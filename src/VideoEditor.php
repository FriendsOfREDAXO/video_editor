<?php

namespace FriendsOfRedaxo\VideoEditor;

use Redaxo\Core\Core;
use Redaxo\Core\Filesystem\Url;
use Redaxo\Core\MediaPool\Media;

use function array_search;
use function in_array;
use function usort;

/**
 * Öffentliche Schnittstelle für Templates, Module und andere AddOns (z. B. fluid): was der Video-Editor zu einem
 * Video weiß – Poster und Web-Fassungen.
 *
 *     $poster = VideoEditor::poster('film.mov');          // Media|null
 *     foreach (VideoEditor::sources('film.mov') as $s) {  // beste Quelle zuerst
 *         echo '<source src="' . $s['url'] . '" type="' . $s['type'] . '">';
 *     }
 *
 * Wer prüfen will, ob das AddOn da ist: class_exists(\FriendsOfRedaxo\VideoEditor\VideoEditor::class).
 */
final class VideoEditor
{
    /** Fassungen für die Auslieferung im Web – andere (stumm, Lautstärke …) bleiben eigenständige Medien */
    private const array WEB = [Preset::Web1080, Preset::Web720, Preset::Mobile540];

    /** Zuletzt gewähltes Poster des Videos */
    public static function poster(string $file): ?Media
    {
        $poster = Links::poster($file);

        return null !== $poster ? Media::get($poster) : null;
    }

    /**
     * Quellen für <video>, beste zuerst: WebM-Fassung, MP4-Web-Fassung (1080p vor 720p, mit $small 720p zuerst – etwa
     * für Hintergrundvideos), sonst das Original. Ohne Fassungen nur das Original.
     *
     * @return list<array{file: string, url: string, type: string}>
     */
    public static function sources(string $file, bool $small = false): array
    {
        $original = Media::get($file);
        if (null === $original) {
            return [];
        }
        $webm = null;
        $mp4 = [];
        foreach (Links::of($file, Links::VERSION) as $link) {
            if (Preset::Webm === $link['preset']) {
                $webm ??= $link['file'];
            } elseif (null !== $link['preset'] && in_array($link['preset'], self::WEB, true)) {
                $mp4[] = $link;
            }
        }
        $order = $small ? [Preset::Web720, Preset::Mobile540, Preset::Web1080] : self::WEB;
        usort($mp4, static fn (array $a, array $b) => array_search($a['preset'], $order, true) <=> array_search($b['preset'], $order, true));

        $files = [];
        if (null !== $webm) {
            $files[] = $webm;
        }
        $files[] = $mp4[0]['file'] ?? $file;

        $sources = [];
        foreach ($files as $name) {
            $media = Media::get($name);
            if (null !== $media) {
                $sources[] = ['file' => $media->fileName, 'url' => $media->getUrl(), 'type' => (string) $media->type];
            }
        }

        return $sources;
    }

    /**
     * Adresse zum Bearbeiten mit dem Platzhalter __FILE__ für den Dateinamen – für Skripte, in denen sich die Auswahl
     * ändert (Medienfelder). Null, wenn der angemeldete Benutzer keine Videos bearbeiten darf; die Medienkategorie
     * prüft dann der Medienpool selbst.
     */
    public static function editUrlTemplate(): ?string
    {
        $user = Core::getUser();

        return null !== $user && VideoEditorAddon::allowed($user) ? Url::backendPage('mediapool/media', ['file_name' => '__FILE__']) : null;
    }

    /** Video im Medienpool bearbeiten (Backend) – null, wenn der angemeldete Benutzer es nicht darf */
    public static function editUrl(string $file): ?string
    {
        $user = Core::getUser();
        $media = Media::get($file);
        if (null === $user || null === $media || !VideoEditorAddon::allowed($user) || !VideoEditorAddon::isVideo($media)) {
            return null;
        }
        if (!VideoEditorAddon::mayView($user, $media)) {
            return null;
        }

        return Url::backendPage('mediapool/media', ['file_id' => $media->id, 'rex_file_category' => $media->categoryId ?? 0]);
    }
}
