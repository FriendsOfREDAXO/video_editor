<?php

namespace FriendsOfRedaxo\VideoEditor\Console;

use FriendsOfRedaxo\VideoEditor\Jobs;
use FriendsOfRedaxo\VideoEditor\Preset;
use FriendsOfRedaxo\VideoEditor\VideoEditorAddon;
use FriendsOfRedaxo\VideoEditor\Worker;
use Redaxo\Core\Console\AbstractCommand;
use Redaxo\Core\MediaPool\Media;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_filter;
use function array_map;
use function count;
use function explode;
use function implode;
use function trim;

/** Web-Fassungen per Konsole anlegen – etwa für viele Videos auf einmal oder beim Einrichten einer Website */
#[AsCommand(name: 'video:optimize', description: 'Legt Aufträge für Fassungen eines Videos an (z. B. web480,web720,web1080,webm)')]
final class OptimizeCommand extends AbstractCommand
{
    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Dateiname im Medienpool')] string $file,
        #[Argument(description: 'Vorlagen, mit Komma getrennt')] string $presets,
        #[Option(description: 'Aufträge gleich hier abarbeiten statt im Hintergrund')] bool $run = false,
    ): int {
        $media = Media::get($file);
        if (null === $media || !VideoEditorAddon::isVideo($media)) {
            $io->error('Kein Video im Medienpool: ' . $file);

            return Command::FAILURE;
        }
        $list = [];
        foreach (array_filter(array_map(trim(...), explode(',', $presets))) as $id) {
            $preset = Preset::tryFrom($id);
            if (null === $preset || !$preset->supports($media->getExtension())) {
                $io->error('Unbekannte oder unpassende Vorlage: ' . $id . ' (' . implode(', ', array_map(static fn (Preset $p) => $p->value, Preset::cases())) . ')');

                return Command::FAILURE;
            }
            $list[] = $preset;
        }
        foreach ($list as $preset) {
            Jobs::add($media->fileName, 'optimize', ['preset' => $preset->value, 'mode' => 'version'], null);
        }
        $io->writeln(count($list) . ' Auftrag/Aufträge angelegt.');
        if ($run) {
            $io->writeln(Worker::run(3300) . ' Auftrag/Aufträge bearbeitet.');
        } else {
            Worker::start();
        }

        return Command::SUCCESS;
    }
}
