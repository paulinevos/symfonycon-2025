<?php

namespace App\Verification;

/**
 * A candidate the visual judge cannot see.
 *
 * Distinct from a failed call: the sheet predates the images, and thirteen
 * candidates have no photograph. That is a gap in the data, not a broken run,
 * so it is recorded and skipped rather than counted as an error.
 */
final class MissingPhotograph extends \RuntimeException
{
    public static function forCandidate(string $findId): self
    {
        return new self(sprintf('No photograph for candidate %s.', $findId));
    }
}
