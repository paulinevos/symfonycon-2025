<?php

namespace App\Console;

use App\Document\Artefact;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\AI\Store\Document\VectorizerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:demo:search',
    description: 'Run a text query against the store and print the ranked matches',
)]
final class DemoSearchCommand extends Command
{
    public function __construct(
        private readonly DocumentManager $dm,
        private readonly VectorizerInterface $vectorizer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('query', InputArgument::REQUIRED, 'What to search for')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'How many matches to show', '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $query = $input->getArgument('query');
        $limit = (int) $input->getOption('limit');

        $io->section(sprintf('Text-only vector search: "%s"', $query));

        $startedAt = microtime(true);
        $vector = $this->vectorizer->vectorize($query);
        $embeddedAt = microtime(true);

        $rows = [];
        $rank = 0;

        foreach ($this->search($vector->getData(), $limit) as $candidate) {
            $rows[] = [
                ++$rank,
                sprintf('%.4f', $candidate->score),
                $candidate->artefact->findId,
                $this->shorten($candidate->artefact->summary),
            ];
        }

        $io->table(['#', 'score', 'find ID', 'summary'], $rows);

        $io->writeln(sprintf(
            '  embedding %.0f ms · search %.0f ms · %d dimensions',
            ($embeddedAt - $startedAt) * 1000,
            (microtime(true) - $embeddedAt) * 1000,
            $vector->getDimensions(),
        ));

        return Command::SUCCESS;
    }

    /**
     * @param list<float> $queryVector
     *
     * @return iterable<\App\Document\MatchCandidate>
     */
    private function search(array $queryVector, int $limit): iterable
    {
        $builder = $this->dm->getRepository(Artefact::class)
            ->createAggregationBuilder()
            ->hydrate(\App\Document\MatchCandidate::class);

        $builder
            ->vectorSearch()
            ->limit($limit)
            ->numCandidates($limit * 20)
            ->index('default')
            ->path('embeddingVector')
            ->queryVector($queryVector)
            ->project()
            ->field('_id')->expression(0)
            ->field('artefact')->expression('$$ROOT')
            ->field('score')->meta('vectorSearchScore');

        return $builder->getAggregation()->execute();
    }

    private function shorten(string $summary): string
    {
        return mb_strimwidth(str_replace("\n", ' ', $summary), 0, 72, '…');
    }
}
