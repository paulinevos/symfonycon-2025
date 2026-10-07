<?php

namespace App\Console;

use App\Evaluation\GroundTruthSet;
use App\Evaluation\ImageCache;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Downloads the held-out photographs ahead of time.
 *
 * Run this before presenting: it moves every network fetch off the stage, so
 * the benchmark itself only waits on the embedding API.
 */
#[AsCommand(
    name: 'app:cache-images',
    description: 'Download the ground truth photographs so a later run never waits on the network',
)]
final class CacheImagesCommand extends Command
{
    public function __construct(
        private readonly DocumentManager $dm,
        private readonly ImageCache $images,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('sample', 's', InputOption::VALUE_REQUIRED, 'How many examples to warm', '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $set = GroundTruthSet::sample($this->dm, (int) $input->getOption('sample'));

        $fetched = 0;
        $already = 0;

        foreach ($set as $case) {
            if ($this->images->isCached($case)) {
                ++$already;

                continue;
            }

            $this->images->imageFor($case);
            ++$fetched;
        }

        $io->success(sprintf('%d downloaded, %d already cached.', $fetched, $already));

        return Command::SUCCESS;
    }
}
