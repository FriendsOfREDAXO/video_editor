<?php

namespace FriendsOfRedaxo\VideoEditor;

use Override;
use Redaxo\Core\ApiFunction\ApiFunction;
use Redaxo\Core\ApiFunction\AsApiFunction;
use Redaxo\Core\ApiFunction\Result;
use Redaxo\Core\Core;
use Redaxo\Core\Filesystem\Path;
use Redaxo\Core\Filesystem\Url;
use Redaxo\Core\Http\Request;
use Redaxo\Core\Http\Response;
use Redaxo\Core\MediaPool\Media;

/**
 * Video für den Editor ausliefern (GET, nur im Backend): mit Range-Anfragen, damit der Browser springen kann – auch
 * wenn der Webserver /media ohne Range ausliefert. Nur lesend, daher ohne CSRF-Token.
 */
#[AsApiFunction('video_editor_stream')]
final class StreamApi extends ApiFunction
{
    protected bool $requiresCsrfProtection = false;

    #[Override]
    public function execute(): Result
    {
        $user = Core::requireUser();
        $media = Media::get(Request::get('file', 'string', ''));
        Response::cleanOutputBuffers();
        if (null === $media || !VideoEditorAddon::isVideo($media) || !VideoEditorAddon::mayView($user, $media) || !$media->fileExists()) {
            Response::setStatus(Response::HTTP_NOT_FOUND);
            Response::sendContent('', 'text/plain');
            exit;
        }
        Response::setHeader('Cache-Control', 'private, max-age=3600');
        Response::sendFile(Path::media($media->fileName), (string) $media->type);
        exit;
    }

    public static function url(Media $media): string
    {
        return Url::backendController([self::REQ_CALL_PARAM => 'video_editor_stream', 'file' => $media->fileName, 'v' => $media->updateDate]);
    }
}
