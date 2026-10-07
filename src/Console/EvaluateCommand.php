<?php

namespace App\Console;

use App\Evaluation\EvaluationRun;
use App\Evaluation\GroundTruthSet;
use App\Evaluation\PipelineRunner;
use App\Evaluation\RetrievalPipeline;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;

#[AsCommand(
    name: 'app:evaluate',
    description: 'Ask the same examples several times and show where the right artefact landed',
)]
final class EvaluateCommand extends Command
{
    public function __construct(
        private readonly DocumentManager $dm,
        private readonly PipelineRunner $runner,
        #[AutowireLocator('app.retrieval_pipeline', defaultIndexMethod: 'name')]
        private readonly ServiceLocator $pipelines,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('pipeline', 'p', InputOption::VALUE_REQUIRED, 'Which pipeline to measure', 'baseline')
            ->addOption('sample', 's', InputOption::VALUE_REQUIRED, 'How many examples to ask', '10')
            ->addOption('repeat', 'r', InputOption::VALUE_REQUIRED, 'How many times to ask each example', '5')
            ->addOption('save', null, InputOption::VALUE_REQUIRED, 'Write the summary to this JSON file')
            ->addOption('compare', 'c', InputOption::VALUE_REQUIRED, 'Compare against a previously saved JSON file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = $input->getOption('pipeline');

        if (!$this->pipelines->has($name)) {
            $io->error(sprintf('Unknown pipeline "%s". Available: %s.', $name, implode(', ', array_keys($this->pipelines->getProvidedServices()))));

            return Command::INVALID;
        }

        $repeats = max(1, (int) $input->getOption('repeat'));
        $set = GroundTruthSet::sample($this->dm, (int) $input->getOption('sample'));

        $io->section(sprintf(
            '"%s" — %d examples, each asked %d times',
            $name,
            count($set),
            $repeats,
        ));

        $run = $this->measure($io, $this->pipelines->get($name), $set, $repeats);

        $this->renderExamples($io, $run, $repeats);
        $this->renderSummary($io, $run, $input->getOption('compare'));
        $this->save($input->getOption('save'), $name, $run);

        $io->comment('Ground truth is the artefact a held-out photo belongs to, which is a proxy for human-judged similarity.');

        return Command::SUCCESS;
    }

    private function measure(SymfonyStyle $io, RetrievalPipeline $pipeline, GroundTruthSet $set, int $repeats): EvaluationRun
    {
        $progress = new ProgressBar($io, count($set));
        $progress->start();

        $run = $this->runner->run($pipeline, $set, $repeats, static fn () => $progress->advance());

        $progress->finish();
        $io->newLine(2);

        return $run;
    }

    private function renderExamples(SymfonyStyle $io, EvaluationRun $run, int $repeats): void
    {
        $rows = [];

        foreach ($run->outcomes as $index => $outcome) {
            $rows[] = [
                $index + 1,
                $outcome->findId,
                mb_strimwidth(str_replace("\n", ' ', $outcome->summary), 0, 34, '…'),
                implode(' ', $outcome->formattedRanks()),
                sprintf('%d/%d', $outcome->hitsWithin(1), $repeats),
            ];
        }

        $io->table(['#', 'artefact', 'summary', 'rank each run', 'first'], $rows);
    }

    private function renderSummary(SymfonyStyle $io, EvaluationRun $run, ?string $comparePath): void
    {
        $metrics = $run->metrics();
        $baseline = $this->readBaseline($comparePath);

        $headers = null === $baseline ? ['metric', 'value'] : ['metric', 'before', 'after', 'change'];
        $rows = [];

        foreach ($metrics->toArray() as $metric => $value) {
            if ('questions' === $metric) {
                continue;
            }

            if (null === $baseline) {
                $rows[] = [$metric, $this->format($metric, $value)];

                continue;
            }

            $was = $baseline[$metric] ?? null;
            $rows[] = [
                $metric,
                null === $was ? '—' : $this->format($metric, $was),
                $this->format($metric, $value),
                null === $was ? '—' : $this->delta($metric, $was, $value),
            ];
        }

        $io->table($headers, $rows);
    }

    private function format(string $metric, float|int $value): string
    {
        return str_ends_with($metric, '_ms')
            ? sprintf('%.0f ms', $value)
            : sprintf('%.3f', $value);
    }

    private function delta(string $metric, float|int $before, float|int $after): string
    {
        $change = $after - $before;
        $sign = $change >= 0 ? '+' : '';

        return str_ends_with($metric, '_ms')
            ? sprintf('%s%.0f ms', $sign, $change)
            : sprintf('%s%.3f', $sign, $change);
    }

    /**
     * @return array<string, float|int>|null
     */
    private function readBaseline(?string $path): ?array
    {
        if (null === $path) {
            return null;
        }

        if (!is_file($path)) {
            throw new \RuntimeException("No saved result at {$path}; run with --save first.");
        }

        return json_decode(file_get_contents($path), true)['metrics'];
    }

    private function save(?string $path, string $name, EvaluationRun $run): void
    {
        if (null === $path) {
            return;
        }

        file_put_contents($path, json_encode([
            'pipeline' => $name,
            'examples' => $run->examples(),
            'repeats' => $run->repeats(),
            'measuredAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'metrics' => $run->metrics()->toArray(),
        ], \JSON_PRETTY_PRINT) . "\n");
    }
}
