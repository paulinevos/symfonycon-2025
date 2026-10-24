<?php

namespace App\Verification;

/**
 * Retries a judge call a few times before letting the failure through.
 *
 * A benchmark makes thousands of calls; one rate limit or dropped connection
 * in the middle would otherwise discard the whole query. A missing photograph
 * is not transient and is rethrown immediately.
 */
final readonly class RetryingJudge implements CandidateJudge
{
    public function __construct(
        private CandidateJudge $judge,
        private int $attempts = 3,
    ) {
    }

    public function name(): string
    {
        return $this->judge->name();
    }

    public function assess(CandidateUnderTest $candidate): CandidateAssessment
    {
        $lastError = null;

        for ($attempt = 1; $attempt <= $this->attempts; $attempt++) {
            try {
                return $this->judge->assess($candidate);
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
