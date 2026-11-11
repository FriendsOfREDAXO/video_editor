<?php

namespace FriendsOfRedaxo\VideoEditor;

use Override;
use Redaxo\Core\ApiFunction\ApiFunction;
use Redaxo\Core\ApiFunction\AsApiFunction;
use Redaxo\Core\ApiFunction\Result;
use Redaxo\Core\Filesystem\Path;
use Redaxo\Core\Filesystem\Url;
use Redaxo\Core\Http\Request;
use Redaxo\Core\Http\Response;
use Redaxo\Core\MediaPool\Media;

use function is_file;
use function strtolower;

/**
 * Untertitel für die Website (GET, öffentlich): liefert eine verknüpfte Spur als text/vtt aus – Webserver kennen die
 * Endung .vtt oft nicht und schicken text/plain, das <track> ablehnt. Nur Dateien, die als Spur verknüpft sind.
 */
#[AsApiFunction('video_editor_track')]
final class TrackApi extends ApiFunction
{
    protected bool $published = true;
    protected bool $requiresCsrfProtection = false;

    #[Override]
    public function execute(): Result
    {
        $media = Media::get(Request::get('file', 'string', ''));
        Response::cleanOutputBuffers();
        if (null === $media || 'vtt' !== strtolower($media->getExtension()) || !Links::isTrack($media->fileName) || !is_file(Path::media($media->fileName))) {
            Response::setStatus(Response::HTTP_NOT_FOUND);
            Response::sendContent('', 'text/plain');
            exit;
        }
        Response::setHeader('Cache-Control', 'public, max-age=86400');
        Response::setHeader('X-Content-Type-Options', 'nosniff');
        Response::sendFile(Path::media($media->fileName), 'text/vtt; charset=utf-8');
        exit;
    }

    public static function url(Media $track): string
    {
        return Url::frontendController([self::REQ_CALL_PARAM => 'video_editor_track', 'file' => $track->fileName, 'v' => $track->updateDate]);
    }
}
