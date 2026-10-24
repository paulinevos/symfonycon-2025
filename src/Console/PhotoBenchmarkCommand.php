<?php

namespace App\Console;

use App\Document\Artefact;
use App\Evaluation\CaptionCache;
use App\Evaluation\JudgedQuery;
use App\Evaluation\JudgedQuerySet;
use App\Reranker\JevImageReranker;
use App\Reranker\JevReranker;
use App\Reranker\PhotoCandidate;
use App\Reranker\VoyageReranker;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The photo path, with and without the lossy step.
 *
 * Four orderings over the same shortlist and the same human judgements:
 *
 *   retrieval      the photo, embedded
 *   rerank-3       a generated caption of the photo
 *   jev (caption)  the same caption, a different model
 *   jev (photo)    the photo itself, and the candidates' photos
 *
 * The last two are the pair that matters: identical model, identical
 * candidates, and the only difference is whether the reranker is shown the
 * picture or a sentence about it.
 */
#[AsCommand(
    name: 'app:benchmark:photo',
    description: 'Compare reranking a photo query by caption against reranking it by picture',
)]
final class PhotoBenchmarkCommand extends Command
{
    public function __construct(
        private readonly DocumentManager $dm,
        private readonly VoyageReranker $voyage,
        private readonly JevReranker $jev,
        private readonly JevImageReranker $jevImage,
        private readonly CaptionCache $captions,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('sheet', InputArgument::REQUIRED, 'The annotated photo judgements CSV')
            ->addArgument('photos', InputArgument::REQUIRED, 'Directory holding the query photographs')
            ->addOption('caption-model', null, InputOption::VALUE_REQUIRED, 'Which model writes the description', 'gpt-4o-mini')
            ->addOption('caption-prompt', null, InputOption::VALUE_REQUIRED, 'appearance or function', 'appearance');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $set = JudgedQuerySet::fromCsv($input->getArgument('sheet'));
        $photos = rtrim($input->getArgument('photos'), '/');

        $scorable = array_filter($set->scorable(), fn (JudgedQuery $q): bool => $q->hasAMatch());
        $captionModel = (string) $input->getOption('caption-model');
        $io->writeln(sprintf('%d photos, %d scorable, captions by %s', count($set), count($scorable), $captionModel));

        $names = ['retrieval', 'rerank-3', 'jev-caption', 'jev-photo'];
        $mrr = array_fill_keys($names, 0.0);
        $top1 = array_fill_keys($names, 0);
        $rows = [];

        foreach ($scorable as $query) {
            $image = Image::fromFile(sprintf('%s/%s.png', $photos, $query->query));
            $caption = $this->captions->of($image, $query->query, $captionModel, (string) $input->getOption('caption-prompt'));
            $candidates = $this->candidatesOf($query->shortlist());

            $orders = [
                'retrieval' => $query->shortlist(),
                'rerank-3' => $this->order($query, $candidates, fn () => $this->voyage->score($caption, $query->summaries(), count($candidates))),
                'jev-caption' => $this->order($query, $candidates, fn () => $this->jev->score($caption, $query->summaries(), count($candidates))),
                'jev-photo' => $this->order($query, $candidates, fn () => $this->jevImage->score($image, $candidates, count($candidates))),
            ];

            $cells = [];
            foreach ($orders as $name => $order) {
                $rank = $query->rankOfFirstMatch($order);
                $mrr[$name] += null === $rank ? 0.0 : 1 / $rank;
                $top1[$name] += 1 === $rank ? 1 : 0;
                $cells[] = null === $rank ? 'miss' : (string) $rank;
            }
            $rows[] = [$query->query, ...$cells];
        }

        $io->table(['photo', ...$names], $rows);

        $count = max(1, count($scorable));
        $io->table(['pipeline', 'top-1 match', 'MRR'], array_map(
            fn (string $name): array => [
                $name,
                sprintf('%d/%d', $top1[$name], count($scorable)),
                sprintf('%.3f', $mrr[$name] / $count),
            ],
            $names,
        ));

        return Command::SUCCESS;
    }

    /**
     * @param list<PhotoCandidate>            $candidates
     * @param callable(): list<array{index: int, relevance_score: float}> $score
     *
     * @return list<string> find IDs, best first
     */
    private function order(JudgedQuery $query, array $candidates, callable $score): array
    {
        $ranked = [];
        foreach ($score() as $entry) {
            $ranked[] = $candidates[$entry['index']]->findId;
        }

        return $ranked;
    }

    /**
     * @param list<string> $findIds
     *
     * @return list<PhotoCandidate>
     */
    private function candidatesOf(array $findIds): array
    {
        $rows = $this->dm->getDocumentCollection(Artefact::class)->find(
            ['findId' => ['$in' => $findIds]],
            [
                'projection' => ['findId' => 1, 'summary' => 1, 'imageUrls' => 1],
                'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'],
            ],
        );

        $byFindId = [];
        foreach ($rows as $row) {
            if (!empty($row['imageUrls'])) {
                $byFindId[$row['findId']] = new PhotoCandidate($row['findId'], $row['summary'], $row['imageUrls'][0]);
            }
        }

        $ordered = [];
        foreach ($findIds as $findId) {
            if (isset($byFindId[$findId])) {
                $ordered[] = $byFindId[$findId];
            }
        }

        return $ordered;
    }
}
