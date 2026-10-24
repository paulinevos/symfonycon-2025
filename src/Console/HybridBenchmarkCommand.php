<?php

namespace App\Console;

use App\Document\Artefact;
use App\ValueObject\RequestedObject;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Store\Document\VectorizerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Three retrieval methods per photo query, and their weighted rank fusion.
 *
 * Emits one row per (query, method, rank, findId) so the Python scorer can
 * judge the legs and the fused list against the same annotations. The text leg
 * deliberately uses only the object name: for "ballpoint pen" it will never
 * find "stylus", which is the point of showing the other two legs.
 */
#[AsCommand(
    name: 'app:benchmark:hybrid',
    description: 'Run multimodal, semantic and text legs plus their fusion for a photo judgement sheet',
)]
final class HybridBenchmarkCommand extends Command
{
    private const DEPTH = 30;

    public function __construct(
        private readonly DocumentManager $dm,
        private readonly VectorizerInterface $vectorizer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('sheet', InputArgument::REQUIRED, 'The annotated photo judgement CSV')
            ->addArgument('photos', InputArgument::REQUIRED, 'Directory holding the query photographs')
            ->addOption('emit', null, InputOption::VALUE_REQUIRED, 'Write the orders to this CSV', 'hybrid-orders.csv');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $photos = rtrim((string) $input->getArgument('photos'), '/');
        $emit = (string) $input->getOption('emit');

        $sheet = array_map('str_getcsv', file((string) $input->getArgument('sheet'), \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES));
        $header = array_shift($sheet);
        $queries = [];
        foreach ($sheet as $row) {
            $queries[array_combine($header, $row)['query']] = true;
        }

        $collection = $this->dm->getDocumentCollection(Artefact::class);
        $handle = fopen($emit, 'w');
        fputcsv($handle, ['query', 'method', 'rank', 'findId']);

        foreach (array_keys($queries) as $query) {
            $object = str_replace('-', ' ', $query);
            $image = Image::fromFile($photos . '/' . $query . '.png');

            $pipelines = [
                'multimodal' => [$this->vectorSearch($this->vector('This but from a different era', $image))],
                'semantic' => [$this->vectorSearch($this->vector('show me an old ' . $object, null))],
                'text' => [
                    ['$match' => ['$text' => ['$search' => $object]]],
                    ['$sort' => ['score' => ['$meta' => 'textScore']]],
                    ['$limit' => self::DEPTH],
                ],
            ];

            foreach ($pipelines as $method => $stages) {
                $this->emit($handle, $collection, $query, $method, $stages);
            }

            $this->emit($handle, $collection, $query, 'fused', [
                ['$rankFusion' => [
                    'input' => ['pipelines' => $pipelines],
                    'combination' => ['weights' => ['multimodal' => 1, 'semantic' => 1, 'text' => 3]],
                ]],
                ['$limit' => self::DEPTH],
            ]);

            $io->writeln($query);
        }

        fclose($handle);
        $io->success("orders -> {$emit}");

        return Command::SUCCESS;
    }

    /**
     * @param resource                   $handle
     * @param list<array<string, mixed>> $stages
     */
    private function emit($handle, $collection, string $query, string $method, array $stages): void
    {
        $rows = $collection->aggregate(
            [...$stages, ['$project' => ['_id' => 0, 'findId' => 1]]],
            ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']],
        );

        $rank = 0;
        foreach ($rows as $row) {
            fputcsv($handle, [$query, $method, ++$rank, $row['findId']]);
        }
    }

    /**
     * @param list<float> $vector
     *
     * @return array<string, mixed>
     */
    private function vectorSearch(array $vector): array
    {
        return [
            '$vectorSearch' => [
                'index' => 'default',
                'path' => 'embeddingVector',
                'queryVector' => $vector,
                'numCandidates' => self::DEPTH * 20,
                'limit' => self::DEPTH,
            ],
        ];
    }

    /**
     * @return list<float>
     */
    private function vector(string $text, ?Image $image): array
    {
        return $this->vectorizer
            ->vectorize(RequestedObject::new($text, $image))
            ->getVector()
            ->getData();
    }
}
