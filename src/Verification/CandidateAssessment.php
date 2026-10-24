<?php

namespace App\Verification;

/**
 * How one candidate answers the two questions the app actually asks.
 *
 * The questions are separate because the app's answer can be either: the same
 * object, or an older object serving the same function. Collapsing them into a
 * single relevance score hides which one the model thought it found.
 */
final readonly class CandidateAssessment
{
    public function __construct(
        public int $sameObjectScore,
        public int $sameFunctionScore,
        public string $rationale,
    ) {
    }
}
