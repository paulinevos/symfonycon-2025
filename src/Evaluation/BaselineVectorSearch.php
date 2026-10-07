<?php

namespace App\Evaluation;

use App\Document\Artefact;
use App\ValueObject\RequestedObject;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Store\Document\VectorizerInterface;

/**
 * The pipeline slices 1 to 5 built: embed the query, run one $vectorSearch,
 * take what comes back. This is the number every later enhancement has to beat.
 */
final readonly class BaselineVectorSearch implements RetrievalPipeline
{
    public function __construct(
        private DocumentManager $dm,
        private VectorizerInterface $vectorizer,
    ) {
    }

    public static function name(): string
    {
        return 'baseline';
    }

    public function retrieve(Image $query, int $limit): array
    {
        $vectorized = $this->vectorizer->vectorize(RequestedObject::new('', $query));

        $builder = $this->dm->getRepository(Artefact::class)->createAggregationBuilder();
        $builder
            ->vectorSearch()
            ->limit($limit)
            ->numCandidates($limit * 20)
            ->index('default')
            ->path('embeddingVector')
            ->queryVector($vectorized->getVector()->getData())
            ->project()
            ->field('_id')->expression(0)
            ->field('findId')->expression('$findId');

        $findIds = [];
        foreach ($builder->getAggregation()->execute() as $row) {
            $findIds[] = $row['findId'];
        }

        return $findIds;
    }
}
