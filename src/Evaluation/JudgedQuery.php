<?php

namespace App\Evaluation;

/**
 * One annotated query: the shortlist retrieval returned, in retrieval order,
 * with a human verdict on each candidate.
 *
 * The verdicts are the ground truth. "y" is a match, "n" is not, and "~" is
 * the closest available without being a match — the bucket that matters when
 * the app can only ever return something.
 */
final readonly class JudgedQuery
{
    public const RELEVANT = 'y';
    public const CLOSE = '~';

    /**
     * @param list<array{findId: string, summary: string, verdict: string}> $candidates
     */
    public function __construct(
        public string $query,
        public array $candidates,
    ) {
    }

    /**
     * @return list<string> find IDs, in the order retrieval put them
     */
    public function shortlist(): array
    {
        return array_map(static fn (array $candidate): string => $candidate['findId'], $this->candidates);
    }

    /**
     * @return list<string> summaries, in the order retrieval put them
     */
    public function summaries(): array
    {
        return array_map(static fn (array $candidate): string => $candidate['summary'], $this->candidates);
    }

    public function verdictOf(string $findId): string
    {
        foreach ($this->candidates as $candidate) {
            if ($candidate['findId'] === $findId) {
                return $candidate['verdict'];
            }
        }

        return '';
    }

    /**
     * Where the first genuine match sits, one-based, or null if there is none.
     *
     * @param list<string> $orderedFindIds
     */
    public function rankOfFirstMatch(array $orderedFindIds): ?int
    {
        foreach ($orderedFindIds as $position => $findId) {
            if (self::RELEVANT === $this->verdictOf($findId)) {
                return $position + 1;
            }
        }

        return null;
    }

    /**
     * Whether anything in the list is a match at all — the difference between
     * "the app ranked badly" and "the catalogue has no answer".
     */
    public function hasAMatch(): bool
    {
        foreach ($this->candidates as $candidate) {
            if (self::RELEVANT === $candidate['verdict']) {
                return true;
            }
        }

        return false;
    }
}
