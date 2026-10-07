<?php

namespace App\Evaluation;

use App\Document\ArtefactChunk;
use App\Document\ChunkKind;
use App\ValueObject\RequestedObject;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Store\Document\VectorizerInterface;

/**
 * Retrieve over chunks, then fuse the ranked lists back into artefacts.
 *
 * Two searches run over the same chunk collection — one restricted to image
 * chunks, one to summary chunks — and MongoDB's $rankFusion combines them by
 * reciprocal rank. An artefact that both looks right and reads right is pushed
 * above one that only does the first, without either signal's raw score having
 * to be made comparable with the other's.
 */
final readonly class ChunkedRankFusionSearch implements RetrievalPipeline
{
    /** Chunks to pull per sub-pipeline before fusing. */
    private const DEPTH = 60;

    public function __construct(
        private DocumentManager $dm,
        private VectorizerInterface $vectorizer,
    ) {
    }

    public static function name(): string
    {
        return 'chunked';
    }

    public function retrieve(Image $query, int $limit): array
    {
        $vector = $this->vectorizer
            ->vectorize(RequestedObject::new('', $query))
            ->getVector()
            ->getData();

        $rows = $this->dm->getDocumentCollection(ArtefactChunk::class)->aggregate(
            [
                ['$rankFusion' => ['input' => ['pipelines' => [
                    'images' => $this->leg($vector, ChunkKind::Image),
                    'summaries' => $this->leg($vector, ChunkKind::Summary),
                ]]]],
                // Several chunks of one artefact can survive the fusion; keep
                // each artefact once, at its best position.
                ['$group' => ['_id' => '$findId', 'score' => ['$max' => ['$meta' => 'score']]]],
                ['$sort' => ['score' => -1]],
                ['$limit' => $limit],
            ],
            ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']],
        );

        $findIds = [];
        foreach ($rows as $row) {
            $findIds[] = $row['_id'];
        }

        return $findIds;
    }

    /**
     * @param list<float> $vector
     *
     * @return list<array<string, mixed>>
     */
    private function leg(array $vector, ChunkKind $kind): array
    {
        return [
            [
                '$vectorSearch' => [
                    'index' => 'default',
                    'path' => 'embeddingVector',
                    'queryVector' => $vector,
                    'numCandidates' => self::DEPTH * 20,
                    'limit' => self::DEPTH,
                    'filter' => ['kind' => $kind->value],
                ],
            ],
        ];
    }
}
