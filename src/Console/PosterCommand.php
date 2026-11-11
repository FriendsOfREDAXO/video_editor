<?php

namespace FriendsOfRedaxo\VideoEditor\Console;

use FriendsOfRedaxo\VideoEditor\Poster;
use FriendsOfRedaxo\VideoEditor\VideoEditorAddon;
use Redaxo\Core\Console\AbstractCommand;
use Redaxo\Core\Exception\UserMessageException;
use Redaxo\Core\MediaPool\Media;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Standbild als Poster per Konsole */
#[AsCommand(name: 'video:poster', description: 'Setzt ein Standbild des Videos als Poster (Zeit in Sekunden)')]
final class PosterCommand extends AbstractCommand
{
    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Dateiname im Medienpool')] string $file,
        #[Argument(description: 'Stelle in Sekunden, z. B. 4.6')] string $time,
    ): int {
        $media = Media::get($file);
        if (null === $media || !VideoEditorAddon::isVideo($media)) {
            $io->error('Kein Video im Medienpool: ' . $file);

            return Command::FAILURE;
        }
        try {
            $poster = Poster::capture($media, (float) $time);
        } catch (UserMessageException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->writeln('Poster: ' . $poster->fileName);

        return Command::SUCCESS;
    }
}
