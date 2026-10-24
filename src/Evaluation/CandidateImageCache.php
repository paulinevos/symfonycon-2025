<?php

namespace App\Evaluation;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Keeps the catalogue photographs a pointwise judge needs on disk.
 *
 * A pointwise judge opens one candidate image per call. Without a cache the
 * web app would download the same thirty photographs on every search.
 */
final readonly class CandidateImageCache
{
    public function __construct(
        private string $directory,
        private HttpClientInterface $httpClient,
    ) {
    }

    public function pathFor(string $url): string
    {
        $path = $this->directory . '/' . md5($url) . '.img';

        if (is_file($path)) {
            return $path;
        }

        $bytes = $this->httpClient->request('GET', $url)->getContent();
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0o777, true);
        }
        file_put_contents($path, $bytes);

        return $path;
    }
}
