<?php

namespace App\Reranker;

use Symfony\AI\Store\Document\VectorDocumentInterface;
use Symfony\AI\Store\Reranker\RerankerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Voyage's cross-encoder rerankers, behind SymfonyAI's RerankerInterface.
 *
 * Hand-rolled because symfony/ai-voyage-platform only speaks to the two
 * embedding endpoints: its ModelClient has no /v1/rerank path and its
 * ResultConverter always returns a VectorResult, so Reranker::rerank() cannot
 * work through it. Tracked upstream as PHPORM-516; when that lands this class
 * goes away and the interface stays.
 *
 * Note the response shape: the documented key is "results", the API actually
 * returns "data".
 */
final readonly class VoyageReranker implements RerankerInterface
{
    private const ENDPOINT = 'https://api.voyageai.com/v1/rerank';

    public function __construct(
        private HttpClientInterface $httpClient,
        #[\SensitiveParameter]
        private string $apiKey,
        private string $model = 'rerank-3',
    ) {
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * @param list<VectorDocumentInterface> $documents
     *
     * @return list<VectorDocumentInterface>
     */
    public function rerank(string $query, array $documents, int $topK = 5): array
    {
        if ([] === $documents) {
            return [];
        }

        $texts = array_map(
            static fn (VectorDocumentInterface $document): string => $document->getMetadata()->getText() ?? '',
            $documents,
        );

        $reranked = [];

        foreach ($this->score($query, $texts, $topK) as $entry) {
            $reranked[] = $documents[$entry['index']]->withScore((float) $entry['relevance_score']);
        }

        return $reranked;
    }

    /**
     * Scores plain strings, for callers that have no VectorDocuments to hand.
     *
     * @param list<string> $texts
     *
     * @return list<array{index: int, relevance_score: float}> best first
     */
    public function score(string $query, array $texts, int $topK): array
    {
        $response = $this->httpClient->request('POST', self::ENDPOINT, [
            'auth_bearer' => $this->apiKey,
            'json' => [
                'model' => $this->model,
                'query' => $query,
                'documents' => $texts,
                'top_k' => min($topK, count($texts)),
            ],
        ]);

        $payload = $response->toArray();

        // "data", not "results" — the docs and the API disagree.
        $entries = $payload['data'] ?? $payload['results'] ?? [];

        usort($entries, static fn (array $a, array $b): int => $b['relevance_score'] <=> $a['relevance_score']);

        return $entries;
    }
}
