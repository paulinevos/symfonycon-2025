<?php

namespace App\Console;

use App\Document\Artefact;
use App\Evaluation\CaptionCache;
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
 * One artefact, one vector, several retrievals — fused by rank.
 *
 * The corpus keeps the blended vector it always had; what changes is that the
 * query is asked twice, once as a photograph and once as the words describing
 * it, and the two ranked lists are combined. The artefact stays the unit
 * throughout, so a result both retrievals agree on is reinforced as an
 * artefact rather than as a fragment of one.
 */
#[AsCommand(
    name: 'app:benchmark:photo-fusion',
    description: 'Fuse a photo retrieval and a caption retrieval over the blended corpus',
)]
final class PhotoFusionCommand extends Command
{
    private const DEPTH = 30;

    public function __construct(
        private readonly DocumentManager $dm,
        private readonly VectorizerInterface $vectorizer,
        private readonly CaptionCache $captions,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('sheet', InputArgument::REQUIRED, 'The annotated photo judgements CSV')
            ->addArgument('photos', InputArgument::REQUIRED, 'Directory holding the query photographs')
            ->addOption('emit', null, InputOption::VALUE_REQUIRED, 'Write the fused order to this CSV')
            ->addOption('legs', null, InputOption::VALUE_REQUIRED, 'Comma-separated legs: photo,caption,caption-4o,caption-function', 'photo,caption')
            ->addOption('caption-model', null, InputOption::VALUE_REQUIRED, 'Which model writes the description', 'gpt-4o-mini');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $photos = rtrim($input->getArgument('photos'), '/');
        $emit = $input->getOption('emit');

        $sheet = array_map('str_getcsv', file($input->getArgument('sheet'), \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES));
        $header = array_shift($sheet);
        $queries = [];
        foreach ($sheet as $row) {
            $record = array_combine($header, $row);
            $queries[$record['query']] = true;
        }

        $legs = array_filter(explode(',', (string) $input->getOption('legs')));
        $io->writeln('legs: ' . implode(', ', $legs));

        $emitted = [];
        foreach (array_keys($queries) as $query) {
            $path = sprintf('%s/%s.png', $photos, $query);
            $image = Image::fromFile($path);

            $vectors = [];
            foreach ($legs as $leg) {
                $vectors[$leg] = $this->vectorFor($leg, $image, $query, (string) $input->getOption('caption-model'));
            }

            $order = $this->fused($vectors);
            $emitted[] = [$query, $order];
        }

        if (null !== $emit) {
            $handle = fopen($emit, 'w');
            fputcsv($handle, ['query', 'rank', 'findId']);
            foreach ($emitted as [$query, $order]) {
                foreach ($order as $position => $findId) {
                    fputcsv($handle, [$query, $position + 1, $findId]);
                }
            }
            fclose($handle);
            $io->writeln("fused orders -> {$emit}");
        }

        return Command::SUCCESS;
    }

    /**
     * @return list<float>
     */
    private function vectorFor(string $leg, Image $image, string $query, string $model): array
    {
        $text = match ($leg) {
            'caption-4o' => $this->captions->of($image, $query, 'gpt-4o'),
            'caption-function' => $this->captions->of($image, $query, $model, 'function'),
            'caption' => $this->captions->of($image, $query, $model),
            default => '',
        };

        return 'photo' === $leg
            ? $this->vectorizer->vectorize(RequestedObject::new('', $image))->getVector()->getData()
            : $this->vectorizer->vectorize(RequestedObject::new($text, null))->getVector()->getData();
    }

    /**
     * @param array<string, list<float>> $vectors keyed by leg name
     *
     * @return list<string> find IDs, best first
     */
    private function fused(array $vectors): array
    {
        $pipelines = [];
        foreach ($vectors as $name => $vector) {
            $pipelines[$name] = $this->leg($vector);
        }

        $rows = $this->dm->getDocumentCollection(Artefact::class)->aggregate(
            [
                ['$rankFusion' => ['input' => ['pipelines' => $pipelines]]],
                ['$limit' => self::DEPTH],
            ],
            ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']],
        );

        $findIds = [];
        foreach ($rows as $row) {
            $findIds[] = $row['findId'];
        }

        return $findIds;
    }

    /**
     * @param list<float> $vector
     *
     * @return list<array<string, mixed>>
     */
    private function leg(array $vector): array
    {
        return [
            ['$vectorSearch' => [
                'index' => 'default',
                'path' => 'embeddingVector',
                'queryVector' => $vector,
                'numCandidates' => self::DEPTH * 20,
                'limit' => self::DEPTH * 3,
            ]],
        ];
    }
}
