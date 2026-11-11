<?php

namespace FriendsOfRedaxo\VideoEditor;

use Closure;
use Override;
use Redaxo\Core\ApiFunction\ApiFunction;
use Redaxo\Core\ApiFunction\AsApiFunction;
use Redaxo\Core\ApiFunction\Result;
use Redaxo\Core\Core;
use Redaxo\Core\Exception\UserMessageException;
use Redaxo\Core\Filesystem\Path;
use Redaxo\Core\Filesystem\Url;
use Redaxo\Core\Http\Request;
use Redaxo\Core\Http\Response;
use Redaxo\Core\MediaManager\MediaManager;
use Redaxo\Core\MediaPool\Media;
use Redaxo\Core\Security\User;
use Redaxo\Core\Translation\I18n;
use Redaxo\Core\Util\Formatter;
use Throwable;

use function array_map;
use function in_array;
use function strtolower;

/**
 * Video-Editor im Medienpool (POST, mit CSRF-Token): ?rex-api-call=video_editor.
 *
 *  - action=info      Analyse, Vorlagen, Aufträge, Fassungen und Poster eines Videos
 *  - action=optimize  Auftrag anlegen (preset, mode version|replace)
 *  - action=poster    Standbild an der Stelle `time` (Sekunden) als Poster
 *  - action=cut       Schneiden: `segments` (JSON [[start, ende], …] in Sekunden, die bleiben), `exact` 0|1, mode
 *  - action=frames    Vorschaubilder für die Zeitleiste
 *  - action=cancel    Auftrag abbrechen (job)
 *  - action=retry     Auftrag erneut versuchen (job)
 *  - action=jobs      nur die Aufträge (zum Nachfragen des Fortschritts)
 */
#[AsApiFunction('video_editor')]
final class Api extends ApiFunction
{
    #[Override]
    public function execute(): Result
    {
        $data = self::handle(Core::requireUser());

        Response::cleanOutputBuffers();
        Response::setHeader('Cache-Control', 'no-store');
        Response::sendJson($data);
        exit;
    }

    /**
     * Anfrage (POST-Parameter) für einen Benutzer auswerten – ohne Ausgabe, damit sie sich testen lässt.
     *
     * @return array<string, mixed>
     */
    public static function handle(User $user): array
    {
        try {
            $media = self::media($user);
            $action = Request::post('action', 'string', 'info');
            // nur ansehen ist ohne Bearbeitungsrecht erlaubt
            if (!in_array($action, ['info', 'jobs'], true) && !VideoEditorAddon::allowed($user)) {
                throw new UserMessageException(I18n::msg('video_editor_no_permission'));
            }
            $data = match ($action) {
                'info' => self::info($media, $user),
                'optimize' => self::optimize($media, $user),
                'cut' => self::cut($media, $user),
                'frames' => [
                    'frames' => Frames::of($media, (float) (Ffmpeg::probe(Path::media($media->fileName))['format']['duration'] ?? 0)),
                    'keyframes' => Cut::keyframes(Path::media($media->fileName)),
                ],
                'poster' => ['poster' => self::posterItem(Poster::capture($media, Request::post('time', 'float', 0.0)))] + self::info($media, $user),
                'cancel' => self::job($media, static fn (int $id) => Jobs::cancel($id)),
                'retry' => self::job($media, static function (int $id): void {
                    Jobs::retry($id);
                    Worker::start();
                }),
                'jobs' => ['jobs' => self::jobs($media), 'versions' => self::versions($media)],
                default => throw new UserMessageException('Unbekannte Aktion.'),
            };
            $data = ['ok' => true] + $data;
        } catch (UserMessageException $e) {
            $data = ['ok' => false, 'error' => $e->getMessage()];
        } catch (Throwable $e) {
            $data = ['ok' => false, 'error' => Core::isDevMode() ? $e->getMessage() : I18n::msg('video_editor_error')];
        }

        return $data;
    }

    /**
     * Rechte: video_editor[] und Medienpool-Recht für die Kategorie des Videos.
     *
     * @throws UserMessageException
     */
    private static function media(User $user): Media
    {
        $media = Media::get(Request::post('file', 'string', ''));
        if (!$media || !VideoEditorAddon::isVideo($media)) {
            throw new UserMessageException(I18n::msg('video_editor_not_found'));
        }
        if (!VideoEditorAddon::mayView($user, $media)) {
            throw new UserMessageException(I18n::msg('video_editor_no_permission'));
        }

        return $media;
    }

    /** @return array<string, mixed> */
    private static function info(Media $media, User $user): array
    {
        $analysis = Ffmpeg::available() ? Analysis::of(Path::media($media->fileName), $media->getExtension()) : null;
        $recommended = array_map(static fn (array $h) => $h['preset'], $analysis->hints ?? []);
        $poster = Links::poster($media->fileName);

        return [
            'available' => Ffmpeg::available(),
            'canEdit' => VideoEditorAddon::allowed($user),
            'canReplace' => VideoEditorAddon::mayReplace($user),
            'version' => Ffmpeg::version(),
            'file' => $media->fileName,
            'url' => $media->getUrl(),
            // für Editor und Vorschau: mit Range-Anfragen, damit der Browser springen kann
            'stream' => StreamApi::url($media),
            'extension' => $media->getExtension(),
            'size' => Formatter::bytes($media->size),
            'analysis' => $analysis?->toArray(),
            'presets' => array_map(static fn (Preset $p) => [
                'id' => $p->value,
                'label' => $p->label(),
                'text' => $p->description(),
                'extension' => $p->extension() ?? $media->getExtension(),
                'replace' => null === $p->extension() || $p->extension() === $media->getExtension(),
                'recommended' => in_array($p->value, $recommended, true),
                'fast' => !$p->reencodes(),
            ], array_values(array_filter(Preset::cases(), static fn (Preset $p) => $p->supports($media->getExtension())))),
            'jobs' => self::jobs($media),
            'versions' => self::versions($media),
            'source' => Links::sourceOf($media->fileName),
            'poster' => null !== $poster && ($p = Media::get($poster)) ? self::posterItem($p) : null,
        ];
    }

    /**
     * @throws UserMessageException
     * @return array<string, mixed>
     */
    private static function optimize(Media $media, User $user): array
    {
        if (!Ffmpeg::available()) {
            throw new UserMessageException(I18n::msg('video_editor_no_ffmpeg'));
        }
        $preset = Preset::tryFrom(Request::post('preset', 'string', ''));
        if (null === $preset || !$preset->supports($media->getExtension())) {
            throw new UserMessageException(I18n::msg('video_editor_preset_unknown'));
        }
        $mode = 'replace' === Request::post('mode', 'string', 'version') ? 'replace' : 'version';
        if ('replace' === $mode && !VideoEditorAddon::mayReplace($user)) {
            throw new UserMessageException(I18n::msg('video_editor_mode_replace_denied'));
        }
        Jobs::add($media->fileName, 'optimize', ['preset' => $preset->value, 'mode' => $mode], $user);
        Worker::start();

        return ['jobs' => self::jobs($media)];
    }

    /**
     * @throws UserMessageException
     * @return array<string, mixed>
     */
    private static function cut(Media $media, User $user): array
    {
        if (!Ffmpeg::available()) {
            throw new UserMessageException(I18n::msg('video_editor_no_ffmpeg'));
        }
        $duration = (float) (Ffmpeg::probe(Path::media($media->fileName))['format']['duration'] ?? 0);
        $segments = Cut::segments(Request::post('segments', 'string', ''), $duration);
        $exact = Request::post('exact', 'bool', false);
        $mode = 'replace' === Request::post('mode', 'string', 'version') ? 'replace' : 'version';
        if ('replace' === $mode && !VideoEditorAddon::mayReplace($user)) {
            throw new UserMessageException(I18n::msg('video_editor_mode_replace_denied'));
        }
        if ('replace' === $mode && Cut::extension($media->getExtension(), $exact) !== strtolower($media->getExtension())) {
            throw new UserMessageException(I18n::msg('video_editor_cut_replace_extension'));
        }
        // Länge wie sie wirklich wird: schnell beginnt jeder Abschnitt am Schlüsselbild davor
        $label = Cut::label($exact ? $segments : Cut::snap($segments, Cut::keyframes(Path::media($media->fileName))));
        Jobs::add($media->fileName, 'cut', ['segments' => $segments, 'exact' => $exact, 'mode' => $mode, 'label' => $label], $user);
        Worker::start();

        return ['jobs' => self::jobs($media)];
    }

    /**
     * @param Closure(int): void $action
     * @throws UserMessageException
     * @return array<string, mixed>
     */
    private static function job(Media $media, Closure $action): array
    {
        $job = Jobs::get(Request::post('job', 'int', 0));
        if (null === $job || $job['filename'] !== $media->fileName) {
            throw new UserMessageException(I18n::msg('video_editor_not_found'));
        }
        $action($job['id']);

        return ['jobs' => self::jobs($media)];
    }

    /** @return list<array<string, mixed>> */
    private static function jobs(Media $media): array
    {
        return array_map(static fn (array $job) => [
            'id' => $job['id'],
            'status' => $job['status'],
            'progress' => $job['progress'],
            'label' => Preset::tryFrom((string) ($job['params']['preset'] ?? ''))?->label() ?? (string) ($job['params']['label'] ?? $job['kind']),
            'mode' => (string) ($job['params']['mode'] ?? 'version'),
            'result' => $job['result'],
            'resultUrl' => $job['result'] ? self::detailUrl((string) $job['result']) : null,
            'message' => $job['message'],
            'date' => $job['createdate'],
            'user' => $job['createuser'],
        ], Jobs::forFile($media->fileName));
    }

    /** @return list<array<string, mixed>> */
    private static function versions(Media $media): array
    {
        return array_map(static function (array $link): array {
            $target = Media::get($link['file']);

            return [
                'file' => $link['file'],
                'label' => $link['preset']?->label() ?? $link['label'],
                'size' => $target ? Formatter::bytes($target->size) : '',
                'url' => self::detailUrl($link['file']),
            ];
        }, Links::of($media->fileName, Links::VERSION));
    }

    /** @return array<string, string> */
    private static function posterItem(Media $poster): array
    {
        return [
            'file' => $poster->fileName,
            'src' => $poster->getUrl() . '?v=' . $poster->updateDate,
            'thumb' => MediaManager::getUrl('rex_media_small', $poster->fileName, $poster->updateDate),
            'url' => self::detailUrl($poster->fileName),
        ];
    }

    private static function detailUrl(string $file): string
    {
        $media = Media::get($file);

        return Url::backendPage('mediapool/media', null !== $media ? ['file_id' => $media->id, 'rex_file_category' => $media->categoryId ?? 0] : []);
    }
}
