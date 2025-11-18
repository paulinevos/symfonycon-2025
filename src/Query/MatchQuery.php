<?php

namespace App\Query;

use Symfony\AI\Platform\Message\Content\Image;

final readonly class MatchQuery
{
    /**
     * @var Image[]
     */
    public array $images;
    public function __construct(public ?string $prompt, ?string $imageData)
    {
        $this->images = $imageData ? [Image::fromDataUrl($imageData)] : [];
    }
}
