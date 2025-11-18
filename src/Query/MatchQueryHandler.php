<?php

namespace App\Query;

use App\Document\Artefact;
use App\Document\MatchCandidate;
use App\Document\MatchQueryResult;
use App\ValueObject\RequestedObject;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Store\Document\VectorizerInterface;

final readonly class MatchQueryHandler
{
    public function __construct(
        private PlatformInterface   $openAi,
        private VectorizerInterface $vectorizer,
        private DocumentManager     $dm,
    ) {}

    public function __invoke(MatchQuery $command): MatchQueryResult
    {
        $description = $command->prompt ?? '';

        $images = $command->images;
        $image = array_pop($images);

        if ($image) {
            $messages = new MessageBag(
                Message::forSystem('You are an image analyzer bot that helps identify objects in images.'),
                Message::ofUser(
                    'Describe the object in the foreground, ignoring the background or the person holding it. Try to focus especially on the function, color, shape, material, and finish of the object.',
                    $image
                ),
            );
            $result = $this->openAi->invoke('gpt-4o-mini', $messages);
            $description = $result->asText();
        }

        /** @var VectorizerInterface $vectorizer */
        $vectorized = $this->vectorizer->vectorize(RequestedObject::new($description, $image));

        $builder = $this->dm->getRepository(Artefact::class)
            ->createAggregationBuilder()
            ->hydrate(MatchCandidate::class);

        $builder
            ->vectorSearch()
            ->limit(10)
            ->numCandidates(200)
            ->index('default')
            ->path('embeddingVector')
            ->queryVector($vectorized->vector->getData())
            ->project()
            ->field('_id')->expression(0)
            ->field('artefact')->expression('$$ROOT')
            ->field('score')->meta('vectorSearchScore');
        $matches = $builder
            ->getAggregation()
            ->execute();

        $result = new MatchQueryResult($description, ...$matches->toArray());

        $this->dm->persist($result);
        $this->dm->flush();

        return $result;
    }
}
