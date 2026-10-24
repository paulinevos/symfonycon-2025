<?php

namespace App\Verification;

/**
 * What the judge is shown of the query.
 *
 * Visual is what the app can do: two photographs. Text is the ablation — the
 * query reduced to its name — and separates what the model knows from what the
 * picture adds.
 */
enum Presentation: string
{
    case Visual = 'visual';
    case Text = 'text';

    public static function fromOption(string $value): self
    {
        return self::tryFrom($value)
            ?? throw new \InvalidArgumentException(sprintf('Unknown presentation "%s"; use visual or text.', $value));
    }
}
