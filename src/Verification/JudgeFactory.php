<?php

namespace App\Verification;

use Symfony\AI\Platform\PlatformInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds the judge named on the command line.
 *
 * Two entry points because the two shapes have different contracts: a
 * pointwise judge decides one candidate and can skip it; a batch judge decides
 * the whole shortlist and fails as a unit. The model and presentation stay
 * per-run options, so the same score script can compare every combination.
 */
final readonly class JudgeFactory
{
    public function __construct(
        private PlatformInterface $openAi,
        private HttpClientInterface $httpClient,
        private string $typeSafeApiKey,
        private JevImageAsset $images,
    ) {
    }

    public function pointwise(string $judge, string $model, Presentation $presentation): CandidateJudge
    {
        return match ($judge) {
            'openai' => new RetryingJudge(new OpenAiCandidateJudge($this->openAi, $this->modelFor($judge, $model), $presentation)),
            'jev' => new RetryingJudge(new JevPointwiseJudge($this->httpClient, $this->typeSafeApiKey, $this->modelFor($judge, $model), $this->images)),
            default => throw new \InvalidArgumentException(sprintf('Unknown pointwise judge "%s"; available: openai, jev.', $judge)),
        };
    }

    public function batch(string $judge, string $model): BatchCandidateJudge
    {
        return match ($judge) {
            'openai-pointwise' => new RetryingBatchJudge(new OpenAiCandidateJudge($this->openAi, $this->modelFor($judge, $model), Presentation::Visual)),
            'openai-choice' => new RetryingBatchJudge(new OpenAiChoiceJudge($this->openAi, $this->modelFor($judge, $model))),
            'jev-choice' => new RetryingBatchJudge(new JevChoiceJudge($this->httpClient, $this->typeSafeApiKey, $this->modelFor($judge, $model), $this->images)),
            default => throw new \InvalidArgumentException(sprintf('Unknown batch judge "%s"; available: openai-pointwise, openai-choice, jev-choice.', $judge)),
        };
    }

    public function modelFor(string $judge, string $model): string
    {
        if ('' !== $model) {
            return $model;
        }

        return match ($judge) {
            'openai', 'openai-choice', 'openai-pointwise' => 'gpt-4o',
            'jev', 'jev-choice' => 'jev-latest',
            default => throw new \InvalidArgumentException(sprintf('Unknown judge "%s".', $judge)),
        };
    }
}
