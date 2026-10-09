<?php

namespace App\Query;

use App\Document\Artefact;
use App\Reranker\Candidate;
use App\Reranker\Shortlist;
use App\ValueObject\RequestedObject;
use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\Collection;
use Symfony\AI\Store\Document\VectorizerInterface;

/**
 * Text retrieval over amsterdam_artefacts, the collection the web demo reads.
 *
 * Not the held-out collection Artefact maps to: that one embeds only the first
 * photograph, and on it the right answer to the demo query already ranks
 * first, which leaves a reranker nothing to show.
 */
final readonly class ShortlistSearch
{
    private const COLLECTION = 'amsterdam_artefacts';

    public function __construct(
        private DocumentManager $dm,
        private VectorizerInterface $vectorizer,
    ) {
    }

    public function for(string $query, int $size): Shortlist
    {
        $vectorized = $this->vectorizer->vectorize(RequestedObject::new($query, null));

        $rows = $this->collection()->aggregate(
            [
                ['$vectorSearch' => [
                    'index' => 'default',
                    'path' => 'embeddingVector',
                    'queryVector' => $vectorized->getVector()->getData(),
                    'numCandidates' => $size * 20,
                    'limit' => $size,
                ]],
                ['$project' => ['_id' => 0, 'summary' => 1]],
            ],
            ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']],
        );

        return new Shortlist(array_map(
            static fn (array $row): Candidate => new Candidate($row['summary']),
            iterator_to_array($rows, false),
        ));
    }

    private function collection(): Collection
    {
        return $this->dm->getDocumentDatabase(Artefact::class)->selectCollection(self::COLLECTION);
    }
}
