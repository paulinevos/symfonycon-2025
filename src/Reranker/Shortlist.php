<?php

namespace App\Reranker;

/**
 * What retrieval found, in the order something put it in.
 *
 * Reranking never adds to a shortlist, it only reorders it — which is why a
 * query whose right answer retrieval missed cannot be rescued here.
 */
final readonly class Shortlist
{
    /**
     * @param list<Candidate> $candidates
     */
    public function __construct(private array $candidates)
    {
    }

    public function isEmpty(): bool
    {
        return [] === $this->candidates;
    }

    /**
     * @return list<string>
     */
    public function summaries(): array
    {
        return array_map(static fn (Candidate $candidate): string => $candidate->summary, $this->candidates);
    }

    /**
     * @param list<array{index: int, relevance_score: float}> $scores best first
     */
    public function reorderedBy(array $scores): self
    {
        return new self(array_map(fn (array $entry): Candidate => $this->candidates[$entry['index']], $scores));
    }

    public function labelAt(int $position): string
    {
        if (!isset($this->candidates[$position])) {
            return '';
        }

        return $this->candidates[$position]->label();
    }
}
