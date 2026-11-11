<?php

namespace FriendsOfRedaxo\VideoEditor;

use Redaxo\Core\Exception\UserMessageException;
use Redaxo\Core\Filesystem\Dir;
use Redaxo\Core\Filesystem\File;
use Redaxo\Core\Filesystem\Path;
use Redaxo\Core\Language\Language;
use Redaxo\Core\MediaManager\MediaManager;
use Redaxo\Core\MediaPool\Media;
use Redaxo\Core\MediaPool\MediaHandler;
use Symfony\Component\HttpFoundation\File\File as SymfonyFile;
use Throwable;

use function array_column;
use function array_filter;
use function array_map;
use function array_slice;
use function array_sum;
use function dirname;
use function escapeshellarg;
use function exec;
use function explode;
use function fclose;
use function flock;
use function fopen;
use function function_exists;
use function implode;
use function is_file;
use function json_encode;
use function max;
use function min;
use function pathinfo;
use function preg_match;
use function register_shutdown_function;
use function set_time_limit;
use function str_contains;
use function str_replace;
use function strtolower;
use function time;
use function trim;

use const LOCK_EX;
use const LOCK_NB;
use const LOCK_UN;
use const PATHINFO_EXTENSION;
use const PATHINFO_FILENAME;
use const PHP_BINARY;
use const PHP_BINDIR;

/**
 * Arbeitet die Aufträge ab – immer nur einer zur Zeit (Sperrdatei), damit der Server nicht überlastet wird.
 *
 * Gestartet wird er im Hintergrund über die Konsole (`video:work`), notfalls nach der Antwort des Requests; der
 * Cronjob `video_editor_work` holt liegengebliebene Aufträge nach.
 */
final class Worker
{
    public const int TIMEOUT = 3600;

    /**
     * Wartende Aufträge abarbeiten, höchstens $seconds lang.
     *
     * @return int Anzahl der bearbeiteten Aufträge
     */
    public static function run(int $seconds = 3300): int
    {
        Dir::create(Path::addonData('video_editor', 'tmp'));
        $lock = fopen(Path::addonData('video_editor', 'worker.lock'), 'c');
        if (false === $lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return 0;
        }
        $done = 0;
        try {
            Jobs::recover(self::timeout() + 600);
            $until = time() + $seconds;
            while (time() < $until && null !== ($job = Jobs::claim())) {
                self::process($job);
                ++$done;
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $done;
    }

    /** Worker im Hintergrund starten (Konsole); klappt das nicht, nach der Antwort dieses Requests arbeiten. */
    public static function start(): void
    {
        if (self::spawn()) {
            return;
        }
        register_shutdown_function(static function (): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            @set_time_limit(0);
            self::run(600);
        });
    }

    /** @param array<string, mixed> $job */
    private static function process(array $job): void
    {
        $id = (int) $job['id'];
        $tmpDir = Path::addonData('video_editor', 'tmp/job-' . $id);
        try {
            $media = Media::get((string) $job['filename']);
            if (!$media || !$media->fileExists()) {
                Jobs::finish($id, Jobs::FAILED, message: 'Medium nicht gefunden.');

                return;
            }
            $ffmpeg = Ffmpeg::binary();
            if (null === $ffmpeg) {
                Jobs::finish($id, Jobs::FAILED, message: 'ffmpeg ist nicht verfügbar.');

                return;
            }
            Dir::create($tmpDir);
            $plan = self::plan($job, $media, $ffmpeg, $tmpDir);
            if (null === $plan) {
                Jobs::finish($id, Jobs::FAILED, message: 'Unbekannter Auftrag oder ungültige Angaben.');

                return;
            }

            // Fortschritt über alle Schritte, gewichtet nach ihrer Dauer
            $total = max(0.1, array_sum(array_column($plan['steps'], 'duration')));
            $done = 0.0;
            $lastWrite = 0;
            $log = '';
            foreach ($plan['steps'] as $step) {
                $result = Ffmpeg::run(
                    $step['args'],
                    self::timeout(),
                    static function (string $line) use ($id, $total, $done, &$lastWrite): void {
                        if (1 === preg_match('/^out_time_(?:us|ms)=(\d+)/', $line, $m)) {
                            $percent = (int) min(99, ($done + (int) $m[1] / 1_000_000) / $total * 100);
                            if (time() > $lastWrite) {
                                $lastWrite = time();
                                Jobs::progress($id, $percent);
                            }
                        }
                    },
                    static fn (): bool => Jobs::cancelRequested($id),
                );
                $log .= $result['err'];
                if ($result['canceled']) {
                    Jobs::finish($id, Jobs::CANCELED);

                    return;
                }
                if ($result['timeout']) {
                    Jobs::finish($id, Jobs::FAILED, message: 'Zeitüberschreitung nach ' . self::timeout() . ' s.', log: $log);

                    return;
                }
                if (0 !== $result['code']) {
                    Jobs::finish($id, Jobs::FAILED, message: self::lastError($result['err']), log: $log);

                    return;
                }
                $done += $step['duration'];
            }
            if (!is_file($plan['output'])) {
                Jobs::finish($id, Jobs::FAILED, message: 'ffmpeg hat keine Datei erzeugt.', log: $log);

                return;
            }
            $stored = self::store($media, $plan['output'], (string) ($job['params']['mode'] ?? 'version'), $plan['label'], $plan['preset']);
            Jobs::finish($id, Jobs::DONE, result: $stored, log: $log);
        } catch (Throwable $e) {
            Jobs::finish($id, Jobs::FAILED, message: $e->getMessage());
        } finally {
            Dir::delete($tmpDir);
        }
    }

    /**
     * Was ist zu tun? Schritte für ffmpeg, Zieldatei (ihr Name wird zum Namen im Medienpool) und Bezeichnung.
     *
     * @param array<string, mixed> $job
     * @return array{steps: list<array{args: list<string>, duration: float}>, output: string, label: string, preset: ?Preset}|null
     */
    private static function plan(array $job, Media $media, string $ffmpeg, string $tmpDir): ?array
    {
        $input = Path::media($media->fileName);
        $base = pathinfo($media->fileName, PATHINFO_FILENAME);
        $params = $job['params'];
        $probe = Ffmpeg::probe($input);
        $duration = max(0.1, (float) ($probe['format']['duration'] ?? 0));

        if ('cut' === $job['kind']) {
            try {
                $segments = Cut::segments((string) json_encode($params['segments'] ?? null), $duration);
            } catch (UserMessageException) {
                return null;
            }
            $exact = (bool) ($params['exact'] ?? false);
            $audio = false;
            foreach ($probe['streams'] ?? [] as $stream) {
                $audio = $audio || 'audio' === ($stream['codec_type'] ?? null);
            }
            $output = $tmpDir . '/' . $base . '-cut.' . Cut::extension($media->getExtension(), $exact);

            return [
                'steps' => Cut::steps($ffmpeg, $input, $output, $segments, $exact, $audio, $tmpDir),
                'output' => $output,
                // Bezeichnung aus der Anfrage (Sprache des Benutzers), der Worker läuft auf der Konsole
                'label' => (string) ($params['label'] ?? Cut::label($segments)),
                'preset' => null,
            ];
        }

        $preset = Preset::tryFrom((string) ($params['preset'] ?? ''));
        if ('optimize' !== $job['kind'] || null === $preset) {
            return null;
        }
        $output = $tmpDir . '/' . $base . '-' . $preset->value . '.' . ($preset->extension() ?? $media->getExtension());

        return [
            'steps' => [[
                'args' => [$ffmpeg, '-hide_banner', '-nostdin', '-y', '-i', $input, ...$preset->args(), '-progress', 'pipe:1', '-nostats', $output],
                'duration' => $duration,
            ]],
            'output' => $output,
            'label' => $preset->label(),
            'preset' => $preset,
        ];
    }

    /**
     * Ergebnis übernehmen: als neue Fassung (verknüpft) oder anstelle des Originals – ersetzen nur bei gleicher Endung.
     *
     * @throws UserMessageException
     * @return string Dateiname im Medienpool
     */
    private static function store(Media $source, string $output, string $mode, string $label, ?Preset $preset): string
    {
        if ('replace' === $mode && strtolower(pathinfo($output, PATHINFO_EXTENSION)) === strtolower($source->getExtension())) {
            // Datei mit dem Namen des Originals, damit MediaHandler::edit sie annimmt (gleiche Endung)
            $target = dirname($output) . '/' . $source->fileName;
            File::move($output, $target);
            MediaHandler::edit($source->fileName, Language::getStartId(), ['file' => new SymfonyFile($target)]);
            MediaManager::deleteCache($source->fileName);
            Media::clearInstances($source->fileName);

            return $source->fileName;
        }

        $media = MediaHandler::add([
            'file' => new SymfonyFile($output),
            'category_id' => $source->categoryId,
            'title' => trim(($source->title ?: $source->fileName) . ' (' . $label . ')'),
        ]);
        Links::add($source->fileName, $media->fileName, Links::VERSION, $label, $preset);

        return $media->fileName;
    }

    /** Hintergrund-Prozess über die Konsole von REDAXO */
    private static function spawn(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }
        $php = self::phpCli();
        $console = Path::bin('console');
        if (null === $php || !is_file($console)) {
            return false;
        }
        // feste Bestandteile, alle maskiert – keine Eingaben von außen
        $command = 'nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($console) . ' video:work > /dev/null 2>&1 &';
        @exec($command, $out, $code);

        return 0 === $code;
    }

    /** PHP für die Kommandozeile – unter PHP-FPM ist PHP_BINARY der FPM-Prozess */
    public static function phpCli(): ?string
    {
        $configured = Ffmpeg::setting('php_cli', 'PHP_CLI');
        $candidates = '' !== $configured ? [$configured] : [];
        $candidates[] = PHP_BINDIR . '/php';
        $candidates[] = str_replace(['/sbin/php-fpm', '/php-fpm'], ['/bin/php', '/php'], PHP_BINARY);
        if (!str_contains(PHP_BINARY, 'fpm')) {
            $candidates[] = PHP_BINARY;
        }
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && 0 === Ffmpeg::run([$candidate, '-v'], 10)['code']) {
                return $candidate;
            }
        }

        return null;
    }

    public static function timeout(): int
    {
        return max(60, (int) VideoEditorAddon::instance()->getConfig('timeout', self::TIMEOUT));
    }

    /** Aussagekräftige letzte Zeile aus der Fehlerausgabe von ffmpeg */
    private static function lastError(string $err): string
    {
        $lines = array_filter(array_map('trim', explode("\n", $err)));
        $last = array_slice($lines, -3);

        return '' !== implode('', $last) ? implode(' · ', $last) : 'ffmpeg ist mit einem Fehler beendet worden.';
    }
}
