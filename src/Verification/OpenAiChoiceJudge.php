<?php

namespace App\Verification;

use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;

/**
 * The OpenAI model asked to order the whole shortlist at once.
 *
 * Same photographs, same summaries, same two questions as the pointwise arm.
 * The only difference is that every candidate is in one request, so the model
 * discriminates against its distractors instead of judging one candidate on
 * its own. That is the shape the old reranker used, and the cell the previous
 * comparison left empty.
 */
final readonly class OpenAiChoiceJudge implements BatchCandidateJudge
{
    private const SYSTEM_PROMPT = 'You assess whether museum catalogue entries match an object someone has photographed. The catalogue covers archaeology from prehistory to the present.';

    private const PROMPT = 'The first image is the object someone photographed. The following images are the candidates, each followed by its label and catalogue entry. A candidate can be the same object, or an older object that serves the same function and looks very different. Judge the object, not the photograph: ignore the background, the lighting and the hand holding it. Return two orderings, best first, each listing every candidate label exactly once as a comma-separated list. sameObjectOrder: is this the same object? sameFunctionOrder: is this an older object serving the same function?';

    public function __construct(
        private PlatformInterface $openAi,
        private string $model = 'gpt-4o',
    ) {
    }

    public function name(): string
    {
        return 'openai-choice';
    }

    public function assessAll(array $candidates): array
    {
        if ([] === $candidates) {
            return [];
        }

        $answer = $this->openAi->invoke(
            $this->model,
            $this->messages($candidates),
            [PlatformSubscriber::RESPONSE_FORMAT => ChoiceOrder::class],
        )->asObject();

        if (!$answer instanceof ChoiceOrder) {
            throw new \RuntimeException('The judge returned an unexpected result: ' . get_debug_type($answer));
        }

        return $this->assessments($candidates, $answer);
    }

    /**
     * @param list<CandidateUnderTest> $candidates
     */
    private function messages(array $candidates): MessageBag
    {
        $parts = [Image::fromFile($candidates[0]->queryPhotoPath)];
        foreach ($candidates as $index => $candidate) {
            if (!$candidate->hasPhotograph()) {
                throw MissingPhotograph::forCandidate($candidate->findId);
            }
            $parts[] = Image::fromFile($candidate->candidatePhotoPath);
            $parts[] = sprintf('Candidate %d: %s', $index, $candidate->summary);
        }

        return new MessageBag(
            Message::forSystem(self::SYSTEM_PROMPT),
            Message::ofUser(self::PROMPT, ...$parts),
        );
    }

    /**
     * @param list<CandidateUnderTest> $candidates
     *
     * @return array<string, CandidateAssessment>
     */
    private function assessments(array $candidates, ChoiceOrder $answer): array
    {
        $count = count($candidates);
        $object = $this->order($answer->sameObjectOrder, $count);
        $function = $this->order($answer->sameFunctionOrder, $count);
        if ([] === $object || [] === $function) {
            throw new \RuntimeException('The choice judge returned an empty ordering.');
        }

        $objectScores = $this->scores($object, $count);
        $functionScores = $this->scores($function, $count);
        $assessments = [];
        foreach ($candidates as $index => $candidate) {
            $assessments[$candidate->findId] = new CandidateAssessment(
                $objectScores[$index],
                $functionScores[$index],
                '',
            );
        }

        return $assessments;
    }

    /**
     * @return list<int>
     */
    private function order(string $raw, int $count): array
    {
        $labels = [];
        foreach (preg_split('/[^0-9]+/', $raw) ?: [] as $token) {
            if ('' === $token) {
                continue;
            }
            $label = (int) $token;
            if ($label < 0 || $label >= $count || in_array($label, $labels, true)) {
                continue;
            }
            $labels[] = $label;
        }

        for ($label = 0; $label < $count; $label++) {
            if (!in_array($label, $labels, true)) {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    /**
     * @param list<int> $order
     *
     * @return array<int, int> label => 0–100
     */
    private function scores(array $order, int $count): array
    {
        $scores = [];
        foreach ($order as $position => $label) {
            $scores[$label] = (int) round(100 * ($count - $position) / $count);
        }

        return $scores;
    }
}
