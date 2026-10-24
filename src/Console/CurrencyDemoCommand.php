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
 * Three genuinely different ways to ask for an old currency, then fuse them.
 *
 * The point of the demo is that each leg is incomplete for a different reason:
 * the multimodal leg finds what looks like the coin, the semantic leg finds old
 * money by meaning, and the text leg finds the exact guilder entries. RRF is
 * what lets the guilder entry win because more than one leg supports it.
 */
#[AsCommand(
    name: 'app:demo:currency',
    description: 'Run multimodal, semantic and text retrieval for a coin query, then fuse them',
)]
final class CurrencyDemoCommand extends Command
{
    private const MULTIMODAL = 'This but from a different era';
    private const SEMANTIC = 'show me an old currency';
    private const TEXT = 'guilder';
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
            ->addArgument('image', InputArgument::REQUIRED, 'The query photograph')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Rows per section', '5');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $image = Image::fromFile((string) $input->getArgument('image'));
        $limit = (int) $input->getOption('limit');
        $collection = $this->dm->getDocumentCollection(Artefact::class);

        $pipelines = [
            'multimodal' => [$this->vectorSearch($this->vector(self::MULTIMODAL, $image))],
            'semantic' => [$this->vectorSearch($this->vector(self::SEMANTIC, null))],
            'text' => [
                // "guilder" also matches telephone cards denominated in
                // guilders; restricting to coin entries keeps the leg on the
                // currency the query is actually asking for.
                ['$match' => [
                    '$text' => ['$search' => self::TEXT],
                    'summary' => ['$regex' => 'coin', '$options' => 'i'],
                ]],
                // $rankFusion only accepts selection stages, so the text score
                // is read by the sort directly rather than materialised.
                ['$sort' => ['score' => ['$meta' => 'textScore']]],
                ['$limit' => self::DEPTH],
            ],
        ];

        foreach ($pipelines as $name => $stages) {
            $io->section($name);
            $this->print($io, $collection, [...$stages, ['$limit' => $limit]], $limit);
        }

        $io->section('fused');
        $this->print($io, $collection, [
            ['$rankFusion' => [
                'input' => ['pipelines' => $pipelines],
                // The two broad coin legs agree on generic old coins; the
                // text leg is the only one that knows "guilder", so it gets
                // the weight to keep the specific currency above the generic.
                'combination' => ['weights' => ['multimodal' => 1, 'semantic' => 1, 'text' => 3]],
            ]],
            ['$limit' => $limit],
        ], $limit);

        return Command::SUCCESS;
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

    /**
     * @param list<array<string, mixed>> $stages
     */
    private function print(SymfonyStyle $io, $collection, array $stages, int $limit): void
    {
        $rows = $collection->aggregate(
            [...$stages, ['$project' => ['_id' => 0, 'findId' => 1, 'summary' => 1]]],
            ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']],
        );

        $table = [];
        foreach ($rows as $row) {
            $table[] = [$row['findId'], mb_substr((string) $row['summary'], 0, 95)];
        }

        $io->table(['findId', 'summary'], $table);
    }
}
