<?php

namespace App\Verification;

/**
 * Appends one JSON object per line, which survives a run that dies halfway.
 */
final readonly class JsonLinesWriter
{
    /**
     * @param resource $stream
     */
    public function __construct(
        private mixed $stream,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public function write(array $row): void
    {
        fwrite($this->stream, json_encode($row, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . \PHP_EOL);
    }
}
