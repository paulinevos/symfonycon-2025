<?php

namespace App\Evaluation;

use Symfony\AI\Platform\Message\Content\Image;

/**
 * One way of turning a query image into a ranked list of find IDs.
 *
 * Later slices add implementations — chunked retrieval, reranked retrieval —
 * and the evaluation command treats them all the same, which is what makes the
 * before/after numbers comparable.
 */
interface RetrievalPipeline
{
    /**
     * @return list<string> find IDs, best match first
     */
    public function retrieve(Image $query, int $limit): array;

    /**
     * The name this pipeline is selected by on the command line. Static so the
     * service locator can index on it without instantiating every pipeline.
     */
    public static function name(): string;
}
