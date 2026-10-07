<?php

namespace App\Evaluation;

/**
 * The result of running one pipeline over one set of examples.
 *
 * Metrics are derived from the per-example outcomes rather than accumulated
 * alongside them, so there is one record of what happened and the summary can
 * never disagree with the detail.
 */
final readonly class EvaluationRun
{
    /**
     * @param list<CaseOutcome> $outcomes
     */
    public function __construct(public array $outcomes)
    {
    }

    public function metrics(): RetrievalMetrics
    {
        $metrics = new RetrievalMetrics();

        foreach ($this->outcomes as $outcome) {
            foreach ($outcome->ranks as $index => $rank) {
                $metrics->record($rank, $outcome->latencies[$index]);
            }
        }

        return $metrics;
    }

    public function examples(): int
    {
        return count($this->outcomes);
    }

    public function repeats(): int
    {
        return [] === $this->outcomes ? 0 : count($this->outcomes[0]->ranks);
    }
}
