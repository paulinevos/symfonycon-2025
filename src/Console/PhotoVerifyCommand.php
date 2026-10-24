<?php

namespace App\Console;

use App\Verification\CandidateJudge;
use App\Verification\CandidateUnderTest;
use App\Verification\JudgeFactory;
use App\Verification\JsonLinesWriter;
use App\Verification\Presentation;
use App\Verification\SeededShuffle;
use App\Verification\Sheet;
use App\Verification\VerificationRun;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Pointwise verification over a frozen photo shortlist.
 *
 * One model call per candidate, in a seeded order, written as JSONL. The
 * scorer — not this command — decides how the two answers combine and how
 * relevance is counted, so those rules can change without another API run.
 */
#[AsCommand(
    name: 'app:benchmark:photo-verify',
    description: 'Assess a frozen photo shortlist one candidate at a time',
)]
final class PhotoVerifyCommand extends Command
{
    public function __construct(
        private readonly JudgeFactory $judges,
        private readonly SeededShuffle $shuffle,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('sheet', InputArgument::REQUIRED, 'The annotated candidate sheet CSV')
            ->addOption('base', null, InputOption::VALUE_REQUIRED, 'Directory the sheet paths are relative to', '')
            ->addOption('judge', null, InputOption::VALUE_REQUIRED, 'Which judge to run', 'openai')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'The judge model; empty uses the judge default', '')
            ->addOption('presentation', null, InputOption::VALUE_REQUIRED, 'visual (photographs) or text (names and summaries)', 'visual')
            ->addOption('seed', null, InputOption::VALUE_REQUIRED, 'Shuffle seed; the same seed gives the same order', '1')
            ->addOption('query', null, InputOption::VALUE_REQUIRED, 'Comma-separated queries; empty means all', '')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum queries to run; 0 means all', '0')
            ->addOption('max-candidates', null, InputOption::VALUE_REQUIRED, 'Maximum candidates per query after shuffling; 0 means all', '0')
            ->addOption('max-rank', null, InputOption::VALUE_REQUIRED, 'Only candidates at or above this retrieval rank; 0 means all', '0')
            ->addOption('emit', null, InputOption::VALUE_REQUIRED, 'JSONL output path; empty means stdout', '')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Write the shuffled rows without calling the judge');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sheetPath = (string) $input->getArgument('sheet');
        $base = '' !== (string) $input->getOption('base') ? (string) $input->getOption('base') : dirname($sheetPath);
        $sheet = Sheet::fromCsv($sheetPath, $base);
        $judgeName = (string) $input->getOption('judge');
        $model = $this->judges->modelFor($judgeName, (string) $input->getOption('model'));
        $judge = $this->judges->pointwise(
            $judgeName,
            $model,
            Presentation::fromOption((string) $input->getOption('presentation')),
        );
        $emit = (string) $input->getOption('emit');
        $stream = '' === $emit ? \STDOUT : fopen($emit, 'w');
        if (false === $stream) {
            throw new \RuntimeException(sprintf('Cannot write to "%s".', $emit));
        }

        $writer = new JsonLinesWriter($stream);
        $writer->write($this->header($sheetPath, $judge, $model, $input));
        $run = new VerificationRun($judge, $this->shuffle, (int) $input->getOption('seed'), (bool) $input->getOption('dry-run'), $writer);

        $errors = 0;
        foreach ($this->queries($sheet, $input) as $query) {
            $errors += $this->runQuery($run, $sheet, $query, (int) $input->getOption('max-candidates'), (int) $input->getOption('max-rank'), $output);
        }

        if ('' !== $emit) {
            fclose($stream);
        }

        return 0 === $errors ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @return list<string>
     */
    private function queries(Sheet $sheet, InputInterface $input): array
    {
        $requested = array_values(array_filter(explode(',', (string) $input->getOption('query'))));
        $queries = [] === $requested ? $sheet->queries() : array_values(array_intersect($sheet->queries(), $requested));
        $limit = (int) $input->getOption('limit');

        return $limit > 0 ? array_slice($queries, 0, $limit) : $queries;
    }

    private function runQuery(VerificationRun $run, Sheet $sheet, string $query, int $maxCandidates, int $maxRank, OutputInterface $output): int
    {
        $candidates = $sheet->forQuery($query);
        if ($maxRank > 0) {
            $candidates = array_values(array_filter(
                $candidates,
                static fn (CandidateUnderTest $candidate): bool => $candidate->retrievalRank <= $maxRank,
            ));
        }

        $candidates = $run->shuffled($candidates);
        $candidates = $maxCandidates > 0 ? array_slice($candidates, 0, $maxCandidates) : $candidates;
        $output->writeln(sprintf('%s: %d candidates', $query, count($candidates)), OutputInterface::VERBOSITY_VERBOSE);

        $errors = 0;
        foreach ($candidates as $position => $candidate) {
            $errors += null === $run->assess($candidate, $position) ? 0 : 1;
        }

        return $errors;
    }

    /**
     * @return array<string, mixed>
     */
    private function header(string $sheetPath, CandidateJudge $judge, string $model, InputInterface $input): array
    {
        return [
            'type' => 'run',
            'judge' => $judge->name(),
            'model' => $model,
            'presentation' => (string) $input->getOption('presentation'),
            'seed' => (int) $input->getOption('seed'),
            'sheet' => $sheetPath,
            'sheetSha256' => hash_file('sha256', $sheetPath),
            'startedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ];
    }
}
