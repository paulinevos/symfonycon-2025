<?php

namespace App\Timeline;

use App\Document\ScoredMatch;

/**
 * The ranked matches placed on a modern-to-old axis.
 *
 * The corpus spans 126,000 years, so a linear axis squashes every modern
 * object into the first pixel. A log scale keeps the modern end readable and
 * still puts the prehistoric finds at the far end. Every slice uses the same
 * fixed scale, so the three demo tabs line up.
 */
final readonly class Timeline
{
    private const CURRENT_YEAR = 2025;
    private const OLDEST_AGE = 130_000;

    /**
     * @param list<TimelinePoint> $points
     */
    private function __construct(
        public array $points,
    ) {
    }

    /**
     * @param list<ScoredMatch> $matches
     */
    public static function fromMatches(array $matches): self
    {
        $points = [];
        foreach ($matches as $match) {
            $points[] = self::pointFor($match);
        }

        return new self($points);
    }

    private static function pointFor(ScoredMatch $match): TimelinePoint
    {
        $dated = $match->artefact->dated;
        if (null === $dated) {
            return new TimelinePoint(
                $match->rank,
                $match->artefact->findId,
                $match->artefact->imageUrls[0] ?? '',
                $match->artefact->summary,
                null,
                'undated',
            );
        }

        $year = ($dated->start + $dated->end) / 2;
        $age = max(0, self::CURRENT_YEAR - $year);
        $position = 100 * log10(1 + $age) / log10(1 + self::OLDEST_AGE);

        return new TimelinePoint(
            $match->rank,
            $match->artefact->findId,
            $match->artefact->imageUrls[0] ?? '',
            $match->artefact->summary,
            $position,
            self::period($year),
        );
    }

    private static function period(float $year): string
    {
        if ($year < 0) {
            return number_format(abs($year)) . ' BCE';
        }

        return number_format($year) . ' CE';
    }
}
