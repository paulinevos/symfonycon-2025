<?php

namespace App\Verification;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A photograph in the shape Jev's image endpoint accepts.
 *
 * Jev takes sides of 280–1568 px and at least 78,400 pixels in total. A phone
 * photo arrives 4032 px wide, so it has to be scaled down; a thin catalogue
 * crop can arrive 65 px wide, so it has to be padded. The format comes from the
 * bytes, not from a filename: the query may be a JPEG while the candidates are
 * the catalogue's PNGs.
 */
final readonly class JevImageAsset
{
    private const MIN_SIDE = 280;
    private const MAX_SIDE = 1568;

    public function __construct(
        private ?HttpClientInterface $httpClient = null,
        private string $cacheDirectory = '',
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function fromFile(string $source): array
    {
        $bytes = $this->bytes($source);

        return [
            'type' => 'image',
            'format' => $this->formatOf($bytes),
            'data_base64' => base64_encode($this->fitted($bytes, $this->formatOf($bytes))),
        ];
    }

    private function bytes(string $source): string
    {
        if (!str_starts_with($source, 'http://') && !str_starts_with($source, 'https://')) {
            return (string) file_get_contents($source);
        }

        if ('' === $this->cacheDirectory) {
            return $this->fetch($source);
        }

        $path = $this->cacheDirectory . '/' . md5($source) . '.img';
        if (is_file($path)) {
            return (string) file_get_contents($path);
        }

        $bytes = $this->fetch($source);
        if (!is_dir($this->cacheDirectory)) {
            mkdir($this->cacheDirectory, 0o777, true);
        }
        file_put_contents($path, $bytes);

        return $bytes;
    }

    private function fetch(string $source): string
    {
        if (null !== $this->httpClient) {
            return $this->httpClient->request('GET', $source)->getContent();
        }

        return (string) file_get_contents($source);
    }

    private function formatOf(string $bytes): string
    {
        $info = @getimagesizefromstring($bytes);

        return 'image/jpeg' === ($info['mime'] ?? '') ? 'jpeg' : 'png';
    }

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
}
