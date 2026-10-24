<?php

namespace App\Verification;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

/**
 * The OpenAI single-choice answer: two orderings over the same shortlist.
 *
 * Orderings rather than scores because a choice model is better at "this
 * before that" than at calibrated numbers, and the old Jev arm was scored from
 * probabilities over the same shape. Labels are comma-separated in the string
 * so the schema stays a plain string for every provider.
 */
final class ChoiceOrder
{
    #[Schema(description: 'Every candidate label exactly once, comma-separated, best first, for "is this the same object?".')]
    public string $sameObjectOrder = '';

    #[Schema(description: 'Every candidate label exactly once, comma-separated, best first, for "is this an older object serving the same function?".')]
    public string $sameFunctionOrder = '';
}
