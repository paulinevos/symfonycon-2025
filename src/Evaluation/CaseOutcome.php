<?php

namespace App\Evaluation;

/**
 * What one example did, once per repeat.
 *
 * Every rank is kept rather than only the average, because for a demo the
 * interesting thing is the shape: an example answered first every time reads
 * differently from one that scatters between first and fourth.
 */
final readonly class CaseOutcome
{
    /**
     * @param list<int|null> $ranks     1-based position per repeat, null when
     *                                  the expected artefact was not retrieved
     * @param list<float>    $latencies milliseconds per repeat
     */
    public function __construct(
        public string $findId,
        public string $summary,
        public array $ranks,
        public array $latencies,
    ) {
    }

    public function hitsWithin(int $k): int
    {
        $hits = 0;

        foreach ($this->ranks as $rank) {
            if (null !== $rank && $rank <= $k) {
                ++$hits;
            }
        }

        return $hits;
    }

    /**
     * @return list<string> one cell per repeat, for a table row
     */
    public function formattedRanks(): array
    {
        return array_map(
            static fn (?int $rank): string => null === $rank ? 'miss' : (string) $rank,
            $this->ranks,
        );
    }
}
