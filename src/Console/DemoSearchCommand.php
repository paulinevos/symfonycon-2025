<?php

namespace App\Console;

use App\Document\Artefact;
use App\Document\MatchCandidate;
use App\ValueObject\RequestedObject;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Store\Document\VectorizerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:demo:search',
    description: 'Search by text, by image, or by both, and print the ranked matches',
)]
final class DemoSearchCommand extends Command
{
    public function __construct(
        private readonly DocumentManager $dm,
        private readonly VectorizerInterface $vectorizer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('text', 't', InputOption::VALUE_REQUIRED, 'Description to search for')
            ->addOption('image', 'i', InputOption::VALUE_REQUIRED, 'Path to an image to search with')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'How many matches to show', '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $text = $input->getOption('text');
        $imagePath = $input->getOption('image');

        if (null === $text && null === $imagePath) {
            $io->error('Give --text, --image, or both.');

            return Command::INVALID;
        }

        if (null !== $imagePath && !is_file($imagePath)) {
            $io->error("No such image: {$imagePath}");

            return Command::INVALID;
        }

        $io->section($this->describeQuery($text, $imagePath));

        $startedAt = microtime(true);
        $vectorized = $this->vectorizer->vectorize(RequestedObject::new(
            $text ?? '',
            null === $imagePath ? null : Image::fromFile($imagePath),
        ));
        $embeddedAt = microtime(true);

        $rows = [];
        $rank = 0;

        foreach ($this->search($vectorized->getVector()->getData(), (int) $input->getOption('limit')) as $candidate) {
            $rows[] = [
                ++$rank,
                sprintf('%.4f', $candidate->score),
                $candidate->artefact->findId,
                mb_strimwidth(str_replace("\n", ' ', $candidate->artefact->summary), 0, 64, '…'),
            ];
        }

        $io->table(['#', 'score', 'find ID', 'summary'], $rows);
        $io->writeln(sprintf(
            '  embedding %.0f ms · search %.0f ms',
            ($embeddedAt - $startedAt) * 1000,
            (microtime(true) - $embeddedAt) * 1000,
        ));

        return Command::SUCCESS;
    }

    /**
     * @param list<float> $queryVector
     *
     * @return iterable<MatchCandidate>
     */
    private function search(array $queryVector, int $limit): iterable
    {
        $builder = $this->dm->getRepository(Artefact::class)
            ->createAggregationBuilder()
            ->hydrate(MatchCandidate::class);

        $builder
            ->vectorSearch()
            ->limit($limit)
            ->numCandidates($limit * 20)
            ->index('default')
            ->path('embeddingVector')
            ->queryVector($queryVector)
            ->project()
            ->field('_id')->expression(0)
            ->field('artefact')->expression('$$ROOT')
            ->field('score')->meta('vectorSearchScore');

        return $builder->getAggregation()->execute();
    }

    private function describeQuery(?string $text, ?string $imagePath): string
    {
        return match (true) {
            null === $imagePath => sprintf('Text query: "%s"', $text),
            null === $text => sprintf('Image query: %s', basename($imagePath)),
            default => sprintf('Image %s plus text "%s"', basename($imagePath), $text),
        };
    }
}
