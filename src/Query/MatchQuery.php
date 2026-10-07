<?php

namespace App\Query;

use Symfony\AI\Platform\Message\Content\Image;

final readonly class MatchQuery
{
    private const MAX_MEGABYTES = 20;

    public function __construct(public ?string $prompt, public ?Image $image)
    {
        if (null === $this->prompt && null === $this->image) {
            throw new \InvalidArgumentException('A match query needs a description, an image, or both.');
        }
    }

    public static function fromRequestData(?string $prompt, ?string $imageData): self
    {
        $prompt = null === $prompt ? null : trim($prompt);

        return new self(
            '' === $prompt ? null : $prompt,
            null === $imageData || '' === $imageData ? null : Image::fromDataUrl($imageData),
        );
    }

    public function exceedsSizeLimit(): bool
    {
        if (null === $this->image) {
            return false;
        }

        return strlen($this->image->asBinary()) >= self::MAX_MEGABYTES * 1024 * 1024;
    }
}
