<?php

namespace App\Evaluation;

use App\Document\Artefact;
use Doctrine\ODM\MongoDB\DocumentManager;

/**
 * The artefacts photographed twice, sampled deterministically so two runs of
 * the same size ask the same questions and their numbers can be compared.
 */
final class GroundTruthSet implements \IteratorAggregate, \Countable
{
    private const SEED = 20251212;

    /**
     * @param list<GroundTruthCase> $cases
     */
    private function __construct(private readonly array $cases)
    {
    }

    public static function sample(DocumentManager $dm, int $size): self
    {
        $candidates = $dm->getDocumentCollection(Artefact::class)->find(
            [
                // Two images: one indexed, one held back to query with.
                'imageUrls.1' => ['$exists' => true],
                // Only artefacts actually in the index can be found at all;
                // including unembedded ones would silently count as misses.
                'embeddingVector' => ['$ne' => null],
            ],
            [
                'projection' => ['findId' => 1, 'imageUrls' => 1, 'summary' => 1],
                'sort' => ['findId' => 1],
                'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'],
            ],
        )->toArray();

        if ([] === $candidates) {
            throw new \RuntimeException('No artefact has two images; cannot build a ground truth set.');
        }

        mt_srand(self::SEED);
        shuffle($candidates);

        $cases = [];
        foreach (array_slice($candidates, 0, $size) as $row) {
            $images = array_values((array) $row['imageUrls']);
            $cases[] = new GroundTruthCase(
                expectedFindId: $row['findId'],
                indexedImageUrl: $images[0],
                heldOutImageUrl: $images[1],
                summary: $row['summary'],
            );
        }

        return new self($cases);
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->cases);
    }

    public function count(): int
    {
        return count($this->cases);
    }
}
