<?php

namespace App\Document;

use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;
use Doctrine\ODM\MongoDB\Types\Type;

/**
 * An artefact together with how well it matched, kept in rank order.
 *
 * The score comes back from $vectorSearch as $meta: vectorSearchScore and used
 * to be thrown away the moment a MatchCandidate was unwrapped. Persisting it
 * next to the reference is what lets the match page show its working.
 */
#[ODM\EmbeddedDocument]
class ScoredMatch
{
    public function __construct(
        #[ODM\ReferenceOne(targetDocument: Artefact::class)]
        public readonly Artefact $artefact,
        #[ODM\Field(type: Type::FLOAT)]
        public readonly float $score,
        #[ODM\Field(type: Type::INT)]
        public readonly int $rank,
    ) {
    }
}
