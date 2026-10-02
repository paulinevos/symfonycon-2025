<?php

namespace App\Console;

use App\Document\Artefact;
use App\Document\YearRange;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Copies artefact metadata from the collection the full demo already populated,
 * so a slice does not have to re-download the CSV and re-probe every image URL
 * over HTTP. Embeddings are deliberately left null for the vectorizer to fill.
 */
#[AsCommand(
    name: 'app:seed-from-main',
    description: 'Copy artefact metadata from amsterdam_artefacts into this slice collection',
)]
final class SeedFromMainCommand extends Command
{
    private const SOURCE_COLLECTION = 'amsterdam_artefacts';

    public function __construct(private readonly DocumentManager $dm)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $target = $this->dm->getDocumentCollection(Artefact::class);

        if (0 < $target->countDocuments()) {
            $io->warning(sprintf('%s already holds documents; nothing copied.', $target->getCollectionName()));

            return Command::SUCCESS;
        }

        $source = $this->dm->getClient()
            ->selectDatabase($this->dm->getConfiguration()->getDefaultDB())
            ->selectCollection(self::SOURCE_COLLECTION);

        $copied = 0;

        $options = [
            'projection' => ['findId' => 1, 'summary' => 1, 'dated' => 1, 'imageUrls' => 1],
            'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'],
        ];

        foreach ($source->find([], $options) as $row) {
            $this->dm->persist($this->toArtefact($row));
            ++$copied;

            if (0 === $copied % 1000) {
                $this->dm->flush();
                $this->dm->clear();
                $io->writeln("Copied {$copied}...");
            }
        }

        $this->dm->flush();

        $io->success(sprintf('Copied %d artefacts into %s.', $copied, $target->getCollectionName()));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toArtefact(array $row): Artefact
    {
        $dated = $row['dated'] ?? null;

        return new Artefact(
            findId: $row['findId'],
            summary: $row['summary'],
            dated: null === $dated ? null : new YearRange((int) $dated['start'], (int) $dated['end']),
            imageUrls: array_values((array) ($row['imageUrls'] ?? [])),
        );
    }
}
