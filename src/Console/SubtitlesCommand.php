<?php

namespace FriendsOfRedaxo\VideoEditor\Console;

use FriendsOfRedaxo\VideoEditor\Subtitles;
use FriendsOfRedaxo\VideoEditor\Tracks;
use FriendsOfRedaxo\VideoEditor\VideoEditorAddon;
use Redaxo\Core\Console\AbstractCommand;
use Redaxo\Core\Exception\UserMessageException;
use Redaxo\Core\MediaPool\Media;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

use function file_get_contents;
use function is_file;

/** Untertitel aus einer VTT- oder SRT-Datei übernehmen – gesäubert, als WebVTT mit dem Video verknüpft */
#[AsCommand(name: 'video:subtitles', description: 'Übernimmt Untertitel (VTT/SRT) für ein Video; eine vorhandene Spur derselben Sprache und Art wird ersetzt')]
final class SubtitlesCommand extends AbstractCommand
{
    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Dateiname des Videos im Medienpool')] string $file,
        #[Argument(description: 'Pfad zur VTT- oder SRT-Datei')] string $source,
        #[Option(description: 'Sprache, z. B. de')] string $lang = 'de',
        #[Option(description: 'subtitles, captions oder chapters')] string $role = 'subtitles',
        #[Option(description: 'Bezeichnung (sonst die Sprache)')] string $label = '',
    ): int {
        $media = Media::get($file);
        if (null === $media || !VideoEditorAddon::isVideo($media)) {
            $io->error('Kein Video im Medienpool: ' . $file);

            return Command::FAILURE;
        }
        $cues = is_file($source) ? Subtitles::parse((string) file_get_contents($source)) : null;
        if (null === $cues) {
            $io->error('Keine lesbaren Untertitel: ' . $source);

            return Command::FAILURE;
        }
        $existing = null;
        foreach (Tracks::of($media->fileName) as $track) {
            if ($track['lang'] === $lang && $track['role'] === $role) {
                $existing = $track['file'];
            }
        }
        try {
            $name = Tracks::save($media, $existing, $lang, $role, '' !== $label ? $label : null, $cues);
        } catch (UserMessageException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->writeln('Spur: ' . $name);

        return Command::SUCCESS;
    }
}
