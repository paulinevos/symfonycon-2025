<?php

namespace App\Verification;

/**
 * One judge, one seed, one output stream.
 *
 * Errors are written to the stream and reported back rather than thrown: a
 * single malformed candidate should not discard the ninety-nine assessments
 * already paid for, but the run must still exit non-zero so the failure is not
 * mistaken for a clean result.
 */
final readonly class VerificationRun
{
    public function __construct(
        private CandidateJudge $judge,
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

    public function assess(CandidateUnderTest $candidate, int $position): ?string
    {
        if ($this->dryRun) {
            $this->writer->write($this->row($candidate, $position, [
                'sameObjectScore' => null,
                'sameFunctionScore' => null,
                'rationale' => 'dry run',
            ]));

            return null;
        }

        try {
            $assessment = $this->judge->assess($candidate);
        } catch (MissingPhotograph $missing) {
            $this->writer->write($this->row($candidate, $position, ['skipped' => $missing->getMessage()]));

            return null;
        } catch (\Throwable $error) {
            $this->writer->write($this->row($candidate, $position, ['error' => $error->getMessage()]));

            return $error->getMessage();
        }

        $this->writer->write($this->row($candidate, $position, [
            'sameObjectScore' => $assessment->sameObjectScore,
            'sameFunctionScore' => $assessment->sameFunctionScore,
            'rationale' => $assessment->rationale,
        ]));

        return null;
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
