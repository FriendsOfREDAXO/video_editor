<?php

namespace FriendsOfRedaxo\VideoEditor;

use Redaxo\Core\Exception\UserMessageException;
use Redaxo\Core\Filesystem\Dir;
use Redaxo\Core\Filesystem\File;
use Redaxo\Core\Filesystem\Path;
use Redaxo\Core\Language\Language;
use Redaxo\Core\MediaPool\Media;
use Redaxo\Core\MediaPool\MediaHandler;
use Redaxo\Core\Translation\I18n;
use Symfony\Component\HttpFoundation\File\File as SymfonyFile;
use Throwable;

use function bin2hex;
use function count;
use function in_array;
use function is_file;
use function pathinfo;
use function preg_match;
use function random_bytes;
use function strtolower;
use function strtoupper;
use function substr;
use function trim;
use function usort;

use const PATHINFO_FILENAME;

/**
 * Untertitel und Kapitel eines Videos: WebVTT-Dateien im Medienpool, mit dem Video verknüpft (Sprache, Art,
 * Bezeichnung). Gespeichert wird immer gesäubertes WebVTT.
 *
 * @phpstan-import-type Cue from Subtitles
 */
final class Tracks
{
    /**
     * Spuren eines Videos.
     *
     * @return list<array{file: string, lang: string, role: string, label: ?string, name: string, cues: int}>
     */
    public static function of(string $video): array
    {
        $list = [];
        foreach (Links::of($video, Links::TRACK) as $link) {
            $list[] = [
                'file' => $link['file'],
                'lang' => $link['lang'] ?? '',
                'role' => $link['role'] ?? 'subtitles',
                'label' => $link['label'],
                'name' => self::name($link['lang'] ?? '', $link['role'] ?? 'subtitles', $link['label']),
                'cues' => count(self::read($link['file'])),
            ];
        }

        // Reihenfolge: Untertitel vor Kapiteln, dann nach Sprache
        usort($list, static fn (array $a, array $b) => ['chapters' === $a['role'], $a['lang']] <=> ['chapters' === $b['role'], $b['lang']]);

        return $list;
    }

    /** @return list<Cue> */
    public static function read(string $track): array
    {
        $media = Media::get($track);
        if (null === $media || !in_array(strtolower($media->getExtension()), ['vtt', 'srt'], true) || !is_file($path = Path::media($media->fileName))) {
            return [];
        }

        return Subtitles::parse((string) File::get($path)) ?? [];
    }

    /**
     * Spur speichern – neu angelegt (Dateiname aus Video, Sprache, Art) oder die Datei der vorhandenen ersetzt.
     *
     * @param list<Cue> $cues
     * @throws UserMessageException
     * @return string Dateiname der Spur
     */
    public static function save(Media $video, ?string $track, string $lang, string $role, ?string $label, array $cues): string
    {
        $lang = strtolower(trim($lang));
        if (1 !== preg_match('~^[a-z]{2,3}(-[a-z0-9]{2,8})?$~', $lang)) {
            throw new UserMessageException(I18n::msg('video_editor_track_lang_invalid'));
        }
        if (!in_array($role, Subtitles::ROLES, true)) {
            $role = 'subtitles';
        }
        $label = null !== $label && '' !== trim($label) ? trim($label) : null;
        $vtt = Subtitles::toVtt($cues);

        $dir = Path::addonData('video_editor', 'tmp/track-' . bin2hex(random_bytes(6)));
        Dir::create($dir);
        try {
            if (null !== $track && '' !== $track) {
                $existing = Media::get($track);
                if (null === $existing || !self::belongsTo($video->fileName, $track)) {
                    throw new UserMessageException(I18n::msg('video_editor_not_found'));
                }
                File::put($dir . '/' . $existing->fileName, $vtt);
                MediaHandler::edit($existing->fileName, Language::getStartId(), ['file' => new SymfonyFile($dir . '/' . $existing->fileName)]);
                Media::clearInstances($existing->fileName);
                Links::updateTrack($video->fileName, $existing->fileName, $lang, $role, $label);

                return $existing->fileName;
            }

            $suffix = match ($role) {
                'captions' => '-cc',
                'chapters' => '-chapters',
                default => '',
            };
            $file = $dir . '/' . pathinfo($video->fileName, PATHINFO_FILENAME) . '-' . $lang . $suffix . '.vtt';
            File::put($file, $vtt);
            $media = MediaHandler::add([
                'file' => new SymfonyFile($file),
                'category_id' => $video->categoryId,
                'title' => trim(($video->title ?: $video->fileName) . ' (' . self::name($lang, $role, $label) . ')'),
            ]);
            Links::add($video->fileName, $media->fileName, Links::TRACK, $label, null, $lang, $role);

            return $media->fileName;
        } finally {
            Dir::delete($dir);
        }
    }

    /** Spur vom Video lösen und die Datei löschen, sofern sie nirgends sonst verwendet wird */
    public static function remove(Media $video, string $track): void
    {
        if (!self::belongsTo($video->fileName, $track)) {
            return;
        }
        Links::remove($video->fileName, $track, Links::TRACK);
        if (Links::isLinked($track)) {
            // gehört auch zu einer anderen Fassung
            return;
        }
        try {
            MediaHandler::delete($track);
        } catch (Throwable) {
            // noch in Verwendung (z. B. in einem Block gewählt) – dann bleibt die Datei im Medienpool
        }
    }

    /**
     * Nach dem Schneiden: Spuren mitnehmen – bei neuer Fassung als eigene Dateien, beim Ersetzen die vorhandenen
     * Dateien anpassen.
     *
     * @param list<array{float, float}> $segments erhaltene Abschnitte (bei „schnell“ ab Schlüsselbild)
     * @throws UserMessageException
     */
    public static function afterCut(Media $source, string $result, array $segments): void
    {
        $target = Media::get($result);
        if (null === $target) {
            return;
        }
        foreach (Links::of($source->fileName, Links::TRACK) as $link) {
            $cues = Subtitles::remap(self::read($link['file']), $segments);
            $replace = $result === $source->fileName;
            self::save($target, $replace ? $link['file'] : null, $link['lang'] ?? 'de', $link['role'] ?? 'subtitles', $link['label'], $cues);
        }
    }

    /** Bezeichnung für die Auswahl im Player, z. B. „Deutsch“, „English (CC)“, „Deutsch – Kapitel“ */
    public static function name(string $lang, string $role, ?string $label = null): string
    {
        if (null !== $label && '' !== trim($label)) {
            return trim($label);
        }
        $names = ['de' => 'Deutsch', 'en' => 'English', 'fr' => 'Français', 'es' => 'Español', 'it' => 'Italiano', 'nl' => 'Nederlands',
            'pl' => 'Polski', 'tr' => 'Türkçe', 'ar' => 'العربية', 'ru' => 'Русский', 'uk' => 'Українська', 'pt' => 'Português',
            'el' => 'Ελληνικά', 'sl' => 'Slovenščina', 'hr' => 'Hrvatski', 'cs' => 'Čeština', 'da' => 'Dansk', 'sv' => 'Svenska'];
        foreach (Language::getAll() as $language) {
            $names[$language->code] ??= $language->name;
        }
        $name = $names[substr($lang, 0, 2)] ?? strtoupper($lang);

        return match ($role) {
            'captions' => $name . ' (CC)',
            'chapters' => $name . ' – ' . I18n::msg('video_editor_track_role_chapters'),
            default => $name,
        };
    }

    private static function belongsTo(string $video, string $track): bool
    {
        foreach (Links::of($video, Links::TRACK) as $link) {
            if ($link['file'] === $track) {
                return true;
            }
        }

        return false;
    }
}
