<?php

namespace App\Verification;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Jev with the whole shortlist in one `choice`, now asking both questions.
 *
 * The two questions go in separate requests: two probability maps over forty
 * candidates exceed the endpoint's output-token limit. The shape is still
 * whole-shortlist choice — every candidate in one criteria set, decided
 * against its distractors — which is what the old reranker did and what the
 * pointwise arm does not do.
 */
final readonly class JevChoiceJudge implements BatchCandidateJudge
{
    private const ENDPOINT = 'https://api.typesafe.ai/v1/systemone';

    private const SAME_OBJECT = 'Which candidate is the same object as the one in the photographed query?';

    private const SAME_FUNCTION = 'Which candidate is a different object that serves the same function — an older equivalent — however different it looks?';

    private const CONTEXT = 'The catalogue covers archaeology from prehistory to the present, so an older equivalent may look very different from the photographed object. Judge the object, not the photograph: ignore the background, the lighting and the hand holding it.';

    public function __construct(
        private HttpClientInterface $httpClient,
        #[\SensitiveParameter]
        private string $apiKey,
        private string $model = 'jev-latest',
        private JevImageAsset $images = new JevImageAsset(),
    ) {
    }

    public function name(): string
    {
        return 'jev-choice';
    }

    public function assessAll(array $candidates): array
    {
        if ([] === $candidates) {
            return [];
        }

        $assets = [];
        $criteria = [];
        foreach ($candidates as $index => $candidate) {
            $reference = $this->reference();
            $assets[$reference] = $this->images->fromFile($candidate->candidatePhotoPath);
            $criteria[(string) $index] = $reference . ' — ' . $candidate->summary;
        }

        $queryReference = $this->reference();
        $assets[$queryReference] = $this->images->fromFile($candidates[0]->queryPhotoPath);

        $object = $this->probabilities('sameObject', self::SAME_OBJECT, $criteria, $assets, $queryReference, $candidates[0]->query);
        $function = $this->probabilities('sameFunction', self::SAME_FUNCTION, $criteria, $assets, $queryReference, $candidates[0]->query);

        return $this->assessments($candidates, $object, $function);
    }

    /**
     * @param array<string, string>              $criteria
     * @param array<string, array<string, string>> $assets
     *
     * @return array<string, float>
     */
    private function probabilities(
        string $name,
        string $instructions,
        array $criteria,
        array $assets,
        string $queryReference,
        string $request,
    ): array {
        $response = $this->httpClient->request('POST', self::ENDPOINT, [
            'auth_bearer' => $this->apiKey,
            'headers' => ['TypeSafe-Danger-Zone-Images' => 'true'],
            'json' => [
                'model' => $this->model,
                'state' => [
                    'request' => $request,
                    'photo' => $queryReference,
                    'context' => self::CONTEXT,
                ],
                'assets' => (object) $assets,
                'questions' => [
                    $name => [
                        'type' => 'choice',
                        'instructions' => $instructions,
                        'criteria' => (object) $criteria,
                    ],
                ],
            ],
        ]);

        if (400 <= $response->getStatusCode()) {
            throw new \RuntimeException('Jev rejected the request: ' . $response->getContent(false));
        }

        return $response->toArray()['answers'][$name]['probabilities'] ?? [];
    }

    /**
     * @param list<CandidateUnderTest> $candidates
     * @param array<string, float>     $object
     * @param array<string, float>     $function
     *
     * @return array<string, CandidateAssessment>
     */
    private function assessments(array $candidates, array $object, array $function): array
    {
        $assessments = [];
        foreach ($candidates as $index => $candidate) {
            $assessments[$candidate->findId] = new CandidateAssessment(
                $this->percentage($object[(string) $index] ?? 0.0),
                $this->percentage($function[(string) $index] ?? 0.0),
                '',
            );
        }

        return $assessments;
    }

    private function percentage(float $probability): int
    {
        return (int) round(max(0.0, min(1.0, $probability)) * 100);
    }

    private function reference(): string
    {
        return bin2hex(random_bytes(8));
    }
}
