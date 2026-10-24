<?php

namespace App\Console;

use App\Document\Artefact;
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
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Ask the index for a photograph it already holds.
 *
 * This is the visual path working as designed: the query image is one the
 * corpus was built from, so the artefact it belongs to should come back first.
 * Whatever this scores is the ceiling for image-to-image matching — a number
 * to hold the semantic results against, not a measure of the app.
 */
#[AsCommand(
    name: 'app:benchmark:self-image',
    description: 'Query the index with photographs it already holds and see where each lands',
)]
final class SelfImageCommand extends Command
{
    private const DEPTH = 10;

    public function __construct(
        private readonly DocumentManager $dm,
        private readonly VectorizerInterface $vectorizer,
        private readonly HttpClientInterface $httpClient,
        private readonly string $cacheDirectory = '',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('sample', 's', InputOption::VALUE_REQUIRED, 'How many artefacts to ask', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $artefacts = $this->sample((int) $input->getOption('sample'));

        $rows = [];
        $atOne = 0;
        $missed = 0;

        foreach ($artefacts as $artefact) {
            $rank = $this->rankOfSelf($artefact);
            $atOne += 1 === $rank ? 1 : 0;
            $missed += null === $rank ? 1 : 0;
            $rows[] = [$artefact['findId'], null === $rank ? 'outside top ' . self::DEPTH : (string) $rank];
        }

        $io->table(['artefact', 'rank of its own photo'], array_slice($rows, 0, 20));

        $io->table(['outcome', 'count'], [
            ['rank 1', sprintf('%d/%d', $atOne, count($artefacts))],
            ['within top ' . self::DEPTH, sprintf('%d/%d', count($artefacts) - $missed, count($artefacts))],
        ]);

        return Command::SUCCESS;
    }

    /**
     * @return list<array{findId: string, imageUrl: string}>
     */
    private function sample(int $size): array
    {
        $rows = $this->dm->getDocumentCollection(Artefact::class)->find(
            ['imageUrls.0' => ['$exists' => true], 'embeddingVector' => ['$ne' => null]],
            [
                'projection' => ['findId' => 1, 'imageUrls' => 1],
                'sort' => ['findId' => 1],
                'limit' => $size,
                'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'],
            ],
        );

        $sample = [];
        foreach ($rows as $row) {
            $sample[] = ['findId' => $row['findId'], 'imageUrl' => array_values((array) $row['imageUrls'])[0]];
        }

        return $sample;
    }

    /**
     * @param array{findId: string, imageUrl: string} $artefact
     */
    private function rankOfSelf(array $artefact): ?int
    {
        $vectorized = $this->vectorizer->vectorize(
            RequestedObject::new('', Image::fromFile($this->imageOf($artefact['imageUrl']))),
        );

        $builder = $this->dm->getRepository(Artefact::class)
            ->createAggregationBuilder();

        $builder
            ->vectorSearch()
            ->limit(self::DEPTH)
            ->numCandidates(self::DEPTH * 20)
            ->index('default')
            ->path('embeddingVector')
            ->queryVector($vectorized->getVector()->getData())
            ->project()
            ->field('_id')->expression(0)
            ->field('findId')->expression(1);

        $rank = 0;
        foreach ($builder->getAggregation()->execute() as $match) {
            ++$rank;
            if ($match['findId'] === $artefact['findId']) {
                return $rank;
            }
        }

        return null;
    }

    private function imageOf(string $url): string
    {
        if ('' === $this->cacheDirectory) {
            return $url;
        }

        if (!is_dir($this->cacheDirectory)) {
            mkdir($this->cacheDirectory, 0o777, true);
        }

        $path = $this->cacheDirectory . '/' . md5($url) . '.png';
        if (!is_file($path)) {
            file_put_contents($path, $this->httpClient->request('GET', $url)->getContent());
        }

        return $path;
    }
}
