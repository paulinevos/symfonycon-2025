<?php

namespace App\Console;

use App\Document\Artefact;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\AI\Store\Document\VectorizerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'ai:store:vectorize',
    description: 'Vectorize artefacts that do not have embeddings yet',
)]
final class VectorizeCommand extends Command
{
    private const CHUNK_SIZE = 500;

    public function __construct(
        private readonly DocumentManager $dm,
        private readonly VectorizerInterface $vectorizer,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $counter = 0;
        $chunk = [];

        foreach ($this->pendingArtefacts() as $artefact) {
            $chunk[] = $artefact;
            ++$counter;

            if (self::CHUNK_SIZE === \count($chunk)) {
                $this->vectorizeChunk($chunk);
                $io->writeln("Vectorized {$counter} artefacts...");
                $chunk = [];
            }
        }

        if ([] !== $chunk) {
            $this->vectorizeChunk($chunk);
        }

        $io->success("Vectorized {$counter} artefacts.");

        return Command::SUCCESS;
    }

    /**
     * @return iterable<Artefact>
     */
    private function pendingArtefacts(): iterable
    {
        return $this->dm->createQueryBuilder(Artefact::class)
            ->field('embeddingVector')->equals(null)
            ->getQuery()
            ->execute();
    }

    /**
     * @param list<Artefact> $chunk
     */
    private function vectorizeChunk(array $chunk): void
    {
        foreach ($this->vectorizer->vectorize($chunk) as $document) {
            $this->dm->createQueryBuilder(Artefact::class)
                ->findAndUpdate()
                ->field('id')->equals($document->getId())
                ->field('embeddingVector')->set($document->getVector()->getData())
                ->getQuery()
                ->execute();
        }
    }
}
