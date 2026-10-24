<?php

namespace App\Verification;

/**
 * One candidate, one decision, no knowledge of the other candidates.
 *
 * Pointwise on purpose: a choice over the whole shortlist asks the model to
 * discriminate against thirty distractors at once, which is the same task the
 * retrieval order is already good at. Assessing one candidate at a time asks
 * the question the app cares about, and leaves the combining rule to the
 * scorer where it can be varied without another API call.
 */
interface CandidateJudge
{
    public function name(): string;

    public function assess(CandidateUnderTest $candidate): CandidateAssessment;
}
