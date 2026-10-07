<?php

namespace App\Console;

use App\Document\Artefact;
use App\Document\ArtefactChunk;
use App\Document\ChunkKind;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\AI\Store\Document\EmbeddableDocumentInterface;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorizerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Cuts every artefact into a summary chunk plus one chunk per held-in image,
 * and embeds each chunk on its own.
 *
 * Only the FIRST image becomes a chunk, for the same reason the baseline only
 * indexes the first image: the second is the question, and indexing it would
 * turn the evaluation into a lookup.
 */
#[AsCommand(
    name: 'app:chunk-and-vectorize',
    description: 'Split artefacts into summary and image chunks, and embed each chunk separately',
)]
final class ChunkAndVectorizeCommand extends Command
{
    private const CHUNK_SIZE = 100;

    public function __construct(
        private readonly DocumentManager $dm,
        private readonly VectorizerInterface $vectorizer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Only process this many artefacts');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $created = $this->cutChunks($io, $input->getOption('limit'));
        $embedded = $this->embedChunks($io);

        $io->success(sprintf('%d chunks exist, %d newly embedded.', $created, $embedded));

        return Command::SUCCESS;
    }

    private function cutChunks(SymfonyStyle $io, ?string $limit): int
    {
        $chunks = $this->dm->getDocumentCollection(ArtefactChunk::class);

        if (0 < $chunks->countDocuments()) {
            $io->writeln('  chunks already cut, skipping');

            return (int) $chunks->countDocuments();
        }

        $builder = $this->dm->createQueryBuilder(Artefact::class)
            ->field('embeddingVector')->notEqual(null);

        if (null !== $limit) {
            $builder->limit((int) $limit);
        }

        $created = 0;

        foreach ($builder->getQuery()->execute() as $artefact) {
            foreach ($this->chunksOf($artefact) as $chunk) {
                $this->dm->persist($chunk);
                ++$created;
            }

            if (0 === $created % 500) {
                $this->dm->flush();
                $this->dm->clear();
                $io->writeln("  cut {$created} chunks...");
            }
        }

        $this->dm->flush();
        $this->dm->clear();

        return $created;
    }

    /**
     * @return list<ArtefactChunk>
     */
    private function chunksOf(Artefact $artefact): array
    {
        $chunks = [ArtefactChunk::forSummary($artefact->findId, $artefact->summary)];

        foreach (array_slice($artefact->imageUrls, 0, 1) as $imageUrl) {
            $chunks[] = ArtefactChunk::forImage($artefact->findId, $artefact->summary, $imageUrl);
        }

        return $chunks;
    }

    private function embedChunks(SymfonyStyle $io): int
    {
        $embedded = 0;
        $batch = [];

        $pending = $this->dm->createQueryBuilder(ArtefactChunk::class)
            ->field('embeddingVector')->equals(null)
            ->getQuery()
            ->execute();

        foreach ($pending as $chunk) {
            $batch[] = $chunk;

            if (self::CHUNK_SIZE === count($batch)) {
                $this->embed($batch);
                $embedded += count($batch);
                $io->writeln("  embedded {$embedded} chunks...");
                $batch = [];
            }
        }

        if ([] !== $batch) {
            $this->embed($batch);
            $embedded += count($batch);
        }

        return $embedded;
    }

    /**
     * @param list<ArtefactChunk> $batch
     */
    private function embed(array $batch): void
    {
        $embeddable = array_map(
            static fn (ArtefactChunk $chunk) => new class($chunk) implements EmbeddableDocumentInterface {
                public function __construct(private readonly ArtefactChunk $chunk)
                {
                }

                public function getId(): int|string
                {
                    return (string) $this->chunk->id;
                }

                public function getContent(): string|object
                {
                    return ChunkKind::from($this->chunk->kind)->contentFor($this->chunk);
                }

                public function getMetadata(): Metadata
                {
                    $metadata = new Metadata();
                    $metadata->setText($this->chunk->summary);

                    return $metadata;
                }
            },
            $batch,
        );

        foreach ($this->vectorizer->vectorize($embeddable) as $document) {
            $this->dm->createQueryBuilder(ArtefactChunk::class)
                ->findAndUpdate()
                ->field('id')->equals($document->getId())
                ->field('embeddingVector')->set($document->getVector()->getData())
                ->getQuery()
                ->execute();
        }
    }
}
