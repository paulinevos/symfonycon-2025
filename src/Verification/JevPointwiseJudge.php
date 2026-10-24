<?php

namespace App\Verification;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Jev asked the same two questions as the OpenAI pointwise judge, one
 * candidate at a time.
 *
 * `noul` is Jev's binary question type: one yes/no thing, answered with a
 * calibrated probability. Two questions in one request keeps the call count
 * and the inputs identical to the OpenAI arm, so the only difference is the
 * model. The old reranker used `choice` over the whole shortlist, which is a
 * different question and a different shape.
 */
final readonly class JevPointwiseJudge implements CandidateJudge
{
    private const ENDPOINT = 'https://api.typesafe.ai/v1/systemone';

    private const SAME_OBJECT = 'Is the candidate the same object as the one in the photographed query?';

    private const SAME_FUNCTION = 'Is the candidate a different object that serves the same function — an older equivalent — however different it looks?';

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
        return 'jev-pointwise';
    }

    public function assess(CandidateUnderTest $candidate): CandidateAssessment
    {
        if (!$candidate->hasPhotograph()) {
            throw MissingPhotograph::forCandidate($candidate->findId);
        }

        $queryReference = $this->reference();
        $candidateReference = $this->reference();
        $response = $this->httpClient->request('POST', self::ENDPOINT, [
            'auth_bearer' => $this->apiKey,
            'headers' => ['TypeSafe-Danger-Zone-Images' => 'true'],
            'json' => [
                'model' => $this->model,
                'state' => [
                    'request' => $candidate->query,
                    'photo' => $queryReference,
                    'context' => $candidateReference . ' — ' . $candidate->summary . ' ' . self::CONTEXT,
                ],
                'assets' => (object) [
                    $queryReference => $this->images->fromFile($candidate->queryPhotoPath),
                    $candidateReference => $this->images->fromFile($candidate->candidatePhotoPath),
                ],
                'questions' => [
                    'sameObject' => ['type' => 'noul', 'instructions' => self::SAME_OBJECT],
                    'sameFunction' => ['type' => 'noul', 'instructions' => self::SAME_FUNCTION],
                ],
            ],
        ]);

        if (400 <= $response->getStatusCode()) {
            throw new \RuntimeException('Jev rejected the request: ' . $response->getContent(false));
        }

        $answers = $response->toArray()['answers'] ?? [];

        return new CandidateAssessment(
            $this->percentage($answers['sameObject']['noul'] ?? 0.0),
            $this->percentage($answers['sameFunction']['noul'] ?? 0.0),
            '',
        );
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
