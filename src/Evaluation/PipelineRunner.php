<?php

namespace App\Evaluation;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Message\Content\Image;

final readonly class PipelineRunner
{
    private const DEPTH = 10;

    public function __construct(
        private ImageCache $images,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param callable(int, int): void|null $onProgress called with (done, total) per example
     */
    public function run(
        RetrievalPipeline $pipeline,
        GroundTruthSet $set,
        int $repeats = 1,
        ?callable $onProgress = null,
    ): EvaluationRun {
        $outcomes = [];
        $done = 0;

        foreach ($set as $case) {
            $outcomes[] = $this->outcomeFor($pipeline, $case, $repeats);

            if (null !== $onProgress) {
                $onProgress(++$done, count($set));
            }
        }

        return new EvaluationRun($outcomes);
    }

    private function outcomeFor(RetrievalPipeline $pipeline, GroundTruthCase $case, int $repeats): CaseOutcome
    {
        // Resolved once per example and outside the clock: fetching a
        // photograph is the harness's own cost, not the pipeline's.
        $image = $this->imageFor($case);

        if (null === $image) {
            return new CaseOutcome(
                $case->expectedFindId,
                $case->summary,
                array_fill(0, $repeats, null),
                array_fill(0, $repeats, 0.0),
            );
        }

        $ranks = [];
        $latencies = [];

        for ($repeat = 0; $repeat < $repeats; ++$repeat) {
            [$rank, $milliseconds] = $this->ask($pipeline, $case, $image);
            $ranks[] = $rank;
            $latencies[] = $milliseconds;
        }

        return new CaseOutcome($case->expectedFindId, $case->summary, $ranks, $latencies);
    }

    /**
     * @return array{0: int|null, 1: float}
     */
    private function ask(RetrievalPipeline $pipeline, GroundTruthCase $case, Image $image): array
    {
        $startedAt = microtime(true);

        try {
            $findIds = $pipeline->retrieve($image, self::DEPTH);
        } catch (\Throwable $e) {
            // One failing query must not void the run; it counts as a miss and
            // is logged so a systematic failure stays visible.
            $this->logger->warning('Evaluation question failed', [
                'findId' => $case->expectedFindId,
                'error' => $e->getMessage(),
            ]);

            return [null, (microtime(true) - $startedAt) * 1000];
        }

        return [$this->rankOf($case, $findIds), (microtime(true) - $startedAt) * 1000];
    }

    private function imageFor(GroundTruthCase $case): ?Image
    {
        try {
            return $this->images->imageFor($case);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not load ground truth image', [
                'findId' => $case->expectedFindId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param list<string> $findIds
     */
    private function rankOf(GroundTruthCase $case, array $findIds): ?int
    {
        foreach ($findIds as $index => $findId) {
            if ($case->isAnsweredBy($findId)) {
                return $index + 1;
            }
        }

        return null;
    }
}
