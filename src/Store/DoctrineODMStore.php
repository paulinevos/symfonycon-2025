<?php

namespace App\Store;

use App\Document\Artefact;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\Document\VectorDocumentInterface;
use Symfony\AI\Store\Exception\InvalidArgumentException;
use Symfony\AI\Store\Exception\UnsupportedQueryTypeException;
use Symfony\AI\Store\ManagedStoreInterface;
use Symfony\AI\Store\Query\QueryInterface;
use Symfony\AI\Store\Query\VectorQuery;
use Symfony\AI\Store\StoreInterface;

class DoctrineODMStore implements StoreInterface, ManagedStoreInterface
{
    private const SEARCH_INDEX = 'default';
    private const EMBEDDING_PATH = 'embeddingVector';

    public function __construct(private readonly DocumentManager $dm, private readonly string $documentClass)
    {
    }

    public function add(VectorDocumentInterface|array $documents): void
    {
        foreach (is_array($documents) ? $documents : [$documents] as $document) {
            $this->storeVector($document);
        }

        $this->dm->flush();
    }

    public function remove(string|array $ids, array $options = []): void
    {
        $this->dm->createQueryBuilder($this->documentClass)
            ->updateMany()
            ->field('id')->in((array) $ids)
            ->field(self::EMBEDDING_PATH)->unsetField()
            ->getQuery()
            ->execute();
    }

    public function clear(array $options = []): void
    {
        $this->dm->createQueryBuilder($this->documentClass)
            ->updateMany()
            ->field(self::EMBEDDING_PATH)->unsetField()
            ->getQuery()
            ->execute();
    }

    public function query(QueryInterface $query, array $options = []): iterable
    {
        if (!$query instanceof VectorQuery) {
            throw new UnsupportedQueryTypeException($query::class, $this);
        }

        $builder = $this->dm->getRepository($this->documentClass)
            ->createAggregationBuilder()
            ->hydrate($this->documentClass);
        $builder
            ->vectorSearch()
            ->limit($options['limit'] ?? 10)
            ->numCandidates($options['num_candidates'] ?? 200)
            ->index(self::SEARCH_INDEX)
            ->path(self::EMBEDDING_PATH)
            ->queryVector($query->getVector()->getData());

        foreach ($builder->getAggregation()->execute() as $artefact) {
            yield $this->toVectorDocument($artefact);
        }
    }

    public function supports(string $queryClass): bool
    {
        return VectorQuery::class === $queryClass;
    }

    public function count(): int
    {
        return $this->dm->createQueryBuilder($this->documentClass)
            ->field(self::EMBEDDING_PATH)->notEqual(null)
            ->count()
            ->getQuery()
            ->execute();
    }

    public function setup(array $options = []): void
    {
        $schemaManager = $this->dm->getSchemaManager();
        $schemaManager->ensureDocumentIndexes($this->documentClass);
        $schemaManager->createDocumentSearchIndexes($this->documentClass);
    }

    public function drop(array $options = []): void
    {
        $schemaManager = $this->dm->getSchemaManager();
        $schemaManager->deleteDocumentSearchIndexes($this->documentClass);
        $schemaManager->deleteDocumentIndexes($this->documentClass);
    }

    private function storeVector(VectorDocumentInterface $document): void
    {
        /** @var Artefact|null $artefact */
        $artefact = $this->dm->getRepository($this->documentClass)->find($document->getId());

        if (null === $artefact) {
            throw new InvalidArgumentException(sprintf('No "%s" found with id "%s".', $this->documentClass, $document->getId()));
        }

        $artefact->embeddingVector = $document->getVector()->getData();
        $this->dm->persist($artefact);
    }

    private function toVectorDocument(Artefact $artefact): VectorDocument
    {
        $metadata = new Metadata();
        $metadata->setText($artefact->summary);

        return new VectorDocument(
            (string) $artefact->id,
            new Vector($artefact->embeddingVector),
            $metadata,
        );
    }
}
