<?php

namespace App\Actions;

use App\Contracts\ScriptWriter;
use App\Enums\OperatorAction;
use App\Enums\RenderStage;
use App\Enums\StoryFormat;
use App\Exceptions\GateViolationException;
use App\Models\RenderJob;
use App\Models\Story;
use App\Support\Providers\PremiseCandidate;
use App\Support\Providers\PremiseDraftSet;
use App\Support\Providers\ScriptWriterException;
use InvalidArgumentException;

/**
 * An operator's idea -> three premise candidates, kept on the story.
 *
 * At Gate 1 while the story is a draft, before the outline. One billed Sonnet
 * call per roll; the operator is expected to roll more than once, which is why
 * it runs on Sonnet (see `providers.anthropic.operations.generate_premises`).
 *
 * The order is GenerateOutline's, for GenerateOutline's reason: the call, then
 * the cost row, then anything that can fail on the output. A roll that comes
 * back short is KEPT and the shortfall named on the job row — three candidates
 * asked and two returned is two premises the operator paid for, and refusing
 * them would throw away billed work to enforce a number.
 *
 * The checks are not run here. They are run when Gate 1 is read, from the
 * stored fields (`ValidateOutlineSpine::premiseChecks()`), so a check changed
 * later reports on stored candidates in its new terms and nothing stored can
 * go stale. A candidate that fails is SHOWN with its failures, never dropped:
 * what the generator does badly is information the operator asked to see.
 */
class GeneratePremises
{
    public const COUNT = 3;

    public function __construct(
        private readonly ScriptWriter $writer,
        private readonly RecordProviderCost $costs,
    ) {}

    public function handle(Story $story, string $idea): PremiseDraftSet
    {
        self::assertReady($story, $idea);

        return RenderJob::record(
            $story->id,
            RenderStage::Premises,
            fn (RenderJob $job): PremiseDraftSet => $this->generate($story, trim($idea), $job),
        );
    }

    /**
     * Where the capability predicate stops and the premise's own conditions
     * start: a draft (OperatorAction), a single narrative, no acts, an idea.
     * Static so the dispatcher refuses in the dispatching process with the
     * same sentences, before anything is queued.
     */
    public static function assertReady(Story $story, string $idea): void
    {
        if (! OperatorAction::WritePremises->permittedAt($story->status)) {
            throw GateViolationException::actionUnavailable(OperatorAction::WritePremises, $story->status);
        }

        if ($story->format === StoryFormat::Anthology) {
            throw new InvalidArgumentException(
                'Premises are written for a single narrative. An anthology is three to five stories, '
                .'and a premise for it is a different shape that nothing here has been built or '
                .'checked for.'
            );
        }

        if ($story->acts()->exists()) {
            throw new InvalidArgumentException(
                'This story already has an outline. A premise is what the outline is written from, '
                .'so a new one now would describe a story nobody wrote.'
            );
        }

        if (mb_strlen(trim($idea)) < 10) {
            throw new InvalidArgumentException('Give the generator an idea to work from — a sentence is enough.');
        }
    }

    private function generate(Story $story, string $idea, RenderJob $job): PremiseDraftSet
    {
        $job->note(sprintf('Asking for %d premises from the idea: "%s"', self::COUNT, $idea));

        $draft = $this->writer->premises($story, $idea, self::COUNT);

        // Before anything that can fail on the output: the call was billed.
        $this->costs->handle($story, $draft->usage);

        if ($draft->candidates === []) {
            throw new ScriptWriterException(
                'The premise call returned no candidates. The call was billed and nothing was stored.',
                kind: \App\Enums\FailureKind::OutputRefused,
                facts: ['stage' => \App\Enums\RenderStage::Premises->value, 'check' => 'no_candidates'],
            );
        }

        $story->forceFill([
            'premise_candidates' => [
                'idea' => $idea,
                'idea_was_revenge_shaped' => $draft->ideaWasRevengeShaped,
                'translation' => $draft->translation,
                'requested' => $draft->requested,
                'generated_at' => now()->toIso8601String(),
                'candidates' => array_map(
                    static fn (PremiseCandidate $c): array => $c->toRow(),
                    $draft->candidates,
                ),
            ],
        ])->save();

        $job->note(sprintf(
            '%d of %d premise(s) written.%s%s',
            count($draft->candidates),
            $draft->requested,
            count($draft->candidates) < $draft->requested ? ' SHORT: kept, not refused — each one was paid for.' : '',
            $draft->ideaWasRevengeShaped ? ' The idea was revenge-shaped: '.$draft->translation : '',
        ));

        return $draft;
    }
}
