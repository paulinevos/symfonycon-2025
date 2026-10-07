<?php

namespace App\Console;

use App\Document\Artefact;
use Doctrine\ODM\MongoDB\DocumentManager;
use App\Evaluation\FirstImageOnly;
use Symfony\AI\Store\Document\VectorizerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Embeds each artefact from its summary and its FIRST image only.
 *
 * Holding the second image back is what makes the evaluation honest: the query
 * images in the ground truth set are then genuinely unseen by the index, so a
 * hit means the pipeline generalised rather than recognised a vector it had
 * already stored.
 */
#[AsCommand(
    name: 'app:index-first-image',
    description: 'Vectorize artefacts from the summary plus their first image, holding the second back',
)]
final class IndexFirstImageCommand extends Command
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
        $this->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Only embed this many artefacts');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = $input->getOption('limit');

        $pending = $this->pending(null === $limit ? null : (int) $limit);
        $counter = 0;
        $chunk = [];

        foreach ($pending as $artefact) {
            $chunk[] = $artefact;
            ++$counter;

            if (self::CHUNK_SIZE === count($chunk)) {
                $this->embed($chunk);
                $io->writeln("  embedded {$counter}...");
                $chunk = [];
            }
        }

        if ([] !== $chunk) {
            $this->embed($chunk);
        }

        $io->success("Embedded {$counter} artefacts from summary + first image.");

        return Command::SUCCESS;
    }

    /**
     * @return list<Artefact>
     */
    private function pending(?int $limit): array
    {
        $builder = $this->dm->createQueryBuilder(Artefact::class)
            ->field('embeddingVector')->equals(null);

        if (null !== $limit) {
            $builder->limit($limit);
        }

        return iterator_to_array($builder->getQuery()->execute());
    }

    /**
     * @param list<Artefact> $chunk
     */
    private function embed(array $chunk): void
    {
        $contents = array_map(
            static fn (Artefact $a) => new FirstImageOnly($a),
            $chunk,
        );

        foreach ($this->vectorizer->vectorize($contents) as $document) {
            $this->dm->createQueryBuilder(Artefact::class)
                ->findAndUpdate()
                ->field('id')->equals($document->getId())
                ->field('embeddingVector')->set($document->getVector()->getData())
                ->getQuery()
                ->execute();
        }
    }
}
