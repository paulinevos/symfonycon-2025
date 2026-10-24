<?php

namespace App\Reranker;

/**
 * One artefact offered to a reranker: the words we have about it, and the
 * photograph we can show.
 */
final readonly class PhotoCandidate
{
    public function __construct(
        public string $findId,
        public string $summary,
        public string $imageUrl,
    ) {
    }
}
