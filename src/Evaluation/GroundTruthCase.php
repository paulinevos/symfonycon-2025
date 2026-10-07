<?php

namespace App\Evaluation;

/**
 * One evaluation question with a known right answer.
 *
 * The trick that makes this free: an artefact photographed twice gives us a
 * picture the index has never seen. Embed the artefact from its first photo,
 * query with the second, and the artefact that should come back first is the
 * one we started from. No human labelling, 4102 questions available.
 */
final readonly class GroundTruthCase
{
    public function __construct(
        public string $expectedFindId,
        public string $indexedImageUrl,
        public string $heldOutImageUrl,
        public string $summary,
    ) {
    }

    public function isAnsweredBy(string $findId): bool
    {
        return $findId === $this->expectedFindId;
    }
}
