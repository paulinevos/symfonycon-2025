<?php

namespace App\Document;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;
use Doctrine\ODM\MongoDB\Types\Type;
use Symfony\Component\Uid\UuidV7;

#[ODM\Document]
class MatchQueryResult
{
    #[ODM\Id(type: Type::UUID, strategy: 'AUTO')]
    public readonly ?UuidV7 $id;

    /**
     * @var Collection<int, ScoredMatch> in descending score order
     */
    #[ODM\EmbedMany(targetDocument: ScoredMatch::class)]
    public Collection $matches;

    #[ODM\Index(expireAfterSeconds: 3600)]
    #[ODM\Field(type: 'date')]
    public readonly \DateTimeInterface $createdAt;

    public function __construct(
        #[ODM\Field(type: Type::STRING)]
        public string $objectDescription,
        MatchCandidate ...$candidates
    ) {
        $this->matches = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();

        $rank = 0;
        foreach ($candidates as $candidate) {
            $this->matches->add(new ScoredMatch($candidate->artefact, $candidate->score, ++$rank));
        }
    }

    /**
     * $vectorSearch already returns its results best first, so the closest
     * match is simply the first one. The original code reached for the last
     * element instead and rendered the tenth-best as the headline result.
     */
    public function closest(): ?ScoredMatch
    {
        return $this->matches->first() ?: null;
    }

    /**
     * @return list<ScoredMatch>
     */
    public function runnersUp(): array
    {
        return array_slice($this->matches->toArray(), 1);
    }
}
