<?php

namespace App\Query;

use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Turns a photograph into words, for the callers that cannot see one.
 *
 * Only the rerankers ask for this. They are text-only, so before they can
 * compare a query against the artefacts the query has to be in words. The
 * embedding never asks: there the photograph itself is the better signal, and
 * a description would only dilute it.
 *
 * Describing the photo rather than passing it on is worth the extra call
 * because a vision model can be told to ignore the background and the hand
 * holding the object, which an embedding model cannot.
 */
final readonly class ObjectDescription
{
    private const SYSTEM_PROMPT = 'You are an image analyzer bot that helps identify objects in images.';

    private const USER_PROMPT = 'Describe the object in the foreground, ignoring the background or the person holding it. Try to focus primarily on the function. Color, shape, material, and finish of the object may also be included.';

    /**
     * The same request with appearance pushed out and purpose pushed forward.
     *
     * Retrieval matches against catalogue entries, which describe what an
     * object *is for* — "metal: iron. padlock. Locks: padlock" — and barely
     * mention what it looks like. A description that leads with colour and
     * shape spends its words on the part of the record that does not exist.
     */
    private const FUNCTION_PROMPT = 'Name the object in the foreground and say what it is used for, ignoring the background or the person holding it. Lead with its purpose and the category of object it belongs to, in the words someone would search a museum catalogue with. Mention colour, shape, material or finish only if they are needed to tell it apart from a similar object.';

    public function __construct(
        private PlatformInterface $openAi,
        private string $model = 'gpt-4o-mini',
    ) {
    }

    public function model(): string
    {
        return $this->model;
    }

    public function of(Image $image, ?string $model = null, string $prompt = 'appearance'): string
    {
        $messages = new MessageBag(
            Message::forSystem(self::SYSTEM_PROMPT),
            Message::ofUser('function' === $prompt ? self::FUNCTION_PROMPT : self::USER_PROMPT, $image),
        );

        return trim($this->openAi->invoke($model ?? $this->model, $messages)->asText());
    }
}
