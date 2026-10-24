<?php

namespace App\Verification;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * A deterministic shuffle, so a seed identifies a candidate order.
 *
 * PHP's global shuffle would make the order depend on whatever else ran in the
 * process. A seeded Randomizer keeps the benchmark reproducible and gives the
 * scorer a position to record when a tie has to be broken.
 */
final readonly class SeededShuffle
{
    /**
     * @template T
     *
     * @param list<T> $items
     *
     * @return list<T>
     */
    public function of(array $items, int $seed): array
    {
        $randomizer = new Randomizer(new Mt19937($seed));
        $shuffled = $items;

        for ($index = count($shuffled) - 1; $index > 0; $index--) {
            $swap = $randomizer->getInt(0, $index);
            [$shuffled[$index], $shuffled[$swap]] = [$shuffled[$swap], $shuffled[$index]];
        }

        return $shuffled;
    }
}
