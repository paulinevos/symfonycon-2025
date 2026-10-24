<?php

namespace App\Console;

use App\Evaluation\JudgedQuery;
use App\Evaluation\JudgedQuerySet;
use App\Reranker\JevReranker;
use App\Reranker\VoyageReranker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Score the hand-annotated queries, with and without reranking.
 *
 * The sheet already holds the shortlist retrieval returned, in order, so
 * nothing is re-retrieved here: this measures one thing only, whether a
 * reranker moves the human's "y" marks nearer the top.
 *
 * A query with no "y" at all cannot score — the catalogue has no answer for
 * it — so it is reported separately rather than counted as a failure.
 */
#[AsCommand(
    name: 'app:benchmark:text',
    description: 'Rerank the annotated shortlists and report where the right answer lands',
)]
final class TextBenchmarkCommand extends Command
{
    private const DEPTH = 10;

    public function __construct(
        private readonly VoyageReranker $voyage,
        private readonly JevReranker $jev,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('sheet', InputArgument::REQUIRED, 'The annotated judgements CSV')
            ->addOption('instruction', null, InputOption::VALUE_REQUIRED, 'Override the Jev question')
            ->addOption('framing', null, InputOption::VALUE_REQUIRED, 'Extra context for the Jev state');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $set = JudgedQuerySet::fromCsv($input->getArgument('sheet'));
        $queries = $set->scorable();

        $io->writeln(sprintf('%d queries, %d scorable', count($set), count($queries)));

        $rows = [];
        $totals = ['retrieval' => 0.0, 'voyage' => 0.0, 'jev' => 0.0];
        $top1 = ['retrieval' => 0, 'voyage' => 0, 'jev' => 0];

        $instruction = $input->getOption('instruction');
        $framing = $input->getOption('framing');

        foreach ($queries as $query) {
            $orders = [
                'retrieval' => $query->shortlist(),
                'voyage' => $this->reorder($query, $this->voyage),
                'jev' => $this->reorder($query, $this->jev, $instruction, $framing),
            ];

            $ranks = [];
            foreach ($orders as $name => $order) {
                $rank = $query->rankOfFirstMatch($order);
                $ranks[$name] = $rank;
                $totals[$name] += null === $rank ? 0.0 : 1 / $rank;
                $top1[$name] += 1 === $rank ? 1 : 0;
            }

            $rows[] = [
                $query->query,
                $this->show($ranks['retrieval']),
                $this->show($ranks['voyage']),
                $this->show($ranks['jev']),
            ];
        }

        $io->table(['query', 'retrieval', 'rerank-3', 'jev'], $rows);

        $count = max(1, count($queries));
        $io->table(['pipeline', 'top-1 match', 'MRR'], [
            ['retrieval', sprintf('%d/%d', $top1['retrieval'], count($queries)), sprintf('%.3f', $totals['retrieval'] / $count)],
            ['rerank-3', sprintf('%d/%d', $top1['voyage'], count($queries)), sprintf('%.3f', $totals['voyage'] / $count)],
            ['jev', sprintf('%d/%d', $top1['jev'], count($queries)), sprintf('%.3f', $totals['jev'] / $count)],
        ]);

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $findIds
     */
    private function show(?int $rank): string
    {
        return null === $rank ? 'miss' : (string) $rank;
    }

    /**
     * @return list<string> find IDs, best first
     */
    private function reorder(JudgedQuery $query, object $reranker, ?string $instruction = null, ?string $framing = null): array
    {
        $shortlist = $query->shortlist();
        $ranked = [];

        $scores = $reranker instanceof JevReranker
            ? $reranker->score($query->query, $query->summaries(), count($shortlist), $instruction, $framing)
            : $reranker->score($query->query, $query->summaries(), count($shortlist));

        foreach ($scores as $entry) {
            $ranked[] = $shortlist[$entry['index']];
        }

        return $ranked;
    }
}
