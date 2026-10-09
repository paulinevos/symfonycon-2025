<?php

namespace App\Console;

use App\Query\ShortlistSearch;
use App\Reranker\JevReranker;
use App\Reranker\Shortlist;
use App\Reranker\VoyageReranker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:demo:rerank',
    description: 'Search by text, then show the top matches before and after each reranker',
)]
final class RerankDemoCommand extends Command
{
    private const SHORTLIST = 20;

    private const SHOWN = 5;

    public function __construct(
        private readonly ShortlistSearch $search,
        private readonly VoyageReranker $voyage,
        private readonly JevReranker $jev,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('query', InputArgument::REQUIRED, 'What the visitor is looking for');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $query = $input->getArgument('query');

        $retrievalStartedAt = microtime(true);
        $retrieved = $this->search->for($query, self::SHORTLIST);

        if ($retrieved->isEmpty()) {
            $io->error('Retrieval found nothing, so there is nothing to rerank.');

            return Command::FAILURE;
        }

        $voyageStartedAt = microtime(true);
        $byVoyage = $retrieved->reorderedBy($this->voyage->score($query, $retrieved->summaries(), self::SHOWN));
        $jevStartedAt = microtime(true);
        $byJev = $retrieved->reorderedBy($this->jev->score($query, $retrieved->summaries(), self::SHOWN));
        $finishedAt = microtime(true);

        $io->table(
            ['#', 'retrieval', $this->voyage->model(), $this->jev->model()],
            $this->rows($retrieved, $byVoyage, $byJev),
        );
        $io->writeln(sprintf(
            '  retrieval %.0f ms · %s %.0f ms · %s %.0f ms',
            ($voyageStartedAt - $retrievalStartedAt) * 1000,
            $this->voyage->model(),
            ($jevStartedAt - $voyageStartedAt) * 1000,
            $this->jev->model(),
            ($finishedAt - $jevStartedAt) * 1000,
        ));

        return Command::SUCCESS;
    }

    /**
     * @return list<list<int|string>>
     */
    private function rows(Shortlist ...$columns): array
    {
        $rows = [];

        for ($position = 0; $position < self::SHOWN; ++$position) {
            $rows[] = [
                $position + 1,
                ...array_map(static fn (Shortlist $column): string => $column->labelAt($position), $columns),
            ];
        }

        return $rows;
    }
}
