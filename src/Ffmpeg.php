<?php

namespace FriendsOfRedaxo\VideoEditor;

use Closure;
use Redaxo\Core\Filesystem\File;
use Redaxo\Core\Filesystem\Path;

use function array_key_exists;
use function array_unique;
use function array_values;
use function explode;
use function fclose;
use function getenv;
use function is_array;
use function is_executable;
use function is_file;
use function is_resource;
use function is_string;
use function json_decode;
use function microtime;
use function preg_match;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function rtrim;
use function stream_get_contents;
use function stream_select;
use function stream_set_blocking;
use function strpos;
use function strtoupper;
use function substr;
use function time;
use function usleep;

use const DIRECTORY_SEPARATOR;
use const PATH_SEPARATOR;
use const SIGKILL;
use const SIGTERM;

/**
 * ffmpeg und ffprobe aufrufen – immer mit einer Liste von Argumenten über proc_open, nie über eine Shell.
 *
 * Die Programme werden gesucht: eingestellter Pfad (Einstellungen), PATH, übliche Orte. Das Ergebnis wird einen Tag
 * zwischengespeichert.
 */
final class Ffmpeg
{
    private const array LOCATIONS = ['/usr/bin', '/usr/local/bin', '/opt/homebrew/bin', '/opt/local/bin'];

    /** @var array<string, ?string> */
    private static array $binaries = [];

    /** Einstellung: Konfiguration des AddOns, sonst Umgebungsvariable (z. B. FFMPEG_BIN in .env – wie bei fluid). */
    public static function setting(string $config, string $env): string
    {
        $value = trim((string) VideoEditorAddon::instance()->getConfig($config, ''));
        if ('' === $value) {
            $fromEnv = getenv($env);
            $value = trim(is_string($fromEnv) ? $fromEnv : (string) ($_SERVER[$env] ?? ''));
        }

        return $value;
    }

    /** Pfad zu ffmpeg bzw. ffprobe – null, wenn nicht vorhanden */
    public static function binary(string $tool = 'ffmpeg'): ?string
    {
        if (array_key_exists($tool, self::$binaries)) {
            return self::$binaries[$tool];
        }
        $cacheFile = Path::addonCache('video_editor', 'binaries.json');
        $cache = File::getCache($cacheFile, null);
        $configured = self::setting($tool . '_path', strtoupper($tool) . '_BIN');
        if (is_array($cache) && ($cache['checked'] ?? 0) > time() - 86_400 && ($cache['configured'][$tool] ?? null) === $configured
            && array_key_exists($tool, $cache['found'] ?? [])) {
            return self::$binaries[$tool] = $cache['found'][$tool];
        }

        $found = null;
        foreach (self::candidates($tool, $configured) as $candidate) {
            if (0 === self::run([$candidate, '-version'], 10)['code']) {
                $found = $candidate;
                break;
            }
        }
        self::$binaries[$tool] = $found;

        $cache = is_array($cache) && ($cache['checked'] ?? 0) > time() - 86_400 ? $cache : ['found' => [], 'configured' => []];
        $cache['checked'] = time();
        $cache['found'][$tool] = $found;
        $cache['configured'][$tool] = $configured;
        File::putCache($cacheFile, $cache);

        return $found;
    }

    public static function available(): bool
    {
        return null !== self::binary('ffmpeg') && null !== self::binary('ffprobe');
    }

    /** Versionsnummer von ffmpeg, z. B. „4.4.2“ */
    public static function version(): ?string
    {
        $bin = self::binary();
        if (null === $bin) {
            return null;
        }
        $out = self::run([$bin, '-version'], 10)['out'];

        return 1 === preg_match('/version\s+n?([0-9][^\s]*)/', $out, $m) ? $m[1] : null;
    }

    /** Programme nach dem Zwischenspeicher neu suchen (z. B. nach dem Ändern der Einstellungen) */
    public static function forget(): void
    {
        self::$binaries = [];
        File::delete(Path::addonCache('video_editor', 'binaries.json'));
    }

    /**
     * Daten von ffprobe (format und streams) – null, wenn die Datei nicht lesbar ist.
     *
     * @return array<string, mixed>|null
     */
    public static function probe(string $file): ?array
    {
        $bin = self::binary('ffprobe');
        if (null === $bin || !is_file($file)) {
            return null;
        }
        $result = self::run([$bin, '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', $file], 60);
        $data = 0 === $result['code'] ? json_decode($result['out'], true) : null;

        return is_array($data) ? $data : null;
    }

    /**
     * Programm ausführen und warten.
     *
     * @param list<string> $args Programm und Argumente (keine Shell)
     * @param Closure(string): void|null $onLine wird für jede Zeile der Standardausgabe aufgerufen (z. B. -progress pipe:1)
     * @param Closure(): bool|null $cancel liefert true, wenn abgebrochen werden soll (wird etwa jede Sekunde gefragt)
     * @return array{code: int, out: string, err: string, timeout: bool, canceled: bool}
     */
    public static function run(array $args, int $timeout, ?Closure $onLine = null, ?Closure $cancel = null): array
    {
        $result = ['code' => -1, 'out' => '', 'err' => '', 'timeout' => false, 'canceled' => false];
        $process = @proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return $result;
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $start = microtime(true);
        $lastCancelCheck = 0.0;
        $buffer = '';
        $code = null;
        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = $except = null;
            if (false !== @stream_select($read, $write, $except, 0, 200_000)) {
                foreach ($read as $stream) {
                    $chunk = (string) stream_get_contents($stream);
                    if ($stream === $pipes[1]) {
                        $result['out'] .= $chunk;
                        if (null !== $onLine) {
                            $buffer .= $chunk;
                            while (false !== ($pos = strpos($buffer, "\n"))) {
                                $onLine(rtrim(substr($buffer, 0, $pos)));
                                $buffer = substr($buffer, $pos + 1);
                            }
                        }
                    } else {
                        // nur das Ende behalten – ffmpeg schreibt viel auf stderr
                        $result['err'] = substr($result['err'] . $chunk, -20_000);
                    }
                }
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                $code = $status['exitcode'];
                break;
            }
            $now = microtime(true);
            if ($now - $start > $timeout) {
                $result['timeout'] = true;
            } elseif (null !== $cancel && $now - $lastCancelCheck >= 1.0) {
                $lastCancelCheck = $now;
                $result['canceled'] = $cancel();
            }
            if ($result['timeout'] || $result['canceled']) {
                proc_terminate($process, SIGTERM);
                usleep(500_000);
                if (proc_get_status($process)['running']) {
                    proc_terminate($process, SIGKILL);
                }
                break;
            }
        }
        $result['out'] .= (string) stream_get_contents($pipes[1]);
        $result['err'] = substr($result['err'] . (string) stream_get_contents($pipes[2]), -20_000);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closed = proc_close($process);
        $result['code'] = $code ?? $closed;
        if ($result['timeout'] || $result['canceled']) {
            $result['code'] = -1;
        }

        return $result;
    }

    /** @return list<string> */
    private static function candidates(string $tool, string $configured): array
    {
        $list = [];
        if ('' !== $configured) {
            $list[] = $configured;
        }
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if ('' !== $dir) {
                $list[] = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $tool;
            }
        }
        foreach (self::LOCATIONS as $dir) {
            $list[] = $dir . DIRECTORY_SEPARATOR . $tool;
        }

        // nicht vorab mit is_executable() filtern: unter open_basedir liefert das oft false – der Aufruf entscheidet
        return array_values(array_unique($list));
    }
}
