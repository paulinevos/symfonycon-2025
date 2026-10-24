<?php

namespace App\Verification;

use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;

/**
 * A multimodal model asked two yes/no questions about one candidate.
 *
 * The catalogue context is in the prompt rather than left implicit because a
 * model shown two photographs will compare them visually, and the app's
 * question is not visual similarity. A prehistoric equivalent really can look
 * nothing like the thing it is equivalent to.
 */
final readonly class OpenAiCandidateJudge implements CandidateJudge, BatchCandidateJudge
{
    private const SYSTEM_PROMPT = 'You assess whether a museum catalogue entry matches an object someone has photographed. The catalogue covers archaeology from prehistory to the present.';

    private const VISUAL_PROMPT = 'The first image is the object someone photographed. The second image is the catalogue candidate. Two photographs of the same kind of object can look very different across eras. Judge the object, not the photograph: ignore the background, the lighting and the hand holding it. Score two questions independently from 0 to 100. sameObjectScore: is this the same object? sameFunctionScore: is this a different object that serves the same function or purpose — an older equivalent — however different it looks?';

    private const TEXT_PROMPT = 'The catalogue covers archaeology from prehistory to the present. The object someone is looking for is given by its name only. Score two questions independently from 0 to 100. sameObjectScore: is this the same object? sameFunctionScore: is this a different object that serves the same function or purpose — an older equivalent — however different it looks?';

    public function __construct(
        private PlatformInterface $openAi,
        private string $model = 'gpt-4o',
        private Presentation $presentation = Presentation::Visual,
    ) {
    }

    public function name(): string
    {
        return 'openai-' . $this->presentation->value;
    }

    public function assess(CandidateUnderTest $candidate): CandidateAssessment
    {
        return $this->assessment($this->deferred($candidate)->asObject());
    }

    /**
     * Start every request before reading any answer, so thirty candidates cost
     * roughly one round trip rather than thirty.
     */
    public function assessAll(array $candidates): array
    {
        $deferred = [];
        foreach ($candidates as $candidate) {
            $deferred[$candidate->findId] = $this->deferred($candidate);
        }

        $assessments = [];
        foreach ($candidates as $candidate) {
            $assessments[$candidate->findId] = $this->assessment($deferred[$candidate->findId]->asObject());
        }

        return $assessments;
    }

    private function deferred(CandidateUnderTest $candidate): \Symfony\AI\Platform\Result\DeferredResult
    {
        return $this->openAi->invoke(
            $this->model,
            $this->messages($candidate),
            [PlatformSubscriber::RESPONSE_FORMAT => VisualAssessment::class],
        );
    }

    private function assessment(object|array $answer): CandidateAssessment
    {
        if (!$answer instanceof VisualAssessment) {
            throw new \RuntimeException('The judge returned an unexpected result: ' . get_debug_type($answer));
        }

        return new CandidateAssessment($answer->sameObjectScore, $answer->sameFunctionScore, $answer->rationale);
    }

    private function messages(CandidateUnderTest $candidate): MessageBag
    {
        return new MessageBag(
            Message::forSystem(self::SYSTEM_PROMPT),
            match ($this->presentation) {
                Presentation::Visual => $this->visualMessage($candidate),
                Presentation::Text => $this->textMessage($candidate),
            },
        );
    }

    private function visualMessage(CandidateUnderTest $candidate): UserMessage
    {
        if (!$candidate->hasPhotograph()) {
            throw MissingPhotograph::forCandidate($candidate->findId);
        }

        return Message::ofUser(
            self::VISUAL_PROMPT . "\n\nCatalogue entry: " . $candidate->summary,
            Image::fromFile($candidate->queryPhotoPath),
            Image::fromFile($candidate->candidatePhotoPath),
        );
    }

    private function textMessage(CandidateUnderTest $candidate): UserMessage
    {
        return Message::ofUser(
            self::TEXT_PROMPT . "\n\nObject: " . $candidate->query . "\nCatalogue entry: " . $candidate->summary,
        );
    }
}
