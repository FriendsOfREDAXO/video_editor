<?php

namespace FriendsOfRedaxo\VideoEditor\Console;

use FriendsOfRedaxo\VideoEditor\Worker;
use Redaxo\Core\Console\AbstractCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Wartende Video-Aufträge abarbeiten (startet der Editor im Hintergrund; auch für eigene Cronjobs) */
#[AsCommand(name: 'video:work', description: 'Arbeitet wartende Aufträge des Video-Editors ab')]
final class WorkCommand extends AbstractCommand
{
    public function __invoke(SymfonyStyle $io, #[Option(description: 'Höchstdauer in Sekunden')] int $seconds = 3300): int
    {
        $done = Worker::run($seconds);
        $io->writeln($done . ' Auftrag/Aufträge bearbeitet.');

        return 0;
    }
}
