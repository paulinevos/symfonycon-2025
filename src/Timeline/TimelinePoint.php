<?php

namespace App\Timeline;

/**
 * One ranked match, placed on the modern-to-old axis.
 *
 * A null position means the artefact has no date; the template lists those
 * separately rather than guessing where they belong.
 */
final readonly class TimelinePoint
{
    public function __construct(
        public int $rank,
        public string $findId,
        public string $imageUrl,
        public string $summary,
        public ?float $position,
        public string $period,
    ) {
    }
}
