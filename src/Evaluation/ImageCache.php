<?php

namespace App\Evaluation;

use Symfony\AI\Platform\Message\Content\Image;

/**
 * Keeps the ground truth photographs on disk.
 *
 * Without this, every question downloads a few hundred kilobytes from the
 * museum's CDN, which put half a second of internet latency inside the timed
 * region and made the benchmark measure the network instead of the pipeline.
 * Fetch once, read locally after that.
 *
 * The directory is shared across workspaces through GROUND_TRUTH_IMAGE_CACHE,
 * so the four pipelines all read the same files rather than downloading four
 * copies of the same two hundred photographs.
 */
final readonly class ImageCache
{
    public function __construct(private string $directory)
    {
    }

    public function imageFor(GroundTruthCase $case): Image
    {
        $path = $this->pathFor($case);

        if (!is_file($path)) {
            $this->download($case, $path);
        }

        return Image::fromFile($path);
    }

    public function isCached(GroundTruthCase $case): bool
    {
        return is_file($this->pathFor($case));
    }

    private function pathFor(GroundTruthCase $case): string
    {
        // findId carries a unique index and contains only characters that are
        // safe in a filename.
        return $this->directory . '/' . $case->expectedFindId . '-held-out.png';
    }

    private function download(GroundTruthCase $case, string $path): void
    {
        $bytes = file_get_contents($case->heldOutImageUrl);

        if (false === $bytes) {
            throw new \RuntimeException("Could not fetch {$case->heldOutImageUrl}");
        }

        $this->ensureDirectory();

        file_put_contents($path, $bytes);
    }

    private function ensureDirectory(): void
    {
        if (is_dir($this->directory)) {
            return;
        }

        if (!mkdir($this->directory, 0o775, true) && !is_dir($this->directory)) {
            throw new \RuntimeException("Could not create {$this->directory}");
        }
    }
}
