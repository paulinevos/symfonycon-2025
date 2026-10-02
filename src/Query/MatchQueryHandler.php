<?php

namespace App\Query;

use App\Document\Artefact;
use App\Document\MatchCandidate;
use App\Document\MatchQueryResult;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\AI\Store\Document\VectorizerInterface;

final readonly class MatchQueryHandler
{
    public function __construct(
        private VectorizerInterface $vectorizer,
        private DocumentManager     $dm,
    ) {}

    public function __invoke(MatchQuery $command): MatchQueryResult
    {
        $vector = $this->vectorizer->vectorize($command->prompt);

        $builder = $this->dm->getRepository(Artefact::class)
            ->createAggregationBuilder()
            ->hydrate(MatchCandidate::class);

        $builder
            ->vectorSearch()
            ->limit(10)
            ->numCandidates(200)
            ->index('default')
            ->path('embeddingVector')
            ->queryVector($vector->getData())
            ->project()
            ->field('_id')->expression(0)
            ->field('artefact')->expression('$$ROOT')
            ->field('score')->meta('vectorSearchScore');

        $matches = $builder
            ->getAggregation()
            ->execute();

        $result = new MatchQueryResult($command->prompt, ...$matches->toArray());

        $this->dm->persist($result);
        $this->dm->flush();

        return $result;
    }
}
