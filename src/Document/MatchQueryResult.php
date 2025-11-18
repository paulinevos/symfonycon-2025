<?php

namespace App\Document;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;
use Doctrine\ODM\MongoDB\Types\Type;
use Symfony\Component\Uid\UuidV7;

#[ODM\Document]
final class MatchQueryResult
{
    #[ODM\Id(type: Type::UUID, strategy: 'AUTO')]
    public readonly ?UuidV7 $id;

    #[ODM\ReferenceMany(targetDocument: Artefact::class)]
    public Collection $matches;

    #[ODM\Index(expireAfterSeconds: 3600)]
    #[ODM\Field(type: 'date')]
    public readonly \DateTimeInterface $createdAt;

    public function __construct(public string $objectDescription, MatchCandidate ...$matches)
    {
        $this->matches = new ArrayCollection(array_map(fn (MatchCandidate $candidate) => $candidate->artefact, $matches));
        $this->createdAt = new \DateTimeImmutable();
    }
}
