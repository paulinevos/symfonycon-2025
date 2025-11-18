<?php

namespace App\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;
#[ODM\QueryResultDocument]
class MatchCandidate
{
    #[ODM\EmbedOne(targetDocument: Artefact::class)]
    public Artefact $artefact;

    #[ODM\Field(type: 'float')]
    public float $score;
}
