<?php

namespace App\Evaluation;

use App\Document\Artefact;
use App\Query\ObjectDescription;
use App\Reranker\JevReranker;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\AI\Platform\Message\Content\Image;

/**
 * The same two-stage shape as RerankedSearch, with Jev doing the ordering.
 *
 * Deliberately identical everywhere else — same retrieval, same caption as
 * the query — so the only thing the benchmark measures is the reranker.
 *
 * The shortlist matches rerank-3's fifty. It used to be twenty, back when
 * scoring a candidate meant a request of its own; asking Jev to choose between
 * them costs one request regardless of how many there are, so there is no
 * longer a reason to hold back.
 */
final readonly class JevRerankedSearch implements RetrievalPipeline
{
    private const SHORTLIST = 50;

    public function __construct(
        private ChunkedRankFusionSearch $retrieval,
        private JevReranker $reranker,
        private ObjectDescription $description,
        private DocumentManager $dm,
    ) {
    }

    public static function name(): string
    {
        return 'reranked-jev';
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

        $ordered = [];
        foreach ($findIds as $findId) {
            if (isset($byFindId[$findId])) {
                $ordered[$findId] = $byFindId[$findId];
            }
        }

        return $ordered;
    }
}
