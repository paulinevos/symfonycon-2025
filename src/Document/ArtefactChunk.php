<?php

namespace App\Document;

use Doctrine\ODM\MongoDB\Mapping\ClassMetadata;
use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;
use Doctrine\ODM\MongoDB\Types\Type;
use Symfony\Component\Uid\UuidV7;

/**
 * One embeddable piece of an artefact: its summary, or one of its photographs.
 *
 * Fusing a summary and two photos into a single vector averages them, and the
 * average resembles none of them. A photo of the object from an unusual angle
 * has to out-compete that blur. Embedding each piece separately keeps every
 * signal sharp; the pieces are reassembled at query time by fusing their ranks.
 */
#[ODM\Document(collection: 'artefact_chunks')]
#[ODM\VectorSearchIndex(
    fields: [
        [
            'type' => 'vector',
            'path' => 'embeddingVector',
            'numDimensions' => 1024,
            'similarity' => ClassMetadata::VECTOR_SIMILARITY_DOT_PRODUCT,
        ],
        [
            'type' => 'filter',
            'path' => 'kind',
        ],
    ],
    name: 'default',
)]
class ArtefactChunk
{
    #[ODM\Id(type: Type::UUID, strategy: 'AUTO')]
    public ?UuidV7 $id;

    public function __construct(
        /** The artefact this chunk was cut from. */
        #[ODM\Field(type: Type::STRING)]
        #[ODM\Index]
        public readonly string $findId,
        #[ODM\Field(type: Type::STRING)]
        public readonly string $kind,
        #[ODM\Field(type: Type::STRING)]
        public readonly string $summary,
        #[ODM\Field(type: Type::STRING, nullable: true)]
        public readonly ?string $imageUrl = null,
        #[ODM\Field(type: Type::COLLECTION)]
        public ?array $embeddingVector = null,
    ) {
    }

    public static function forSummary(string $findId, string $summary): self
    {
        return new self($findId, ChunkKind::Summary->value, $summary);
    }

    public static function forImage(string $findId, string $summary, string $imageUrl): self
    {
        return new self($findId, ChunkKind::Image->value, $summary, $imageUrl);
    }
}
