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
 * The same self-image question, asked of the chunked index.
 *
 * If one blended vector per artefact is why an exact photograph fails to find
 * itself, then separating the signals should fix it: the query photograph now
 * meets a chunk that holds only a photograph, rather than a photograph
 * averaged with a sentence. This is the number that says whether that
 * explanation is right.
 */
#[AsCommand(
    name: 'app:benchmark:self-image-chunked',
    description: 'Query the chunked index with photographs it already holds',
)]
final class ChunkedSelfImageCommand extends Command
{
    private const DEPTH = 10;
    private const CHUNKS = 'artefact_chunks';

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
        $this
            ->addOption('sample', 's', InputOption::VALUE_REQUIRED, 'How many artefacts to ask', '50')
            ->addOption('kind', null, InputOption::VALUE_REQUIRED, 'image or summary chunks', 'image');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $kind = (string) $input->getOption('kind');
        $artefacts = $this->sample((int) $input->getOption('sample'));

        $atOne = 0;
        $found = 0;
        $rows = [];

        foreach ($artefacts as $artefact) {
            $rank = $this->rankOfSelf($artefact, $kind);
            $atOne += 1 === $rank ? 1 : 0;
            $found += null === $rank ? 0 : 1;
            $rows[] = [$artefact['findId'], null === $rank ? 'outside top ' . self::DEPTH : (string) $rank];
        }

        $io->table(['artefact', "rank among {$kind} chunks"], array_slice($rows, 0, 20));
        $io->table(['outcome', 'count'], [
            ['rank 1', sprintf('%d/%d', $atOne, count($artefacts))],
            ['within top ' . self::DEPTH, sprintf('%d/%d', $found, count($artefacts))],
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
    private function rankOfSelf(array $artefact, string $kind): ?int
    {
        $vectorized = $this->vectorizer->vectorize(
            RequestedObject::new('', Image::fromFile($this->imageOf($artefact['imageUrl']))),
        );

        $rows = $this->dm->getDocumentDatabase(Artefact::class)
            ->selectCollection(self::CHUNKS)
            ->aggregate(
                [
                    ['$vectorSearch' => [
                        'index' => 'default',
                        'path' => 'embeddingVector',
                        'queryVector' => $vectorized->getVector()->getData(),
                        'numCandidates' => self::DEPTH * 20,
                        'limit' => self::DEPTH,
                        'filter' => ['kind' => $kind],
                    ]],
                    ['$project' => ['_id' => 0, 'findId' => 1]],
                ],
                ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']],
            );

        $rank = 0;
        foreach ($rows as $row) {
            ++$rank;
            // Image chunks are keyed "<artefact>-01"; the artefact itself is
            // what the chunk was cut from.
            if (str_starts_with((string) $row['findId'], $artefact['findId'])) {
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
