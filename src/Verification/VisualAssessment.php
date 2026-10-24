<?php

namespace App\Verification;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

/**
 * The structured answer the OpenAI judge is constrained to.
 *
 * Scores are 0–100 rather than booleans because the ordering is the
 * measurement: a boolean produces ties, and a tie hands the ranking back to
 * the retrieval order the benchmark is trying to beat.
 */
final class VisualAssessment
{
    #[Schema(description: 'How likely the candidate is the same object as the one in the query photograph.', minimum: 0, maximum: 100)]
    public int $sameObjectScore = 0;

    #[Schema(description: 'How likely the candidate is a different object serving the same function — an older equivalent — however different it looks.', minimum: 0, maximum: 100)]
    public int $sameFunctionScore = 0;

    #[Schema(description: 'One sentence explaining the two scores.')]
    public string $rationale = '';
}
