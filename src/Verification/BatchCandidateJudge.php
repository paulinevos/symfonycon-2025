<?php

namespace App\Verification;

/**
 * A judge that decides the whole shortlist in one request.
 *
 * This is the shape the old rerankers used: every candidate in a single
 * `choice`, so the model discriminates against its distractors at once. Kept
 * as a separate interface from {@see CandidateJudge} because a batch judge
 * cannot report a per-candidate skip or failure without discarding the call.
 */
interface BatchCandidateJudge
{
    public function name(): string;

    /**
     * @param list<CandidateUnderTest> $candidates
     *
     * @return array<string, CandidateAssessment> keyed by find id
     */
    public function assessAll(array $candidates): array;
}
