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
use Redaxo\Core\Translation\I18n;
use Symfony\Component\HttpFoundation\File\File as SymfonyFile;

use function bin2hex;
use function is_file;
use function max;
use function pathinfo;
use function random_bytes;
use function sprintf;

use const PATHINFO_FILENAME;

/**
 * Standbild an einer Stelle des Videos als Poster (JPEG im Medienpool, mit dem Video verknüpft). Gibt es schon ein
 * Poster, wird dessen Datei ersetzt – Verwendungen bleiben erhalten.
 */
final class Poster
{
    /** @throws UserMessageException */
    public static function capture(Media $video, float $seconds): Media
    {
        $ffmpeg = Ffmpeg::binary();
        if (null === $ffmpeg) {
            throw new UserMessageException(I18n::msg('video_editor_no_ffmpeg'));
        }
        $dir = Path::addonData('video_editor', 'tmp/poster-' . bin2hex(random_bytes(6)));
        Dir::create($dir);
        try {
            $output = $dir . '/' . pathinfo($video->fileName, PATHINFO_FILENAME) . '-poster.jpg';
            // -ss vor -i: schnelles Springen, ffmpeg dekodiert ab dem Keyframe bis zur genauen Stelle
            $result = Ffmpeg::run([
                $ffmpeg, '-hide_banner', '-nostdin', '-y',
                '-ss', sprintf('%.3F', max(0.0, $seconds)),
                '-i', Path::media($video->fileName),
                '-frames:v', '1', '-q:v', '2', $output,
            ], 120);
            if (0 !== $result['code'] || !is_file($output)) {
                throw new UserMessageException(I18n::msg('video_editor_poster_error'));
            }

            $existing = Links::poster($video->fileName);
            if (null !== $existing && 'jpg' === Media::get($existing)?->getExtension()) {
                $target = $dir . '/' . $existing;
                File::move($output, $target);
                $updated = MediaHandler::edit($existing, Language::getStartId(), ['file' => new SymfonyFile($target)]);
                MediaManager::deleteCache($existing);
                Media::clearInstances($existing);

                return $updated;
            }

            $poster = MediaHandler::add([
                'file' => new SymfonyFile($output),
                'category_id' => $video->categoryId,
                'title' => I18n::msg('video_editor_poster_title', $video->title ?: $video->fileName),
            ]);
            Links::add($video->fileName, $poster->fileName, Links::POSTER);

            return $poster;
        } finally {
            Dir::delete($dir);
        }
    }
}
