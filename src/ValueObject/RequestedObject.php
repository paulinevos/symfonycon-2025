<?php

namespace App\ValueObject;

use Symfony\AI\Platform\Message\Content\Collection;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Store\Document\EmbeddableDocumentInterface;
use Symfony\AI\Store\Document\Metadata;
use Symfony\Component\Uid\UuidV7;

final class RequestedObject implements EmbeddableDocumentInterface
{
    /**
     * @var array Image[]
     */
    private readonly array $images;
    public function __construct(private readonly UuidV7 $uuid, private readonly Text $description, Image ...$images)
    {
        $this->images = $images;
    }

    public static function new(string $description, ?Image $image): self
    {
        $images = $image ? [$image] : [];
        return new self(
            UuidV7::v7(),
            new Text($description),
            ...$images,
        );
    }

    public function getId(): UuidV7
    {
        return $this->uuid;
    }

    public function getContent(): Collection
    {
        return new Collection(
            $this->description,
            ...$this->images,
        );
    }

    public function getMetadata(): Metadata
    {
        return new Metadata();
    }
}
