<?php

namespace FriendsOfRedaxo\VideoEditor;

use Redaxo\Core\Core;
use Redaxo\Core\Filesystem\Url;
use Redaxo\Core\Language\Language;
use Redaxo\Core\MediaPool\Media;

use function array_search;
use function in_array;
use function is_string;
use function Redaxo\Core\View\escape;
use function strtolower;
use function usort;

/**
 * Öffentliche Schnittstelle für Templates, Module und andere AddOns (z. B. fluid): was der Video-Editor zu einem
 * Video weiß – Poster und Web-Fassungen.
 *
 *     echo VideoEditor::html('film.mov');                 // fertiges <video> mit Poster, Fassungen, Untertiteln
 *     echo VideoEditor::html('loop.mp4', ['background' => true]);
 *
 *     $poster = VideoEditor::poster('film.mov');          // Media|null
 *     foreach (VideoEditor::sources('film.mov') as $s) {  // beste Quelle zuerst
 *         echo '<source src="' . $s['url'] . '" type="' . $s['type'] . '">';
 *     }
 *     VideoEditor::tracks('film.mov');                    // Untertitel und Kapitel
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

    /**
     * Untertitel und Kapitel des Videos, Untertitel zuerst.
     *
     * @return list<array{file: string, url: string, lang: string, kind: string, label: string}>
     */
    public static function tracks(string $file): array
    {
        $list = [];
        foreach (Tracks::of($file) as $track) {
            $media = Media::get($track['file']);
            if (null !== $media) {
                $list[] = ['file' => $media->fileName, 'url' => TrackApi::url($media), 'lang' => $track['lang'], 'kind' => $track['role'], 'label' => $track['name']];
            }
        }

        return $list;
    }

    /**
     * Fertiges <video>-Element.
     *
     * Optionen:
     *  - background (bool)  stumm, automatisch, in Schleife, ohne Bedienelemente und Untertitel; kleinere Fassung
     *  - captions (bool|string)  Untertitel gleich einschalten: true = Sprache der Seite, oder ein Kürzel
     *  - poster (string|false)   anderes Poster (Dateiname) oder keines
     *  - preload (string)        metadata (Standard), none, auto
     *  - label (string)          zugänglicher Name (aria-label)
     *  - class (string)          CSS-Klasse
     *
     * @param array{background?: bool, captions?: bool|string, poster?: string|false, preload?: string, label?: string, class?: string} $options
     */
    public static function html(string $file, array $options = []): string
    {
        $video = Media::get($file);
        if (null === $video) {
            return '';
        }
        $background = (bool) ($options['background'] ?? false);
        $posterOption = $options['poster'] ?? null;
        $poster = false === $posterOption ? null : (is_string($posterOption) && '' !== $posterOption ? Media::get($posterOption) : self::poster($file));
        $preload = in_array($options['preload'] ?? '', ['none', 'metadata', 'auto'], true) ? $options['preload'] : 'metadata';

        $attributes = $background
            ? ' muted autoplay loop playsinline disablepictureinpicture aria-hidden="true"'
            : ' controls playsinline preload="' . $preload . '"';
        if (null !== $poster) {
            $attributes .= ' poster="' . escape($poster->getUrl()) . '"';
        }
        if (!$background && isset($options['label']) && '' !== $options['label']) {
            $attributes .= ' aria-label="' . escape($options['label']) . '"';
        }
        if (isset($options['class']) && '' !== $options['class']) {
            $attributes .= ' class="' . escape($options['class']) . '"';
        }

        $html = '<video' . $attributes . '>';
        foreach (self::sources($file, $background) as $source) {
            $html .= '<source src="' . escape($source['url']) . '" type="' . escape($source['type']) . '">';
        }
        if (!$background) {
            $captions = $options['captions'] ?? false;
            $wanted = true === $captions ? Language::getCurrent()->code : (is_string($captions) ? strtolower($captions) : null);
            $on = false;
            foreach (self::tracks($file) as $track) {
                $default = !$on && null !== $wanted && 'chapters' !== $track['kind'] && $track['lang'] === $wanted;
                $on = $on || $default;
                $html .= '<track kind="' . escape($track['kind']) . '" src="' . escape($track['url']) . '" srclang="' . escape($track['lang'])
                    . '" label="' . escape($track['label']) . '"' . ($default ? ' default' : '') . '>';
            }
        }

        return $html . '</video>';
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
