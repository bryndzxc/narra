<?php

namespace App\Enums;

use App\Exceptions\ClassifiedFailure;
use Throwable;

/**
 * What kind of failure a `render_jobs` row records.
 *
 * A FACT about the run, written once by `RenderJob::fail()` and never
 * rewritten. What to DO about it is not stored anywhere: `FailureRemedy`
 * builds that from this case, the facts beside it and the code running when
 * the page is read. That split is the whole point. Story 36's act 2 row kept
 * telling the operator a locale rule the code had dropped, because the rule
 * was written into the stored message; a kind cannot go out of date, because
 * a failure that happened stays the kind of failure it was.
 *
 * ---------------------------------------------------------------------------
 * A CASE EXISTS ONLY WHERE A MOVE IS KNOWN — AND THAT IS TWO DIFFERENT THINGS
 * ---------------------------------------------------------------------------
 *
 * 1. **A known repair.** The cause is certain from the failure itself (a
 *    quota body, a missing Python module, a status that forbids the move) or
 *    the repair has been measured working here (the act summary bound, a
 *    timed-out still). `Remedy::known()`.
 *
 * 2. **One certain move, outcome unmeasured.** There is exactly one thing to
 *    do, it is certain what it does, and nothing has measured whether it
 *    succeeds. An outline refused by a check after the call: nothing was
 *    stored, the story has no acts, and Write asks for the outline again — no
 *    other input exists and no other move is available. `Remedy::
 *    unmeasuredMove()`: the button, and a separate sentence saying plainly
 *    that a pass is unmeasured.
 *
 * 3. **No move known.** `Unclassified`, and the page says "No known repair."
 *    That is not a gap to fill with a plausible sentence. "Raise
 *    ANTHROPIC_MAX_TOKENS" and "re-run it, it's variance" were plausible
 *    sentences, and both cost real runs.
 *
 * **2 and 3 were one rule until 2026-09-18**, written as "a failure whose only
 * move is 'run it again and see' is not a kind". That collapsed "no move
 * exists" into "the move exists and is unmeasured", and story 37's outline —
 * refused for a cast with no narrator — got "No known repair." beside the one
 * button that repairs it. What separates 2 from the re-run advice that cost
 * runs is not the move, it is the CLAIM: those sentences said the re-run would
 * work, or named a knob that was not the cause. Case 2 names the move and says
 * outright that its outcome is not known, which is what makes it safe to say.
 * A re-run whose success is unmeasured is only case 2 when it is the ONLY
 * move; where editing an input, waiting, or a different button might be the
 * answer, it stays case 3.
 *
 * Adding a case means adding its remedy in `FailureRemedy`, and
 * `RemediesNameRealKnobsTest` renders every case at every status to check
 * that remedy names only commands, flags and env vars that exist.
 *
 * Stored as a plain string, not a MySQL ENUM. The row this is written to is
 * the failure record itself, and an ENUM column that has not caught up with a
 * new case truncates the write (CostUnit::TotalTokens lost a billed outline
 * that way). A failure must never be the thing that fails to record.
 */
enum FailureKind: string
{
    /** Nothing classified it. The page says "No known repair." */
    case Unclassified = 'unclassified';

    /** A denied locale term. Facts: stage. */
    case LocaleRefused = 'locale_refused';

    /** An act's summary came back over Act::SUMMARY_MAX_CHARS. Facts: act. */
    case ActSummaryOverBound = 'act_summary_over_bound';

    /** A text call hit its output ceiling. Facts: operation. */
    case Truncated = 'truncated';

    /** The model declined the request (stop_reason refusal). Facts: operation. */
    case ModelDeclined = 'model_declined';

    /**
     * The outline came back and one of GenerateOutline's checks refused it,
     * after the cost row, storing nothing. Facts: check — one of
     * `OUTLINE_CHECKS`. A certain move with an unmeasured outcome.
     */
    case OutlineRefused = 'outline_refused';

    /**
     * Any other text stage got its output back and a check after the call
     * refused it, storing nothing. Facts: `stage` (a RenderStage value),
     * `check` — one of `OUTPUT_CHECKS` — and `act` where there is one.
     *
     * The outline's case, for every stage after it. Built 2026-09-19 as one
     * family rather than one stage at a time, because that is how it had been
     * arriving: the outline refusals were wired on 2026-09-18, and the act
     * scripts' chapter shapes and story 38's cast refusal then each reached
     * the page as "No known repair." beside the one button that re-runs
     * them. Every refusal of this kind has the same shape — billed, nothing
     * stored, and running the stage again is the only move the operator has
     * — so FailureRemedy builds all of them from one table of stages.
     */
    case OutputRefused = 'output_refused';

    /** A scene with people in frame has a character with no picked reference. */
    case MissingCharacterReference = 'missing_character_reference';

    /** The story has no voice_id, so narration has no narrator. */
    case NoVoice = 'no_voice';

    /** ElevenLabs said the allowance is spent. */
    case SpeechQuotaExhausted = 'speech_quota_exhausted';

    /** ElevenLabs rejected the key, and the body does not say quota. */
    case SpeechKeyRejected = 'speech_key_rejected';

    /** The WhisperX interpreter cannot import whisperx. */
    case AlignerNotInstalled = 'aligner_not_installed';

    /** The worker disagrees with the dispatch about provider or fingerprint. Facts: queue. */
    case StaleWorker = 'stale_worker';

    /** A render stage needs narration audio a scene does not have. Facts: scene. */
    case NarrationMissing = 'narration_missing';

    /** Concat found a scene clip missing or not matching its audio. Facts: scene. */
    case ClipMissing = 'clip_missing';

    /** A vendor call timed out in transit. Facts: stage. */
    case VendorTimeout = 'vendor_timeout';

    /** fal's content checker refused a scene's frame. Facts: scene. */
    case ContentRefused = 'content_refused';

    /** A still on disk does not decode. Facts: scene. */
    case UndecodableStill = 'undecodable_still';

    /** A column cannot hold a value the code wrote. Facts: column. */
    case SchemaDrift = 'schema_drift';

    /**
     * The checks GenerateOutline runs on a returned outline, and what each
     * refused it for. The key is the `check` fact on an OutlineRefused row.
     */
    public const OUTLINE_CHECKS = [
        'act_count' => 'a different number of acts from the number asked for',
        'act_text_bounds' => 'an act title, summary or beat over its bound',
        'cast_structure' => 'a cast that cannot be used',
        'reused_name' => 'a name a recent story already used',
        'coded_terms' => 'an accomplice built on orientation-coded material',
        'malformed_response' => 'a response that was not the JSON its schema required',
    ];

    /**
     * The checks the later text stages run on what came back, and what each
     * refused it for. The key is the `check` fact on an OutputRefused row.
     */
    public const OUTPUT_CHECKS = [
        'no_candidates' => 'no premise candidates',
        'chapter_shape' => 'chapters outside the shape the prompt states (their count, a title, the word '
            .'floor, or a chapter that did not end on a complete sentence)',
        'point_of_view_chapter' => 'a point-of-view chapter that was not asked for, is misplaced or is told '
            .'under the wrong name',
        'character_text' => 'character text the guard refuses: a hedge, a carried object, a pose, an '
            .'expression or ageing texture, in a field pasted into every scene',
        'cast_names' => 'a cast with none of the outline cast\'s names in it',
        'scene_bounds' => 'a scene frame or expression over its bound',
        'scene_tiling' => 'scenes that do not cover the act\'s sentences exactly once, in order',
        'titles_over_limit' => 'every title variant over the hard limit',
        'description_over_limit' => 'an assembled description over YouTube\'s limit',
        // Thrown by the writer itself, after the call, through
        // TalksToClaude::refuseOutput(), which writes the cost row first.
        'empty_output' => 'nothing usable in it: no act text, no characters, no scenes, or no titles or tags',
        'malformed_response' => 'a response that was not the JSON its schema required',
    ];

    public function label(): string
    {
        return match ($this) {
            self::Unclassified => 'Unclassified',
            self::LocaleRefused => 'Locale term refused',
            self::ActSummaryOverBound => 'Act summary over its bound',
            self::Truncated => 'Output ceiling hit',
            self::ModelDeclined => 'Declined by the model',
            self::OutlineRefused => 'Outline refused by a check',
            self::OutputRefused => 'Output refused by a check',
            self::MissingCharacterReference => 'Character with no reference',
            self::NoVoice => 'No narrator voice',
            self::SpeechQuotaExhausted => 'Narration allowance spent',
            self::SpeechKeyRejected => 'Narration key rejected',
            self::AlignerNotInstalled => 'Aligner not installed',
            self::StaleWorker => 'Stale worker',
            self::NarrationMissing => 'Narration audio missing',
            self::ClipMissing => 'Scene clip missing or stale',
            self::VendorTimeout => 'Vendor call timed out',
            self::ContentRefused => 'Frame refused by content checker',
            self::UndecodableStill => 'Still does not decode',
            self::SchemaDrift => 'Column cannot hold the value',
        };
    }

    /**
     * The kind and facts of any throwable, for `RenderJob::fail()`.
     *
     * A `ClassifiedFailure` says what it is. Everything else is read here, from
     * exceptions this app does not own (a vendor client, the database) whose
     * class and message are stable enough to recognise. Anything unrecognised
     * is Unclassified, never a best guess.
     *
     * @return array{0: self, 1: array<string, scalar>}
     */
    public static function of(Throwable $e): array
    {
        // Walk the chain, and keep walking past a wrapper that is classified
        // as nothing: TalksToClaude wraps a transport failure in an
        // unclassified ScriptWriterException, and stopping there would hide a
        // classified cause underneath.
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof ClassifiedFailure && $current->failureKind() !== self::Unclassified) {
                return [$current->failureKind(), $current->failureFacts()];
            }
        }

        // The unowned exceptions are read from the whole chain too, for the
        // same reason.
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            $read = self::ofUnowned($current);

            if ($read[0] !== self::Unclassified) {
                return $read;
            }
        }

        return [self::Unclassified, []];
    }

    /** @return array{0: self, 1: array<string, scalar>} */
    private static function ofUnowned(Throwable $e): array
    {

        $message = $e->getMessage();

        if ($e instanceof \Illuminate\Database\QueryException
            && preg_match("/Data truncated for column '([^']+)'/", $message, $column)) {
            return [self::SchemaDrift, ['column' => $column[1]]];
        }

        // cURL error 28 is a timeout, whichever client wrapped it. Laravel's
        // HTTP client throws ConnectionException around Guzzle's
        // ConnectException; the ones in failed_jobs arrived unwrapped.
        if (($e instanceof \Illuminate\Http\Client\ConnectionException || $e instanceof \GuzzleHttp\Exception\ConnectException)
            && str_contains($message, 'cURL error 28')) {
            return [self::VendorTimeout, []];
        }

        return [self::Unclassified, []];
    }
}
