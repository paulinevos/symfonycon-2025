<?php

namespace App\Query;

use App\Document\Artefact;
use App\Document\MatchCandidate;
use App\Document\MatchQueryResult;
use App\Evaluation\CandidateImageCache;
use App\Evaluation\CaptionCache;
use App\ValueObject\RequestedObject;
use App\Verification\CandidateUnderTest;
use App\Verification\JudgeFactory;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\AI\Store\Document\VectorizerInterface;

/**
 * Ask for an old version of the object, retrieve thirty, then verify each one.
 *
 * The hybrid benchmark showed the semantic leg ("show me an old X") was the
 * strongest single retriever; the gpt pointwise judge is the second stage that
 * asks whether each candidate is an old equivalent.
 */
final readonly class MatchQueryHandler
{
    private const CANDIDATES = 30;
    private const SHOWN = 10;

    public function __construct(
        private VectorizerInterface $vectorizer,
        private DocumentManager $dm,
        private JudgeFactory $judges,
        private CaptionCache $captions,
        private CandidateImageCache $images,
    ) {
    }

    public function __invoke(MatchQuery $command): MatchQueryResult
    {
        $object = $this->objectOf($command);
        $candidates = $this->candidates($object);
        if (null !== $command->image) {
            $candidates = $this->rerank($command, $candidates, $object);
        }

        $result = new MatchQueryResult(
            $command->prompt ?? $object,
            ...array_slice($candidates, 0, self::SHOWN),
        );

        $this->dm->persist($result);
        $this->dm->flush();

        return $result;
    }

    /**
     * A typed description is better than a generated one; the caption is the
     * fallback for a photo-only search.
     */
    private function objectOf(MatchQuery $command): string
    {
        if (null !== $command->prompt) {
            return $command->prompt;
        }
        if (null === $command->image) {
            return 'object';
        }

        return $this->captions->of($command->image, md5($command->image->asBinary()), 'gpt-4o-mini');
    }

    /**
     * @return list<MatchCandidate>
     */
    private function candidates(string $object): array
    {
        $vectorized = $this->vectorizer->vectorize(
            RequestedObject::new('show me an old ' . $object, null),
        );

        $builder = $this->dm->getRepository(Artefact::class)
            ->createAggregationBuilder()
            ->hydrate(MatchCandidate::class);

        $builder
            ->vectorSearch()
            ->limit(self::CANDIDATES)
            ->numCandidates(200)
            ->index('default')
            ->path('embeddingVector')
            ->queryVector($vectorized->getVector()->getData())
            ->project()
            ->field('_id')->expression(0)
            ->field('artefact')->expression('$$ROOT')
            ->field('score')->meta('vectorSearchScore');

        return $builder->getAggregation()->execute()->toArray();
    }

    /**
     * @param list<MatchCandidate> $candidates
     *
     * @return list<MatchCandidate>
     */
    private function rerank(MatchQuery $command, array $candidates, string $object): array
    {
        if (null === $command->image) {
            return $candidates;
        }

        $queryPath = tempnam(sys_get_temp_dir(), 'query-');
        if (false === $queryPath) {
            return $candidates;
        }
        file_put_contents($queryPath, $command->image->asBinary());

        $underTest = [];
        foreach ($candidates as $index => $candidate) {
            $imageUrl = $candidate->artefact->imageUrls[0] ?? null;
            if (null === $imageUrl) {
                continue;
            }

            $underTest[] = new CandidateUnderTest(
                $object,
                $candidate->artefact->findId,
                $candidate->artefact->summary,
                $queryPath,
                $this->images->pathFor($imageUrl),
                $index + 1,
            );
        }

        if ([] === $underTest) {
            unlink($queryPath);

            return $candidates;
        }

        $judge = $this->judges->batch('openai-pointwise', 'gpt-4o');
        try {
            $assessments = $judge->assessAll($underTest);
        } finally {
            unlink($queryPath);
        }

        $reranked = [];
        foreach ($candidates as $candidate) {
            $assessment = $assessments[$candidate->artefact->findId] ?? null;
            if (null === $assessment) {
                continue;
            }

            // The app is looking for the old equivalent, so the function
            // question orders the page. The object score remains available.
            $candidate->score = $assessment->sameFunctionScore / 100;
            $reranked[] = $candidate;
        }

        usort($reranked, static fn (MatchCandidate $a, MatchCandidate $b): int => $b->score <=> $a->score);

        return $reranked;
    }
}
