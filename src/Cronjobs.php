<?php

namespace FriendsOfRedaxo\VideoEditor;

use Redaxo\Core\Cronjob\AsCronjob;

/** Holt liegengebliebene Aufträge nach, falls der Hintergrund-Start nicht geklappt hat */
final class Cronjobs
{
    #[AsCronjob('video_editor_work', '* * * * *')]
    public static function work(): string
    {
        if (!Jobs::hasQueued()) {
            return 'Keine wartenden Aufträge.';
        }

        return Worker::run(50) . ' Video-Auftrag/Aufträge bearbeitet.';
    }
}
