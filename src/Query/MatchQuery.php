<?php

namespace App\Query;

final readonly class MatchQuery
{
    public function __construct(public string $prompt)
    {
    }
}
