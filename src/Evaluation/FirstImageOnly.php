<?php

namespace App\Evaluation;

use App\Document\Artefact;
use Symfony\AI\Platform\Message\Content;
use Symfony\AI\Store\Document\EmbeddableDocumentInterface;
use Symfony\AI\Store\Document\Metadata;

/**
 * An artefact presented to the vectorizer with only its first photograph.
 *
 * Wrapping rather than changing Artefact::getContent() keeps the production
 * embedding honest: the app still indexes every image, and only the evaluation
 * withholds one.
 */
final readonly class FirstImageOnly implements EmbeddableDocumentInterface
{
    public function __construct(private Artefact $artefact)
    {
    }

    public function getId(): int|string
    {
        return $this->artefact->getId();
    }

    public function getContent(): string|object
    {
        $images = array_slice($this->artefact->imageUrls, 0, 1);

        return new Content\Collection(
            new Content\Text($this->artefact->summary),
            ...array_map(
                static fn (string $url) => new Content\ImageUrl($url),
                $images,
            ),
        );
    }

    public function getMetadata(): Metadata
    {
        $metadata = new Metadata();
        $metadata->setText($this->artefact->summary);

        return $metadata;
    }
}
