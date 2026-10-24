<?php

namespace App\Verification;

/**
 * One batch judge, one seed, one output stream.
 *
 * A batch judge returns every candidate at once, so a failure is the whole
 * query rather than one candidate. It is still written per candidate, in the
 * same shape as the pointwise run, so the scorer reads both without knowing
 * which shape produced them.
 */
final readonly class BatchVerificationRun
{
    public function __construct(
        private BatchCandidateJudge $judge,
        private SeededShuffle $shuffle,
        private int $seed,
        private bool $dryRun,
        private JsonLinesWriter $writer,
    ) {
    }

    /**
     * @template T
     *
     * @param list<T> $candidates
     *
     * @return list<T>
     */
    public function shuffled(array $candidates): array
    {
        return $this->shuffle->of($candidates, $this->seed);
    }

    /**
     * @param list<CandidateUnderTest> $candidates
     *
     * @return int number of candidates that failed
     */
    public function run(array $candidates): int
    {
        if ($this->dryRun) {
            $this->writeDryRun($candidates);

            return 0;
        }

        try {
            $assessments = $this->judge->assessAll($this->withPhotographs($candidates));
        } catch (\Throwable $error) {
            return $this->writeFailure($candidates, $error);
        }

        return $this->writeOutcome($candidates, $assessments);
    }

    /**
     * @param list<CandidateUnderTest> $candidates
     *
     * @return list<CandidateUnderTest>
     */
    private function withPhotographs(array $candidates): array
    {
        return array_values(array_filter(
            $candidates,
            static fn (CandidateUnderTest $candidate): bool => $candidate->hasPhotograph(),
        ));
    }

    /**
     * @param list<CandidateUnderTest> $candidates
     */
    private function writeDryRun(array $candidates): void
    {
        foreach ($candidates as $position => $candidate) {
            $this->writer->write($this->row($candidate, $position, [
                'sameObjectScore' => null,
                'sameFunctionScore' => null,
                'rationale' => 'dry run',
            ]));
        }
    }

    /**
     * @param list<CandidateUnderTest> $candidates
     */
    private function writeFailure(array $candidates, \Throwable $error): int
    {
        $errors = 0;
        foreach ($candidates as $position => $candidate) {
            if (!$candidate->hasPhotograph()) {
                $this->writer->write($this->row($candidate, $position, $this->skipReason($candidate)));
                continue;
            }

            $this->writer->write($this->row($candidate, $position, ['error' => $error->getMessage()]));
            $errors++;
        }

        return $errors;
    }

    /**
     * @param list<CandidateUnderTest>           $candidates
     * @param array<string, CandidateAssessment> $assessments
     */
    private function writeOutcome(array $candidates, array $assessments): int
    {
        $errors = 0;
        foreach ($candidates as $position => $candidate) {
            if (!$candidate->hasPhotograph()) {
                $this->writer->write($this->row($candidate, $position, $this->skipReason($candidate)));
                continue;
            }

            $assessment = $assessments[$candidate->findId] ?? null;
            if (null === $assessment) {
                $this->writer->write($this->row($candidate, $position, ['error' => 'The judge returned no assessment.']));
                $errors++;
                continue;
            }

            $this->writer->write($this->row($candidate, $position, [
                'sameObjectScore' => $assessment->sameObjectScore,
                'sameFunctionScore' => $assessment->sameFunctionScore,
                'rationale' => $assessment->rationale,
            ]));
        }

        return $errors;
    }

    /**
     * @return array<string, string>
     */
    private function skipReason(CandidateUnderTest $candidate): array
    {
        return ['skipped' => sprintf('No photograph for candidate %s.', $candidate->findId)];
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function row(CandidateUnderTest $candidate, int $position, array $result): array
    {
        return [
            'type' => 'assessment',
            'judge' => $this->judge->name(),
            'seed' => $this->seed,
            'query' => $candidate->query,
            'findId' => $candidate->findId,
            'retrievalRank' => $candidate->retrievalRank,
            'position' => $position,
        ] + $result;
    }
}
