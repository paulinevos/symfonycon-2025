<?php

namespace App\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;

#[ODM\EmbeddedDocument]
final readonly class YearRange
{
    public function __construct(
        #[ODM\Field(type: 'int64')]
        public int $start,
        #[ODM\Field(type: 'int64')]
        public int $end,
    )
    {
    }
}
