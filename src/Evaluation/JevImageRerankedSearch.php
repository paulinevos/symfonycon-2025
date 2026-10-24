<?php

namespace App\Evaluation;

use App\Document\Artefact;
use App\Reranker\JevImageReranker;
use App\Reranker\PhotoCandidate;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\AI\Platform\Message\Content\Image;

/**
 * Two-stage retrieval, with a reranker that can see.
 *
 * Everything around the reranker matches the text-only Jev pipeline — same
 * chunked retrieval, same shortlist of fifty — so the benchmark measures one
 * thing: whether handing Jev the photographs, rather than a caption written
 * about them, actually helps.
 *
 * The candidates carry their first image, the one the corpus was indexed with.
 * Artefacts without a photograph cannot be shown, so they drop out.
 */
final readonly class JevImageRerankedSearch implements RetrievalPipeline
{
    private const SHORTLIST = 50;

    public function __construct(
        private ChunkedRankFusionSearch $retrieval,
        private JevImageReranker $reranker,
        private DocumentManager $dm,
    ) {
    }

    public static function name(): string
    {
        return 'reranked-jev-image';
    }

    public function retrieve(Image $query, int $limit): array
    {
        $shortlist = $this->retrieval->retrieve($query, self::SHORTLIST);

        if ([] === $shortlist) {
            return [];
        }

        $candidates = $this->candidatesOf($shortlist);

        if ([] === $candidates) {
            return [];
        }

        $ranked = [];
        foreach ($this->reranker->score($query, $candidates, $limit) as $entry) {
            $ranked[] = $candidates[$entry['index']]->findId;
        }

        return $ranked;
    }

    /**
     * @param list<string> $findIds
     *
     * @return list<PhotoCandidate>
     */
    private function candidatesOf(array $findIds): array
    {
        $rows = $this->dm->getDocumentCollection(Artefact::class)->find(
            ['findId' => ['$in' => $findIds]],
            [
                'projection' => ['findId' => 1, 'summary' => 1, 'imageUrls' => 1],
                'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'],
            ],
        );

        $byFindId = [];
        foreach ($rows as $row) {
            if (!empty($row['imageUrls'])) {
                $byFindId[$row['findId']] = new PhotoCandidate(
                    $row['findId'],
                    $row['summary'],
                    $row['imageUrls'][0],
                );
            }
        }

        $ordered = [];
        foreach ($findIds as $findId) {
            if (isset($byFindId[$findId])) {
                $ordered[] = $byFindId[$findId];
            }
        }

        return $ordered;
    }
}
