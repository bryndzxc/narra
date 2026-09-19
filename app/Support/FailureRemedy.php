<?php

namespace App\Support;

use App\Enums\FailureKind;
use App\Enums\OperatorAction;
use App\Models\Story;

/**
 * The repair for a failure, built when the page is read.
 *
 * ---------------------------------------------------------------------------
 * THREE RULES, EACH LEARNED BY PAYING FOR ITS ABSENCE
 * ---------------------------------------------------------------------------
 *
 * 1. **Built at display time, from current code, never stored.** A row keeps
 *    the kind and the facts; this class turns them into a sentence against
 *    the code that is running now. Advice written into a stored error is true
 *    on the day it is written and read for as long as the row exists (story
 *    36's act 2 row repeated a dropped locale rule).
 *
 * 2. **Unknown says so, plainly.** A kind with no known repair returns
 *    `Remedy::unknown()` and the page renders "No known repair." — no
 *    softened suggestion, no "you could try". "Raise ANTHROPIC_MAX_TOKENS" and
 *    "re-run it, it's variance" were both plausible, both unmeasured, and
 *    both cost a run. A move that is certain and whose OUTCOME is unmeasured
 *    is different, and is `Remedy::unmeasuredMove()`: the button, plus a
 *    separate sentence saying the outcome is not known. What made the old
 *    advice wrong was claiming it would work, not naming the move.
 *
 * 3. **Name the button, and only one the story has.** An action is offered
 *    only when `OperatorAction::permittedAt()` allows it at the story's
 *    current status, the same predicate the button and the command consult.
 *    When it is not permitted the remedy says which status blocks it rather
 *    than pointing at a button that will refuse. The terminal command is
 *    given beside it for the same move.
 *
 * Every command, flag and env var named here is checked to exist by
 * RemediesNameRealKnobsTest, for every kind at every status.
 */
final class FailureRemedy
{
    /**
     * @param  array<string, mixed>  $facts
     * @param  string|null  $stage  the row's stage, for a kind whose exception
     *                              could not say where it was thrown (a locale refusal, a decline
     *                              from an operation); a fact of the same name wins
     */
    public static function for(FailureKind $kind, array $facts, Story $story, ?string $stage = null): Remedy
    {
        if ($stage !== null) {
            $facts += ['stage' => $stage];
        }

        $slug = (string) $story->slug;
        $scene = isset($facts['scene']) ? (int) $facts['scene'] : null;
        $sceneWords = $scene === null ? 'This scene' : "Scene {$scene}";

        return match ($kind) {
            FailureKind::Unclassified => Remedy::unknown(),

            FailureKind::LocaleRefused => in_array($facts['stage'] ?? null, ['outline', 'act_scripts'], true)
                ? self::withAction(
                    'This stage no longer fails on a denied term: it keeps the text, and Gate 1 shows the '
                    .'phrase in red. One in an outline field is yours to judge; one in an act script has to '
                    .'come out before scene drafting, which refuses it. Writing again writes only the acts '
                    .'that have no script.',
                    OperatorAction::WriteScript,
                    'stories.outline',
                    $story,
                    "php artisan story:write {$slug}",
                )
                // A cast description or a scene frame still refuses a denied
                // term, and the only move is to generate it again. That was
                // "No known repair." until 2026-09-19, which told the operator
                // no move existed beside the button that makes it; it is the
                // move, with its outcome said to be unmeasured.
                : self::rerunStage(
                    (string) ($facts['stage'] ?? ''),
                    $facts,
                    $story,
                    'What came back carried a term the setting denies, so this stage refused it; the call '
                    .'was billed and nothing was stored.',
                    'Nothing has measured whether a second attempt comes back without the term. Each '
                    .'attempt is billed again.',
                ),

            FailureKind::ActSummaryOverBound => isset($facts['act'])
                ? self::withAction(
                    sprintf(
                        'Nothing from act %1$d was stored. Writing again writes only the acts with no '
                        .'script, so it re-runs act %1$d alone. Measured: the three act summaries refused '
                        .'by this bound (story 30 act 3, story 31 acts 1 and 4) each passed on their first '
                        .'retry. The bound (Act::SUMMARY_MAX_CHARS) is not moved to fit a result.',
                        (int) $facts['act'],
                    ),
                    OperatorAction::WriteScript,
                    'stories.outline',
                    $story,
                    sprintf('php artisan story:write %s --acts-only=%d', $slug, (int) $facts['act']),
                )
                : Remedy::unknown(),

            FailureKind::Truncated => self::truncation((string) ($facts['operation'] ?? '')),

            // Only the outline has an operator-controlled input the model can
            // decline: the premise. Every later call is built from text the
            // gates have already approved, so running the stage again is the
            // only move there is.
            FailureKind::ModelDeclined => ($facts['operation'] ?? null) === 'generate_outline'
                ? self::withAction(
                    'The model declined to write from this premise; the call was billed and nothing was '
                    .'stored. The premise is the input, and it is editable at Gate 1 before the outline is '
                    .'written again.',
                    OperatorAction::WriteScript,
                    'stories.outline',
                    $story,
                    "php artisan story:write {$slug}",
                    'No decline has been observed in this app, so nothing has measured how much of a '
                    .'premise has to change.',
                )
                : self::rerunStage(
                    self::STAGE_OF_OPERATION[$facts['operation'] ?? ''] ?? (string) ($facts['stage'] ?? ''),
                    $facts,
                    $story,
                    'The model declined this call; it was billed and nothing was stored.',
                    'No decline has been observed in this app, so nothing has measured whether the same '
                    .'input passes a second time. Each attempt is billed again.',
                ),

            FailureKind::OutlineRefused => self::outlineRefused($facts, $story),

            // The stage from the row where there is one, and from the
            // operation where there is not: a refusal the writer throws
            // (TalksToClaude::refuseOutput) knows its operation, not its stage.
            FailureKind::OutputRefused => self::rerunStage(
                (string) ($facts['stage'] ?? self::STAGE_OF_OPERATION[$facts['operation'] ?? ''] ?? ''),
                $facts,
                $story,
                sprintf(
                    '%s came back with %s. The call was billed and nothing was stored.',
                    isset($facts['act']) ? 'Act '.(int) $facts['act'] : 'It',
                    FailureKind::OUTPUT_CHECKS[$facts['check'] ?? ''] ?? 'something a check refused',
                ),
                self::unmeasuredRetry((string) ($facts['stage'] ?? ''), (string) ($facts['check'] ?? '')),
            ),

            FailureKind::MissingCharacterReference => Remedy::known(
                'A character in frame has no reference picked, so the still was not generated from '
                .'text. Generate and pick that character\'s sheet on the cast page, then generate scene '
                .'assets again: stills that already exist are not re-billed.',
                null,
                'Open the cast sheets',
                route('stories.characters', $story),
            ),

            FailureKind::NoVoice => Remedy::known(
                'The story has no narrator voice, so nothing was narrated or billed. The channel keeps '
                .'one voice per narrator gender (CLAUDE.md, Voice): set it before narrating, then generate '
                .'scene assets again.',
                "php artisan voices:list --set={$slug} --voice=<voice id>",
            ),

            FailureKind::SpeechQuotaExhausted => self::withAction(
                'The ElevenLabs allowance is spent. The plan stops at its limit rather than billing past '
                .'it, so the scenes after this one are unnarrated and the earlier ones are paid for. Top up '
                .'or upgrade the plan, then generate scene assets again: scenes that already have audio '
                .'are skipped and not re-billed.',
                OperatorAction::RegenerateAssets,
                'stories.scenes',
                $story,
                "php artisan assets:generate {$slug}",
            ),

            FailureKind::SpeechKeyRejected => Remedy::known(
                'ElevenLabs rejected the API key, and the response did not say the allowance was spent. '
                .'Check ELEVENLABS_API_KEY in .env and that the key carries text_to_speech permission, '
                .'then restart the assets worker so it reads the new value.',
                WorkerServices::restartCommand((string) config('render.queues.assets')),
            ),

            FailureKind::AlignerNotInstalled => self::withAction(
                'The interpreter at WHISPERX_PYTHON cannot import whisperx, so no timings were written '
                .'and nothing was billed. Install it into that interpreter ("<python> -m pip install '
                .'whisperx"), confirm with Check without spending on Gate 2 or the command below, then '
                .'re-run word timings only: that path cannot bill.',
                OperatorAction::AlignTimings,
                'stories.scenes',
                $story,
                "php artisan narration:preflight {$slug} --align",
            ),

            FailureKind::StaleWorker => self::staleWorker($facts, $story),

            FailureKind::NarrationMissing => self::withAction(
                "{$sceneWords} has no narration audio, so it cannot be timed or rendered. Generating "
                .'scene assets narrates the scenes that have none and skips the rest; then dispatch the '
                .'render again.',
                OperatorAction::RegenerateAssets,
                'stories.scenes',
                $story,
                "php artisan assets:generate {$slug}",
            ),

            FailureKind::ClipMissing => self::withAction(
                "{$sceneWords}'s clip is missing or does not match its audio. Dispatching the render again "
                .'keeps every clip that still matches and renders this one.',
                OperatorAction::DispatchRender,
                'stories.preview',
                $story,
                "php artisan render:dispatch {$slug}",
            ),

            // Measured on images only. A timed-out narration call may already
            // have been generated and billed on ElevenLabs' side, so retrying
            // it is a spending question nobody has answered.
            FailureKind::VendorTimeout => ($facts['stage'] ?? null) === 'images'
                ? self::withAction(
                    'A still timed out in transit. Generating scene assets again retries the failed '
                    .'scenes and skips the rest. Measured once: story 28 scene 131 retried clean on the '
                    .'first attempt for $0.035. Whether fal billed the call that timed out is not known.',
                    OperatorAction::RegenerateAssets,
                    'stories.scenes',
                    $story,
                    "php artisan assets:generate {$slug}",
                )
                : Remedy::unknown(),

            FailureKind::ContentRefused => self::contentRefused($sceneWords, $story),

            // The repair is certain and nothing performs it: a still that is
            // on disk and marked generated is not picked up by a retry, which
            // only takes scenes that are missing or failed.
            FailureKind::UndecodableStill => Remedy::known(
                "{$sceneWords}'s still is on disk but does not decode, so it has to be generated again. "
                .'Nothing in the console or in assets:generate does that today: a retry only takes scenes '
                .'whose still is missing or failed, and this one is recorded as generated.',
            ),

            FailureKind::SchemaDrift => Remedy::known(
                sprintf(
                    'The database column%s cannot hold a value the code now writes, so this stage '
                    .'recorded nothing. The command below names the column and the values it is missing; '
                    .'the repair is a migration that adds them.',
                    isset($facts['column']) ? ' "'.$facts['column'].'"' : '',
                ),
                'php artisan schema:enum-drift',
            ),
        };
    }

    /**
     * The same repair for an exception caught in a terminal, where there is no
     * row to read it from. `story:write` and `story:scenes` run stages in the
     * foreground and print the exception; the advice left the message when it
     * stopped being stored, so it arrives here instead, from the same builder
     * the page uses.
     *
     * @return array<int, string> lines to print
     */
    public static function consoleLines(\Throwable $e, Story $story, ?string $stage = null): array
    {
        [$kind, $facts] = FailureKind::of($e);
        $remedy = self::for($kind, $facts + array_filter(['stage' => $stage]), $story);

        if (! $remedy->known) {
            return ['No known repair.'];
        }

        return array_values(array_filter([
            'Repair: '.$remedy->text,
            $remedy->unmeasured === null ? null : 'Not measured: '.$remedy->unmeasured,
            $remedy->actionUrl === null ? null : "{$remedy->actionLabel}: {$remedy->actionUrl}",
            $remedy->command,
        ]));
    }

    /**
     * The measured remedy from config, read now. An operation with no
     * `truncation_remedy` has none known, and the page says so.
     */
    private static function truncation(string $operation): Remedy
    {
        $remedy = config("providers.anthropic.operations.{$operation}.truncation_remedy");

        return is_string($remedy) && trim($remedy) !== ''
            ? Remedy::known(trim($remedy))
            : Remedy::unknown();
    }

    /** @param  array<string, mixed>  $facts */
    private static function staleWorker(array $facts, Story $story): Remedy
    {
        $queue = (string) ($facts['queue'] ?? config('render.queues.assets'));
        $command = WorkerServices::restartCommand($queue);

        return Remedy::known(
            sprintf(
                'The worker on "%s" booted on older code or config than the process that dispatched this '
                .'job, so it refused before generating or billing anything. Restart it%s, then generate '
                .'scene assets again from Gate 2.',
                $queue,
                $command === null
                    ? ' (no worker service is mapped to this queue, so there is no restart command to offer)'
                    : ' from an elevated PowerShell prompt',
            ),
            $command,
        );
    }

    private static function contentRefused(string $sceneWords, Story $story): Remedy
    {
        $text = "fal's content checker refused {$sceneWords}'s frame, and it sees only the frame, never "
            .'the narration that makes the picture benign. A retry sends the same prompt and is refused '
            .'again. The frame has to be rewritten as a composition, not a word swap (story 28 scene 17 '
            .'was a person down and another standing over them; rewritten with both seated, it passed '
            .'first time). Editing a frame is done at Gate 2';

        if (OperatorAction::ReopenScenesGate->permittedAt($story->status)) {
            return Remedy::known(
                $text.', which has been approved: reopening it is what makes the frame editable.',
                null,
                OperatorAction::ReopenScenesGate->label(),
                route('stories.scenes', $story),
            );
        }

        return Remedy::known(
            $text.'.',
            null,
            'Open Gate 2',
            route('stories.scenes', $story),
        );
    }

    /**
     * A remedy whose move is an operator capability: the button when the
     * story's status allows it, and the status named when it does not.
     */
    private static function withAction(
        string $text,
        OperatorAction $action,
        string $route,
        Story $story,
        ?string $command = null,
        ?string $unmeasured = null,
    ): Remedy {
        if ($action->permittedAt($story->status)) {
            return $unmeasured === null
                ? Remedy::known($text, $command, $action->label(), route($route, $story))
                : Remedy::unmeasuredMove($text, $unmeasured, $command, $action->label(), route($route, $story));
        }

        $blocked = $text.sprintf(
            ' "%s" is not available while the story is at "%s", so it is not offered here.',
            $action->label(),
            $story->status->value,
        );

        return $unmeasured === null
            ? Remedy::known($blocked)
            : Remedy::unmeasuredMove($blocked, $unmeasured);
    }

    /**
     * Which stage a text operation runs in, for a decline that names only its
     * operation. The metadata stage makes three calls and all three are here.
     */
    private const STAGE_OF_OPERATION = [
        'generate_premises' => 'premises',
        'generate_outline' => 'outline',
        'generate_act_script' => 'act_scripts',
        'extract_characters' => 'extract_cast',
        'draft_scenes' => 'draft_scenes',
        'generate_titles' => 'metadata',
        'generate_copy' => 'metadata',
        'generate_tags' => 'metadata',
    ];

    /**
     * A text stage whose billed output was refused or declined, storing
     * nothing: the move that runs it again, with the caveat that its outcome
     * is not known.
     *
     * ONE TABLE FOR THE FAMILY, because it arrived stage by stage: the outline
     * refusals were wired on 2026-09-18, and then act-script chapter shapes
     * and story 38's cast refusal each reached the page as "No known repair."
     * beside the button that re-runs them. The move differs by stage (which
     * button, which command, what re-running touches); the shape of the
     * failure does not, so it is written once. A stage not in `rerun()` stays
     * unknown — an asset stage is not a text call and has none of these.
     *
     * @param  array<string, mixed>  $facts
     */
    private static function rerunStage(string $stage, array $facts, Story $story, string $what, string $unmeasured): Remedy
    {
        $move = self::rerun($stage, $facts, $story);

        if ($move === null) {
            return Remedy::unknown();
        }

        if ($move instanceof Remedy) {
            return $move;
        }

        [$action, $route, $command, $does] = $move;

        return self::withAction("{$what} {$does}", $action, $route, $story, $command, $unmeasured);
    }

    /**
     * The move that runs a text stage again, and what running it touches.
     *
     * Null for a stage with no such move. A Remedy where the state since the
     * failure makes the move wrong: a cast refused on a rebuild left the old
     * cast standing, and the draft button would keep it rather than extract.
     *
     * @param  array<string, mixed>  $facts
     * @return array{0: OperatorAction, 1: string, 2: string|null, 3: string}|Remedy|null
     */
    private static function rerun(string $stage, array $facts, Story $story): array|Remedy|null
    {
        $slug = (string) $story->slug;
        $act = isset($facts['act']) ? (int) $facts['act'] : null;

        return match ($stage) {
            'premises' => [
                OperatorAction::WritePremises,
                'stories.outline',
                null,
                'Writing premises again asks for three new ones from the idea, which is editable beside '
                .'the button; the previous roll is kept until a new one replaces it.',
            ],

            'act_scripts' => [
                OperatorAction::WriteScript,
                'stories.outline',
                "php artisan story:write {$slug}".($act === null ? '' : " --acts-only={$act}"),
                $act === null
                    ? 'Writing again writes only the acts that have no script.'
                    : "Writing again writes only the acts that have no script, so it re-runs act {$act} alone.",
            ],

            'extract_cast' => $story->characters()->exists()
                ? Remedy::known(
                    'The cast stored before this run is still on the story: a refused extraction replaces '
                    .'nothing, so there is nothing of this failure left to repair.',
                )
                : [
                    OperatorAction::DraftSceneList,
                    'stories.scenes',
                    "php artisan story:scenes {$slug}",
                    'The story still has no cast. The draft button extracts it again first, up to two billed '
                    .'attempts, and drafts the scenes only once a cast is stored.',
                ],

            'draft_scenes' => [
                OperatorAction::DraftSceneList,
                'stories.scenes',
                $act !== null && $story->scenes()->exists()
                    ? "php artisan story:scenes {$slug} --acts={$act}"
                    : "php artisan story:scenes {$slug}",
                'Nothing from this draft was stored, including acts drafted before the refused one. '
                .'Drafting again keeps the cast, so extraction is not billed again.',
            ],

            'metadata' => [
                OperatorAction::WriteMetadata,
                'stories.metadata',
                "php artisan metadata:generate {$slug}",
                'The sheet was not written. Writing it again asks for the copy again, three billed calls.',
            ],

            default => null,
        };
    }

    /**
     * What is and is not known about a second attempt, per stage and check.
     *
     * Everything is unmeasured except the cast, which has a record, and the
     * record is not encouraging, so it is said rather than softened.
     */
    private static function unmeasuredRetry(string $stage, string $check): string
    {
        if ($stage === 'extract_cast' && $check === 'character_text') {
            return 'Not under the current prompt. On the ledger, 7 of 24 extraction runs ended refused like '
                .'this one, and the three runs made within minutes of a refusal (story 21 twice, story 32 '
                .'once) were refused again; the casts stored after them followed a change to the prompt, '
                .'the guard or the retry. Each run is up to two billed calls of about $0.04-0.06.';
        }

        return 'Nothing has measured whether a second attempt passes this check. Each attempt is billed again.';
    }

    /**
     * An outline a check refused after the call: one certain move, outcome
     * unmeasured.
     *
     * Certain because nothing was stored and Write only re-runs the outline on
     * a story with no acts (WriteStoryJob), where the first press stops after
     * the outline, before any act is bought. Unmeasured because no outline
     * refused by one of these checks has been retried and recorded — story
     * 37's re-run on 2026-09-18 is the first, under a schema that no longer
     * lets its cause happen.
     *
     * If the story has acts by the time the page is read, an outline was
     * written since and Write now writes act scripts, so offering it as the
     * repair would be false. A successful re-run overwrites the stage's row,
     * so this is rare; it is handled rather than assumed.
     *
     * @param  array<string, mixed>  $facts
     */
    private static function outlineRefused(array $facts, Story $story): Remedy
    {
        $what = FailureKind::OUTLINE_CHECKS[$facts['check'] ?? ''] ?? 'a check on the returned outline';

        if ($story->acts()->exists()) {
            return Remedy::known(
                "The outline was refused for {$what}. The story has an outline now, written after this "
                .'failure, so there is nothing of it left to repair: Write writes act scripts from here, '
                .'not a new outline.',
            );
        }

        return self::withAction(
            "The outline was refused for {$what}. The call was billed and nothing was stored, so the "
            .'story still has no acts and Write asks for the whole outline again. The first press stops '
            .'after the outline, before any act is bought.',
            OperatorAction::WriteScript,
            'stories.outline',
            $story,
            "php artisan story:write {$story->slug} --outline-only",
            'Nothing has measured whether a second outline passes this check. Each attempt is one billed '
            .'outline call.',
        );
    }
}
