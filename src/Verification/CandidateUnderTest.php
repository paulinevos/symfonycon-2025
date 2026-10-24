<?php

namespace App\Verification;

/**
 * One frozen candidate from the annotation sheet, with the files a judge needs.
 *
 * The sheet, not retrieval, is the unit of reproducibility: every judge and
 * every seed ranks the same candidate set, so a difference between runs is the
 * judge and nothing else.
 */
final readonly class CandidateUnderTest
{
    public function __construct(
        public string $query,
        public string $findId,
        public string $summary,
        public string $queryPhotoPath,
        public string $candidatePhotoPath,
        public int $retrievalRank,
    ) {
    }

    public function hasPhotograph(): bool
    {
        return '' !== $this->candidatePhotoPath && is_file($this->candidatePhotoPath);
    }
}
