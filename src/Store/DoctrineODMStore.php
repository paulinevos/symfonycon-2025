<?php

namespace App\Store;

use App\Document\Artefact;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\MongoDBException;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\StoreInterface;

class DoctrineODMStore implements StoreInterface
{
    /**
     * @throws MongoDBException
     */
    public function __construct(private readonly DocumentManager $dm, private readonly string $documentClass)
    {
    }

    public function add(VectorDocument ...$documents): void
    {
        foreach ($documents as $document) {
            // ToDo: populate embedding field by reading vector search index metadata
            /** @var Artefact $doc */
            $doc = $this->dm->getRepository($this->documentClass)->findOneBy(['id' => $document->id]);

            $doc->embeddingVector = $document->vector->getData();
            $this->dm->persist($doc);
        }

        $this->dm->flush();
    }

    public function query(Vector $vector, array $options = []): array
    {
        return [];
    }
}
