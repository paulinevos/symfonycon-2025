<?php

namespace App\Evaluation;

/**
 * What a pipeline scored over a ground truth set.
 *
 * Built by recording one rank per question — the position the right answer
 * came back at, or null when it did not come back at all — then asking for the
 * summary. Keeping the raw ranks means every metric is derived from the same
 * run rather than needing a pass each.
 */
final class RetrievalMetrics
{
    /**
     * @var list<int|null> one entry per question: the 1-based rank of the
     *                     expected artefact, or null if it was not retrieved
     */
    private array $ranks = [];

    /**
     * @var list<float> milliseconds per question
     */
    private array $latencies = [];

    public function record(?int $rank, float $milliseconds): void
    {
        $this->ranks[] = $rank;
        $this->latencies[] = $milliseconds;
    }

    public function questions(): int
    {
        return count($this->ranks);
    }

    public function recallAt(int $k): float
    {
        if ([] === $this->ranks) {
            return 0.0;
        }

        $hits = 0;
        foreach ($this->ranks as $rank) {
            if (null !== $rank && $rank <= $k) {
                ++$hits;
            }
        }

        return $hits / count($this->ranks);
    }

    /**
     * Mean reciprocal rank: rewards putting the right answer near the top, not
     * merely somewhere in the list. A hit at rank 1 contributes 1, at rank 2
     * contributes 0.5, a miss contributes 0.
     */
    public function meanReciprocalRank(): float
    {
        if ([] === $this->ranks) {
            return 0.0;
        }

        $total = 0.0;
        foreach ($this->ranks as $rank) {
            $total += null === $rank ? 0.0 : 1 / $rank;
        }

        return $total / count($this->ranks);
    }

    public function medianLatency(): float
    {
        return $this->percentileLatency(50);
    }

    public function percentileLatency(int $percentile): float
    {
        if ([] === $this->latencies) {
            return 0.0;
        }

        $sorted = $this->latencies;
        sort($sorted);

        $index = (int) ceil($percentile / 100 * count($sorted)) - 1;

        return $sorted[max(0, $index)];
    }

    /**
     * @return array<string, float|int>
     */
    public function toArray(): array
    {
        return [
            'questions' => $this->questions(),
            'recall@1' => $this->recallAt(1),
            'recall@5' => $this->recallAt(5),
            'recall@10' => $this->recallAt(10),
            'mrr' => $this->meanReciprocalRank(),
            'p50_ms' => $this->medianLatency(),
            'p95_ms' => $this->percentileLatency(95),
        ];
    }
}
