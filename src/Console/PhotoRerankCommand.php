<?php

namespace App\Console;

use App\Document\Artefact;
use App\Evaluation\CaptionCache;
use App\Reranker\JevImageReranker;
use App\Reranker\JevReranker;
use App\Reranker\PhotoCandidate;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Rerank one retrieval order and print the result, for the scoring script.
 *
 * Kept as a separate command so the Python side can score every combination
 * without reimplementing the reranker call, and so the caption cache is shared
 * with the other benchmarks rather than regenerated.
 */
#[AsCommand(
    name: 'app:benchmark:photo-rerank',
    description: 'Rerank a photo query against a supplied shortlist and print the order',
)]
final class PhotoRerankCommand extends Command
{
    public function __construct(
        private readonly DocumentManager $dm,
        private readonly JevReranker $jev,
        private readonly JevImageReranker $jevImage,
        private readonly CaptionCache $captions,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('photo', InputArgument::REQUIRED, 'Path to the query photograph')
            ->addArgument('findIds', InputArgument::REQUIRED, 'Comma-separated shortlist, best first')
            ->addOption('caption-model', null, InputOption::VALUE_REQUIRED, 'Which model writes the description', 'gpt-4o-mini')
            ->addOption('reranker', null, InputOption::VALUE_REQUIRED, 'jev (summaries) or jev-image (photos)', 'jev')
            ->addOption('with-summaries', null, InputOption::VALUE_NONE, 'For jev-image: send the summary beside each photo')
            ->addOption('context-in-instructions', null, InputOption::VALUE_NONE, 'Put the catalogue context in the instructions');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $findIds = array_values(array_filter(explode(',', (string) $input->getArgument('findIds'))));
        $photo = (string) $input->getArgument('photo');

        $summaries = $this->summariesOf($findIds);
        $caption = $this->captions->of(
            Image::fromFile($photo),
            pathinfo($photo, \PATHINFO_FILENAME),
            (string) $input->getOption('caption-model'),
        );

        $ranked = [];
        $withSummaries = (bool) $input->getOption('with-summaries');
        $scored = 'jev-image' === $input->getOption('reranker')
            ? $this->jevImage->score(Image::fromFile($photo), $this->candidatesOf($findIds, $summaries), count($summaries), $withSummaries, $caption, (bool) $input->getOption('context-in-instructions'))
            : $this->jev->score($caption, array_values($summaries), count($summaries));

        foreach ($scored as $entry) {
            $ranked[] = array_keys($summaries)[$entry['index']];
        }

        $output->writeln(implode(',', $ranked));

        return Command::SUCCESS;
    }

    /**
     * @param list<string>          $findIds
     * @param array<string, string> $summaries
     *
     * @return list<PhotoCandidate>
     */
    private function candidatesOf(array $findIds, array $summaries): array
    {
        $urls = $this->dm->getDocumentCollection(Artefact::class)->find(
            ['findId' => ['$in' => $findIds]],
            ['projection' => ['findId' => 1, 'imageUrls' => 1],
             'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']],
        );

        $byFindId = [];
        foreach ($urls as $row) {
            if (!empty($row['imageUrls'])) {
                $byFindId[$row['findId']] = array_values((array) $row['imageUrls'])[0];
            }
        }

        $candidates = [];
        foreach ($findIds as $findId) {
            if (isset($byFindId[$findId], $summaries[$findId])) {
                $candidates[] = new PhotoCandidate($findId, $summaries[$findId], $byFindId[$findId]);
            }
        }

        return $candidates;
    }

    /**
     * @param list<string> $findIds
     *
     * @return array<string, string> find ID => summary, in shortlist order
     */
    private function summariesOf(array $findIds): array
    {
        $rows = $this->dm->getDocumentCollection(Artefact::class)->find(
            ['findId' => ['$in' => $findIds]],
            [
                'projection' => ['findId' => 1, 'summary' => 1],
                'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'],
            ],
        );

        $byFindId = [];
        foreach ($rows as $row) {
            $byFindId[$row['findId']] = $row['summary'];
        }

        $ordered = [];
        foreach ($findIds as $findId) {
            if (isset($byFindId[$findId])) {
                $ordered[$findId] = $byFindId[$findId];
            }
        }

        return $ordered;
    }
}
