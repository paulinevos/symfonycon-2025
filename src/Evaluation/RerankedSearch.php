<?php

namespace App\Evaluation;

use App\Document\Artefact;
use App\Query\ObjectDescription;
use App\Reranker\VoyageReranker;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\AI\Platform\Message\Content\Image;

/**
 * Two-stage retrieval: cast a wide net, then re-score it properly.
 *
 * The embedding model encodes the query and each artefact separately, so it
 * never compares them directly — it only measures how close two independently
 * drawn points landed. A cross-encoder reads the query and one candidate
 * together, which is far more accurate but far too slow to run over 11,666
 * artefacts. So retrieval picks the shortlist and the reranker orders it.
 *
 * The query text is the caption a vision model wrote for the photo, because a
 * text reranker cannot see the photo itself.
 */
final readonly class RerankedSearch implements RetrievalPipeline
{
    /** How many candidates to hand the reranker. */
    private const SHORTLIST = 50;

    public function __construct(
        private ChunkedRankFusionSearch $retrieval,
        private VoyageReranker $reranker,
        private ObjectDescription $description,
        private DocumentManager $dm,
    ) {
    }

    public static function name(): string
    {
        return 'reranked-voyage';
    }

    public function retrieve(Image $query, int $limit): array
    {
        $shortlist = $this->retrieval->retrieve($query, self::SHORTLIST);

        if ([] === $shortlist) {
            return [];
        }

        $summaries = $this->summariesOf($shortlist);
        $caption = $this->description->of($query);

        $ranked = [];
        foreach ($this->reranker->score($caption, array_values($summaries), $limit) as $entry) {
            $ranked[] = array_keys($summaries)[$entry['index']];
        }

        return $ranked;
    }

    /**
     * @param list<string> $findIds
     *
     * @return array<string, string> find ID => summary, in shortlist order
     */
    private function summariesOf(array $findIds): array
    {
        $rows = $this->dm->getDocumentCollection(Artefact::class)->find(
            ['findId' => ['$in' => $findIds]],
            [
                'projection' => ['findId' => 1, 'summary' => 1],
                'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'],
            ],
        );

        $byFindId = [];
        foreach ($rows as $row) {
            $byFindId[$row['findId']] = $row['summary'];
        }

        // Preserve the retrieval order; $in does not.
        $ordered = [];
        foreach ($findIds as $findId) {
            if (isset($byFindId[$findId])) {
                $ordered[$findId] = $byFindId[$findId];
            }
        }

        return $ordered;
    }
}
