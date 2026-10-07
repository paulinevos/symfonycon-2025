<?php

namespace App\Document;

use Doctrine\ODM\MongoDB\Mapping\ClassMetadata;
use Doctrine\ODM\MongoDB\Types\Type;
use Symfony\AI\Platform\Message\Content;
use Symfony\AI\Store\Document\EmbeddableDocumentInterface;
use Symfony\AI\Store\Document\Metadata;
use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;
use Symfony\Component\Uid\UuidV7;

// Multimodal embeddings: summary text plus the artefact photographs, fused into
// one vector. Own collection so these stay comparable only to each other.
#[ODM\Document(collection: 'amsterdam_artefacts')]
#[ODM\VectorSearchIndex(
    fields: [
        [
            'type' => 'vector',
            'path' => 'embeddingVector',
            'numDimensions' => 1024,
            'similarity' => ClassMetadata::VECTOR_SIMILARITY_DOT_PRODUCT,
        ],
    ],
    name: 'default',
)]
class Artefact implements EmbeddableDocumentInterface
{
    #[ODM\Id(type: Type::UUID, strategy: 'AUTO')]
    public ?UuidV7 $id;

    public function __construct(
        #[ODM\Field(type: Type::STRING)]
        #[ODM\UniqueIndex]
        public readonly string $findId,
        #[ODM\Field(type: Type::STRING)]
        public string          $summary,
        #[ODM\EmbedOne(nullable: true, targetDocument: YearRange::class)]
        public ?YearRange      $dated,
        #[ODM\Field(type: Type::COLLECTION)]
        public readonly array  $imageUrls,
        #[ODM\Field(type: Type::COLLECTION)]
        public ?array          $embeddingVector = null,
    ) {
    }

    public function getId(): int|string
    {
        return (string) $this->id;
    }

    public function getContent(): string|object
    {
        return new Content\Collection(
            new Content\Text($this->summary),
            ...array_map(
                fn (string $imageUrl) => new Content\ImageUrl($imageUrl),
                $this->imageUrls
            )
        );
    }

    public function getMetadata(): Metadata
    {
        return new Metadata();
    }
}
