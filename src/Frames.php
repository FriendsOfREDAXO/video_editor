<?php

namespace FriendsOfRedaxo\VideoEditor;

use Redaxo\Core\Filesystem\Dir;
use Redaxo\Core\Filesystem\Path;
use Redaxo\Core\Filesystem\Url;
use Redaxo\Core\MediaPool\Media;

use function filemtime;
use function glob;
use function is_file;
use function md5;
use function sprintf;
use function time;

use const GLOB_ONLYDIR;

/**
 * Vorschaubilder für die Zeitleiste des Schnitt-Editors: gleichmäßig verteilte Standbilder, einmal erzeugt und
 * zwischengespeichert (öffentlich unter assets/addons/video_editor/frames, je Datei und Änderungsdatum).
 */
final class Frames
{
    public const int COUNT = 16;

    /** @return list<string> URLs der Bilder, leer ohne ffmpeg */
    public static function of(Media $video, float $duration): array
    {
        $ffmpeg = Ffmpeg::binary();
        if (null === $ffmpeg || $duration <= 0) {
            return [];
        }
        $key = md5($video->fileName . '|' . $video->updateDate . '|' . $video->size);
        $dir = Path::addonAssets('video_editor', 'frames/' . $key);
        $files = [];
        for ($i = 0; $i < self::COUNT; ++$i) {
            $files[] = sprintf('%02d.jpg', $i);
        }

        if (!is_file($dir . '/' . $files[self::COUNT - 1])) {
            self::prune();
            Dir::create($dir);
            // ein Aufruf: je Bild ein Eingang mit schnellem Sprung (-ss vor -i) und eine Ausgabe
            $args = [$ffmpeg, '-hide_banner', '-nostdin', '-y'];
            for ($i = 0; $i < self::COUNT; ++$i) {
                $at = ($i + 0.5) * $duration / self::COUNT;
                $args = [...$args, '-ss', sprintf('%.3F', $at), '-i', Path::media($video->fileName)];
            }
            for ($i = 0; $i < self::COUNT; ++$i) {
                $args = [...$args, '-map', $i . ':v:0', '-frames:v', '1', '-vf', 'scale=-2:72', '-q:v', '5', $dir . '/' . $files[$i]];
            }
            $result = Ffmpeg::run($args, 60);
            if (0 !== $result['code']) {
                return [];
            }
        }

        $urls = [];
        foreach ($files as $file) {
            $urls[] = Url::addonAssets('video_editor', 'frames/' . $key . '/' . $file);
        }

        return $urls;
    }

    /** Bilder, die eine Woche nicht neu erzeugt wurden, entfernen (ersetzte oder gelöschte Videos) */
    private static function prune(): void
    {
        foreach (glob(Path::addonAssets('video_editor', 'frames/*'), GLOB_ONLYDIR) ?: [] as $dir) {
            if ((int) filemtime($dir) < time() - 7 * 86_400) {
                Dir::delete($dir);
            }
        }
    }
}
