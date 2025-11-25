<?php

namespace App\Console;

use App\Service\ArtefactLoader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:load-artefacts',
    description: 'Load artefacts from the Below the Surface dataset',
)]
final class LoadArtefactsCommand extends Command
{
    public function __construct(
        private readonly ArtefactLoader $artefactLoader,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Loading artefacts from Below the Surface dataset');

        try {
            $count = 0;
            foreach ($this->artefactLoader->load() as $artefact) {
                $count++;
                if ($count % 100 === 0) {
                    $io->info("Loaded {$count} artefacts...");
                }
            }

            $io->success("Successfully loaded {$count} artefacts into the database.");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Failed to load artefacts: ' . $e->getMessage());

            return Command::FAILURE;
        }
    }
}

