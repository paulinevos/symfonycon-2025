<?php

namespace App\Console;

use App\Document\Artefact;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Query\Builder;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\AI\Store\Document\VectorizerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'ai:store:vectorize',
    description: 'Vectorize artefacts that do not have embeddings yet',
)]
final class VectorizeCommand extends Command
{
    public function __construct(
        private readonly DocumentManager $dm,
        private readonly VectorizerInterface $vectorizer,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $verbosityLevelMap = [
            LogLevel::NOTICE => OutputInterface::VERBOSITY_NORMAL,
            LogLevel::INFO   => OutputInterface::VERBOSITY_NORMAL,
        ];

        $qb = $this->dm->createQueryBuilder(Artefact::class);

        $logger = new ConsoleLogger($output, $verbosityLevelMap);

        $artefacts = $qb->field('embeddingVector')->equals(null)->getQuery()->execute();

        $chunkSize = 500;
        $counter = 0;
        $chunk = [];

        $logger->info('Starting vectorizer: ' . count($artefacts), ['total_documents' => count($artefacts)]);

        foreach ($artefacts as $document) {
            $chunk[] = $document;
            ++$counter;

            if ($chunkSize === \count($chunk)) {
                $logger->info("Processing chunk, at {$counter}", ['processed_documents' => $counter]);
                $this->vectorizeChunk($chunk, $qb);

                $chunk = [];
            }
        }

        if ([] !== $chunk) {
            $this->vectorizeChunk($chunk, $qb);
        }

        $logger->info('Document processing completed', ['total_documents' => $counter]);
        $this->dm->flush();

        return 0;
    }

    private function vectorizeChunk(array $chunk, Builder $qb): void
    {

        $vectorDocuments = $this->vectorizer->vectorize($chunk);

        foreach ($vectorDocuments as $document) {
            $qb->findAndUpdate()
                ->field('id')->equals($document->id)
                ->field('embeddingVector')->set($document->vector)
                ->getQuery()
                ->execute();
        }
    }
}
