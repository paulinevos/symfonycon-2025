<?php

namespace App\Reranker;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reranking with a decision model instead of a cross-encoder.
 *
 * Jev is not a reranker. It is TypeSafe's "System One" model: you hand it a
 * state and a typed question and it answers with calibrated probabilities
 * rather than prose. Asking which of the candidates matches turns those
 * probabilities into a ranking.
 *
 * The question type matters more than it looks. A "noul" asks one yes/no
 * question about one thing and returns a single probability, so ranking N
 * candidates means N requests. A "choice" puts all the candidates in as
 * alternatives and answers once, with a probability for each — the whole
 * shortlist in a single round trip. Measured over twenty candidates that is
 * 0.3 s against 5.4 s, an eighteen-fold difference for a marginally noisier
 * ordering.
 *
 * Where this diverges from rerank-3 is what you can ask. A cross-encoder
 * returns one relevance number. Jev answers several independent questions in
 * the same request, so a caller can ask "is this the right category?", "does
 * it satisfy this constraint?", "is it compatible?" and combine the answers
 * with their own weights. That makes it a policy engine more than a ranker,
 * which is a different tool rather than a worse one.
 */
final readonly class JevReranker
{
    private const ENDPOINT = 'https://api.typesafe.ai/v1/systemone';

    private const INSTRUCTIONS = 'Which artefact best matches what the user is looking for? Compare function, material, shape and finish. Ignore differences in wording.';

    public function __construct(
        private HttpClientInterface $httpClient,
        #[\SensitiveParameter]
        private string $apiKey,
        // The API advertises "jev-latest" and "jev-preview"; a pinned version
        // like "jev-1.13" is rejected as an unknown model, even though that is
        // the version string it reports back.
        private string $model = 'jev-latest',
    ) {
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * @param list<string> $texts
     *
     * @return list<array{index: int, relevance_score: float}> best first
     */
    public function score(string $query, array $texts, int $topK): array
    {
        if ([] === $texts) {
            return [];
        }

        $probabilities = $this->probabilities($query, $texts);

        $scored = [];
        foreach ($texts as $index => $ignored) {
            $scored[] = [
                'index' => $index,
                // A candidate the model left out of its probabilities was not
                // in contention, which is a zero rather than a missing value.
                'relevance_score' => (float) ($probabilities[(string) $index] ?? 0.0),
            ];
        }

        usort($scored, static fn (array $a, array $b): int => $b['relevance_score'] <=> $a['relevance_score']);

        return array_slice($scored, 0, $topK);
    }

    /**
     * @param list<string> $texts
     *
     * @return array<string, float> keyed by the label each candidate was given
     */
    private function probabilities(string $query, array $texts): array
    {
        $response = $this->httpClient->request('POST', self::ENDPOINT, [
            'auth_bearer' => $this->apiKey,
            'json' => [
                'model' => $this->model,
                'state' => ['request' => $query],
                'questions' => [
                    'best' => [
                        // The discriminator is required; leaving it out is a 422
                        // complaining about a missing tag. Note that "criteria"
                        // sits beside "type", not inside a nested "choice".
                        'type' => 'choice',
                        'instructions' => self::INSTRUCTIONS,
                        'criteria' => $this->label($texts),
                    ],
                ],
            ],
        ]);

        $answer = $response->toArray()['answers']['best'] ?? [];

        return $answer['probabilities'] ?? [];
    }

    /**
     * Labels are the candidate's position, so the response keys map straight
     * back onto the input list.
     *
     * Cast to an object on purpose: PHP turns the string keys "0", "1", … back
     * into integers, and a sequentially keyed array encodes as a JSON list,
     * which the API rejects with "Input should be a valid dictionary".
     *
     * @param list<string> $texts
     */
    private function label(array $texts): \stdClass
    {
        $criteria = [];

        foreach ($texts as $index => $text) {
            $criteria[(string) $index] = $text;
        }

        return (object) $criteria;
    }
}
