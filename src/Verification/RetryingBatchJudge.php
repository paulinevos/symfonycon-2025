<?php

namespace App\Verification;

/**
 * Retries a whole-shortlist call before letting the failure through.
 *
 * A batch failure costs every candidate in the query, so a transient rate
 * limit is worth retrying rather than recording. A missing photograph is a
 * data problem and is rethrown immediately.
 */
final readonly class RetryingBatchJudge implements BatchCandidateJudge
{
    public function __construct(
        private BatchCandidateJudge $judge,
        private int $attempts = 3,
    ) {
    }

    public function name(): string
    {
        return $this->judge->name();
    }

    public function assessAll(array $candidates): array
    {
        $lastError = null;

        for ($attempt = 1; $attempt <= $this->attempts; $attempt++) {
            try {
                return $this->judge->assessAll($candidates);
            } catch (MissingPhotograph $missing) {
                throw $missing;
            } catch (\Throwable $error) {
                $lastError = $error;
                usleep(500_000 * (2 ** ($attempt - 1)));
            }
        }

        throw $lastError ?? new \RuntimeException('The judge failed without an error.');
    }
}
