<?php

namespace App\Reranker;

final readonly class Candidate
{
    private const LABEL_WIDTH = 40;

    public function __construct(public string $summary)
    {
    }

    public function label(): string
    {
        return mb_strimwidth(str_replace("\n", ' ', $this->summary), 0, self::LABEL_WIDTH, '…');
    }
}
