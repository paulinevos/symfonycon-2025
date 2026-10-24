<?php

namespace App\Reranker;

use Symfony\AI\Platform\Message\Content\Image;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reranking with Jev, handing it the photographs.
 *
 * A text-only reranker cannot improve a pipeline that compared pictures: it
 * only ever sees the words somebody wrote about them, and a caption is a lossy
 * proxy for the thing itself. Jev has accepted images since 1.13, in the
 * state, in a question's instructions, or in the criteria — so the candidates
 * can be their own photographs.
 *
 * Images are off by default and need the TypeSafe-Danger-Zone-Images header.
 * Each image is listed once under "assets" against a reference, and the
 * reference is written wherever the image belongs; references match as plain
 * text, so they are random rather than readable.
 */
final readonly class JevImageReranker
{
    private const ENDPOINT = 'https://api.typesafe.ai/v1/systemone';

    private const INSTRUCTIONS = 'Which candidate is the object in the photograph — the same object, or the closest thing to it? Compare shape, material and function, not the background or the hand holding it.';

    /**
     * The context a museum catalogue needs and a photo search does not.
     *
     * The candidates are archaeological finds spanning thousands of years.
     * Some are modern, some are prehistoric and look nothing like the thing
     * they are equivalent to. Without being told that, a model shown two
     * photographs will compare them visually, which is the wrong question.
     */
    private const CONTEXT = 'The candidates are archaeological finds from a museum catalogue. They range from prehistoric to modern, so a candidate may look very different from the object in the photograph while still being the same thing or an older equivalent of it. A candidate is a match if it is the same object, or an older object that serves the same general function, however different it looks.';

    /** The API's smallest and largest accepted sides, in pixels. */
    private const MIN_SIDE = 280;
    private const MAX_SIDE = 1568;

    public function __construct(
        private HttpClientInterface $httpClient,
        #[\SensitiveParameter]
        private string $apiKey,
        private string $model = 'jev-latest',
        private string $imageCache = '',
    ) {
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * @param list<PhotoCandidate> $candidates
     *
     * @return list<array{index: int, relevance_score: float}> best first
     */
    public function score(Image $query, array $candidates, int $topK, bool $withSummaries = false, string $request = '', bool $contextInInstructions = false): array
    {
        if ([] === $candidates) {
            return [];
        }

        $probabilities = $this->probabilities($query, $candidates, $withSummaries, $request, $contextInInstructions);

        $scored = [];
        foreach ($candidates as $index => $ignored) {
            $scored[] = [
                'index' => $index,
                // A candidate the model left out was not in contention, which
                // is a zero rather than a missing value.
                'relevance_score' => (float) ($probabilities[(string) $index] ?? 0.0),
            ];
        }

        usort($scored, static fn (array $a, array $b): int => $b['relevance_score'] <=> $a['relevance_score']);

        return array_slice($scored, 0, $topK);
    }

    /**
     * @param list<PhotoCandidate> $candidates
     *
     * @return array<string, float> keyed by the label each candidate was given
     */
    private function probabilities(Image $query, array $candidates, bool $withSummaries, string $request, bool $contextInInstructions): array
    {
        [$assets, $queryReference, $references] = $this->assets($query, $candidates);

        $response = $this->httpClient->request('POST', self::ENDPOINT, [
            'auth_bearer' => $this->apiKey,
            'headers' => ['TypeSafe-Danger-Zone-Images' => 'true'],
            'json' => [
                'model' => $this->model,
                'state' => $contextInInstructions
                    ? ['request' => $request, 'photo' => $queryReference]
                    : ['request' => $request, 'photo' => $queryReference, 'context' => self::CONTEXT],
                'assets' => (object) $assets,
                'questions' => [
                    'best' => [
                        'type' => 'choice',
                        'instructions' => $contextInInstructions
                            ? self::INSTRUCTIONS . ' ' . self::CONTEXT
                            : self::INSTRUCTIONS,
                        'criteria' => $this->criteria(
                            $references,
                            $withSummaries ? array_map(static fn (PhotoCandidate $c): string => $c->summary, $candidates) : [],
                        ),
                    ],
                ],
            ],
        ]);

        if (400 <= $response->getStatusCode()) {
            // The API's own explanation is the useful part; Symfony's exception
            // only carries the status line.
            throw new \RuntimeException('Jev rejected the request: ' . $response->getContent(false));
        }

        $answer = $response->toArray()['answers']['best'] ?? [];

        return $answer['probabilities'] ?? [];
    }

    /**
     * Every image, listed once, against the reference that stands in for it.
     *
     * @param list<PhotoCandidate> $candidates
     *
     * @return array{0: array<string, array<string, string>>, 1: string, 2: list<string>}
     */
    private function assets(Image $query, array $candidates): array
    {
        $queryReference = $this->reference();
        $assets = [$queryReference => $this->asset($query->asBinary(), $this->formatFor($query->getFormat()))];

        $references = [];
        foreach ($candidates as $candidate) {
            $reference = $this->reference();
            $assets[$reference] = $this->asset($this->imageBytes($candidate->imageUrl), 'png');
            $references[] = $reference;
        }

        return [$assets, $queryReference, $references];
    }

    /**
     * Each criterion is the candidate's photograph, and optionally the words
     * the catalogue holds about it.
     *
     * Sending the image alone isolates what the picture contributes. Sending
     * both is the design that should win: the photograph says what it looks
     * like, the catalogue entry says what it is for, and the object is both.
     *
     * @param list<string> $references
     * @param list<string> $summaries
     */
    private function criteria(array $references, array $summaries = []): \stdClass
    {
        $criteria = [];

        foreach ($references as $index => $reference) {
            $criteria[(string) $index] = isset($summaries[$index])
                ? $reference . ' — ' . $summaries[$index]
                : $reference;
        }

        // Cast to an object: PHP turns the string keys "0", "1" back into
        // integers, and a sequentially keyed array encodes as a JSON list,
        // which the API rejects with "Input should be a valid dictionary".
        return (object) $criteria;
    }

    /**
     * @return array<string, string>
     */
    private function asset(string $bytes, string $format): array
    {
        return [
            'type' => 'image',
            'format' => $format,
            'data_base64' => base64_encode($this->fitted($bytes, $format)),
        ];
    }

    /**
     * Jev takes sides of 280–1568 px and at least 78,400 pixels in total.
     *
     * The catalogue's photographs are cropped tight to the object, so a thin
     * one can arrive 65 px wide and be rejected outright — the whole request
     * fails, not just that candidate. Padding the short side onto a neutral
     * canvas keeps the object undistorted and inside the bounds; scaling up
     * would blur it, and scaling down is not possible when a side is already
     * too small.
     */
    private function fitted(string $bytes, string $format): string
    {
        $source = @imagecreatefromstring($bytes);
        if (false === $source) {
            return $bytes;
        }

        $originalWidth = imagesx($source);
        $originalHeight = imagesy($source);
        $source = $this->downscaled($source);
        $width = imagesx($source);
        $height = imagesy($source);
        $shortest = min($width, $height);
        $scaled = $width !== $originalWidth || $height !== $originalHeight;
        $padded = max(self::MIN_SIDE, $shortest) !== $shortest;

        if (!$scaled && !$padded) {
            return $bytes;
        }

        if ($padded) {
            $canvas = imagecreatetruecolor(max($width, self::MIN_SIDE), max($height, self::MIN_SIDE));
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);
            $source = $canvas;
        }

        ob_start();
        if ('jpeg' === $format) {
            imagejpeg($source, null, 90);
        } else {
            imagepng($source);
        }

        return (string) ob_get_clean();
    }

    private function downscaled(\GdImage $source): \GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        if (max($width, $height) <= self::MAX_SIDE) {
            return $source;
        }

        $scale = self::MAX_SIDE / max($width, $height);
        $targetWidth = (int) round($width * $scale);
        $targetHeight = (int) round($height * $scale);
        $resized = imagecreatetruecolor($targetWidth, $targetHeight);
        imagecopyresampled($resized, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        return $resized;
    }

    private function formatFor(string $mime): string
    {
        return match ($mime) {
            'image/jpeg', 'image/jpg' => 'jpeg',
            default => 'png',
        };
    }

    /**
     * Photographs are fetched once and kept: a benchmark asks for the same
     * candidate images over and over, and the CDN is slower than the disk.
     */
    private function imageBytes(string $url): string
    {
        $path = $this->cachePath($url);

        if ('' !== $path && is_file($path)) {
            return (string) file_get_contents($path);
        }

        $bytes = $this->httpClient->request('GET', $url)->getContent();

        if ('' !== $path) {
            file_put_contents($path, $bytes);
        }

        return $bytes;
    }

    private function cachePath(string $url): string
    {
        if ('' === $this->imageCache) {
            return '';
        }

        if (!is_dir($this->imageCache)) {
            mkdir($this->imageCache, 0o777, true);
        }

        return $this->imageCache . '/' . md5($url) . '.png';
    }

    private function reference(): string
    {
        return bin2hex(random_bytes(8));
    }
}
