<?php

namespace App\Console;

use App\Document\Artefact;
use App\Evaluation\JudgedQuery;
use App\Evaluation\JudgedQuerySet;
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
 * The real use case: a photograph of a modern object, against the chunked index.
 *
 * Three ways to retrieve, scored against the human judgements:
 *
 *   image chunks     the photograph meets photographs
 *   summary chunks   the photograph meets catalogue text, cross-modally
 *   fused            both legs, combined by rank
 *
 * The judgements were made against the old blended shortlist, so this is a
 * recall test rather than a ranking one: did the right artefact come back at
 * all, and how near the top.
 */
#[AsCommand(
    name: 'app:benchmark:photo-chunked',
    description: 'Retrieve modern-object photos from the chunked index and score against the judgements',
)]
final class PhotoChunkCommand extends Command
{
    private const DEPTH = 20;
    private const CHUNKS = 'artefact_chunks';

    public function __construct(
        private readonly DocumentManager $dm,
        private readonly VectorizerInterface $vectorizer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('sheet', InputArgument::REQUIRED, 'The annotated photo judgements CSV')
            ->addArgument('photos', InputArgument::REQUIRED, 'Directory holding the query photographs')
            ->addOption('emit', null, InputOption::VALUE_REQUIRED, 'Write the fused shortlist to this CSV');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $set = JudgedQuerySet::fromCsv($input->getArgument('sheet'));
        $photos = rtrim($input->getArgument('photos'), '/');

        $scorable = array_filter($set->scorable(), fn (JudgedQuery $q): bool => $q->hasAMatch());
        $emit = $input->getOption('emit');
        $emitted = [];
        $names = ['image-chunks', 'summary-chunks', 'fused'];
        $mrr = array_fill_keys($names, 0.0);
        $top1 = array_fill_keys($names, 0);
        $rows = [];

        foreach ($scorable as $query) {
            $vector = $this->vectorizer
                ->vectorize(RequestedObject::new('', Image::fromFile(sprintf('%s/%s.png', $photos, $query->query))))
                ->getVector()
                ->getData();

            $orders = [
                'image-chunks' => $this->leg($vector, 'image'),
                'summary-chunks' => $this->leg($vector, 'summary'),
                'fused' => $this->fused($vector),
            ];

            $cells = [];
            foreach ($orders as $name => $order) {
                $rank = $query->rankOfFirstMatch($order);
                $mrr[$name] += null === $rank ? 0.0 : 1 / $rank;
                $top1[$name] += 1 === $rank ? 1 : 0;
                $cells[] = null === $rank ? 'miss' : (string) $rank;
            }
            $rows[] = [$query->query, ...$cells];

            if (null !== $emit) {
                foreach ($orders as $name => $order) {
                    $emitted[] = [$query->query, $name, $order];
                }
            }
        }

        $io->table(['photo', ...$names], $rows);
        $count = max(1, count($scorable));
        $io->table(['retrieval', 'top-1 match', 'MRR'], array_map(
            fn (string $name): array => [$name, sprintf('%d/%d', $top1[$name], count($scorable)), sprintf('%.3f', $mrr[$name] / $count)],
            $names,
        ));

        if (null !== $emit) {
            $handle = fopen($emit, 'w');
            fputcsv($handle, ['query', 'method', 'rank', 'findId']);
            foreach ($emitted as [$query, $method, $order]) {
                foreach ($order as $position => $findId) {
                    fputcsv($handle, [$query, $method, $position + 1, $findId]);
                }
            }
            fclose($handle);
            $io->writeln("fused shortlists -> {$emit}");
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<float> $vector
     *
     * @return list<string> find IDs, best first
     */
    private function leg(array $vector, string $kind): array
    {
        return $this->findIds([
            ['$vectorSearch' => [
                'index' => 'default',
                'path' => 'embeddingVector',
                'queryVector' => $vector,
                'numCandidates' => self::DEPTH * 20,
                'limit' => self::DEPTH,
                'filter' => ['kind' => $kind],
            ]],
            ['$project' => ['_id' => 0, 'findId' => 1]],
        ]);
    }

    /**
     * @param list<float> $vector
     *
     * @return list<string> find IDs, best first
     */
    private function fused(array $vector): array
    {
        return $this->findIds([
            ['$rankFusion' => ['input' => ['pipelines' => [
                'images' => $this->legDefinition($vector, 'image'),
                'summaries' => $this->legDefinition($vector, 'summary'),
            ]]]],
            ['$group' => ['_id' => '$findId', 'score' => ['$max' => ['$meta' => 'score']]]],
            ['$sort' => ['score' => -1]],
            ['$limit' => self::DEPTH],
        ], '_id');
    }

    /**
     * @param list<float> $vector
     *
     * @return list<array<string, mixed>>
     */
    private function legDefinition(array $vector, string $kind): array
    {
        return [
            ['$vectorSearch' => [
                'index' => 'default',
                'path' => 'embeddingVector',
                'queryVector' => $vector,
                'numCandidates' => self::DEPTH * 20,
                'limit' => self::DEPTH * 3,
                'filter' => ['kind' => $kind],
            ]],
        ];
    }

    /**
     * @param list<array<string, mixed>> $pipeline
     *
     * @return list<string>
     */
    private function findIds(array $pipeline, string $key = 'findId'): array
    {
        $rows = $this->dm->getDocumentDatabase(Artefact::class)
            ->selectCollection(self::CHUNKS)
            ->aggregate($pipeline, ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]);

        $findIds = [];
        foreach ($rows as $row) {
            // Image chunks are keyed "<artefact>-01"; the artefact is the base.
            $findIds[] = preg_replace('/-\d+$/', '', (string) $row[$key]);
        }

        return $findIds;
    }
}
