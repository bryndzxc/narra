<?php

namespace App\Support;

use App\Enums\Gate;
use App\Enums\StoryStatus;
use InvalidArgumentException;

/**
 * Every sentence on a gate page that names an action or claims a position, and
 * the one place that decides whether either is true where it renders.
 *
 * ---------------------------------------------------------------------------
 * THE DEFECT
 * ---------------------------------------------------------------------------
 *
 * Gate 1 on a story at `scenes_drafted` said three things on one screen:
 *
 *   the strip           "Reopening is not available: scenes have already been
 *                        drafted against this outline."
 *   structural warnings "None of these block approval ... all of them are
 *                        cheaper to fix here than at Gate 3."
 *   locale terms        "Judging these is yours."
 *
 * Two of those offer a decision that does not exist in that state. Nothing was
 * wrong with either sentence when it was written — they were written for the
 * state the page was designed against, and then rendered in the state the page
 * spends most of its life in. That is `.dash.quiet`'s defect wearing prose
 * instead of layout, and Gate 2 had already fixed one instance of it by passing
 * its advisory heading in per state.
 *
 * One instance fixed by hand is not a mechanism. This is the mechanism.
 *
 * ---------------------------------------------------------------------------
 * HOW IT CANNOT DRIFT
 * ---------------------------------------------------------------------------
 *
 * Every clause below has exactly two phrasings and one capability that chooses
 * between them. The claiming half is built out of a fragment in `CLAUSES`, so
 * the fragment is in the emitted sentence by construction, and the test that
 * checks no gate page named an action it did not have greps for those same
 * fragments. One list, two readers, and a reworded clause moves both.
 *
 * A second kind of clause joined later and does not fit that sentence: one
 * that claims a POSITION rather than an action, where every phrasing is a
 * claim and there is no phrasing that asserts nothing. See the position axis
 * below for the defect that produced it.
 *
 * The claim is a fragment rather than a whole sentence because the defect is
 * the claim and not the sentence: "N thing(s) block approval" on a page that
 * cannot be approved is the same page as "None of these block approval". That
 * is also why every claim is argument-free — an interpolated one could not be
 * recognised on the rendered page. The arguments land in the settled phrasings,
 * which claim nothing.
 */
final class GateVoice
{
    /** Crossing this gate is the decision the operator is being asked for. */
    public const APPROVE = 'approve';

    /** The material this gate reviews can still be changed from this page. */
    public const EDIT = 'edit';

    /** Either of the above: something on this page is still a decision. */
    public const DECIDE = 'decide';

    /*
    |--------------------------------------------------------------------------
    | The position axis, and why it is here rather than in a fourth `$phase()`
    |--------------------------------------------------------------------------
    |
    | THE DEFECT. Gate 4's settled strip read "Gate 4 is behind this story" and
    | its disclosure read "the story is at draft, which is terminal: the file is
    | on YouTube and this app does not reach it" — at EIGHT statuses where the
    | gate is ahead of the story, reachable in one click because the stepper
    | links all four gates from every story and no route guards by status. Gate
    | 2 said the same thing at three more. The condition behind both was a
    | capability: `! editable()` at Gate 4, `! canApprove() && ! canGenerateAssets()`
    | at Gate 2. Neither is a position — both are false on BOTH sides of a gate.
    |
    | Every instrument passed, and the reason is exactly the seam this class was
    | built across: the clauses above govern sentences that NAME AN ACTION, and
    | `claimsNotEntitledTo` greps for their fragments. These sentences name no
    | action. They make a claim about WHERE THE STORY IS, so a page could be
    | wrong about that at eight statuses while satisfying a contract that runs
    | over every gate at every status. Found by hand, during a hunt for an
    | unrelated predicate; nothing that runs could have found it.
    |
    | So position joins the same mechanism rather than becoming a fourth
    | hand-written phase helper. Gate 3 already had one — `phase()`, returning
    | 'ahead'/'behind' — and it is the ONLY one of the four that was right,
    | which is the "one instance fixed by hand is not a mechanism" shape this
    | class was written for the first time.
    */

    /** The story has crossed this gate. */
    public const PASSED = 'passed';

    /** The story has not reached this gate yet. */
    public const AHEAD = 'ahead';

    /** The status the story is at is the end of the lifecycle. */
    public const TERMINAL = 'terminal';

    /**
     * Every clause, the capability each of its phrasings depends on, and the
     * fragment that phrasing carries.
     *
     * ONE MAP, WHERE THERE WERE TWO. `CLAUSES` and `CLAIMS` were a clause list
     * and a fragment list keyed the same way, which is two copies of one fact
     * and a fifth clause added to one and not the other. They are the same
     * entry now.
     *
     * The shape also says the difference between the two kinds of clause,
     * which is the whole point of this change:
     *
     *   AN ACTION CLAUSE has ONE entry. It offers something in one state and
     *   claims nothing in the other, so only the offering phrasing carries a
     *   fragment and the settled phrasing is unlisted because it asserts
     *   nothing at all.
     *
     *   A POSITION CLAUSE has one entry PER PHRASING, because every phrasing is
     *   a claim. "Gate 4 has not been reached" is exactly as much an assertion
     *   as "Gate 4 is behind this story", and a check that only looked for one
     *   of them would catch this defect in one direction and not the other.
     *
     * @var array<string, array<string, string>>
     */
    private const CLAUSES = [
        'advisoryHeading' => [self::DECIDE => 'before you approve'],
        'blocksApproval' => [self::DECIDE => 'block approval'],
        'countBlocking' => [self::DECIDE => 'block approval'],
        'fixHere' => [self::EDIT => 'cheaper to fix here'],
        'judgement' => [self::DECIDE => 'Judging these is yours'],

        // Position. Both phrasings claim, so both are listed; the third state —
        // the story parked AT this gate — claims neither and is unlisted, which
        // is what makes a page saying either one there a finding.
        'standing' => [
            self::PASSED => 'is behind this story',
            self::AHEAD => 'has not been reached',
        ],

        // A claim about the STATUS rather than about the gate. Only `published`
        // is the end of the line; every gate that is behind a story at
        // `scripted` is behind a story with most of its life ahead of it.
        //
        // There was a second one here — `publishedOn`, fragment "Published on",
        // for Gate 4's banner. It is gone with the sentence, and its removal is
        // worth a line because the fragment looked like protection and was not:
        // it could only ever answer "may THIS STATE say this", and the banner's
        // other defect was that `updated_at` is not a publication date in ANY
        // state. A registered claim nothing emits is also the dead-mechanism
        // seam, so keeping it for a sentence that no longer exists would have
        // been worse than useless. What replaces it is a written rule that the
        // app has no publication event at all — see CLAUDE.md.
        'statusQualifier' => [self::TERMINAL => 'which is terminal'],

        // The crossing, which is a claim about the GATE and reads differently
        // from where the story stands: `standing()` is the strip's orientation
        // line and this is the locked banner's account of how it got locked.
        'approved' => [self::PASSED => 'has been approved'],

        // An ACTION claim on Gate 1's sizing panel. "The next run fixes this"
        // offers a run, and a page that cannot write a script cannot offer one.
        'sizingFixed' => [self::EDIT => 'the next run fixes this'],
    ];

    private function __construct(
        public readonly Gate $gate,
        public readonly StoryStatus $status,
        private readonly bool $approve,
        private readonly bool $edit,
    ) {}

    /**
     * The voice of one gate at one status.
     *
     * Both predicates are derived from the gate rather than passed in by the
     * component, for the reason OperatorAction exists: a button deciding for
     * itself and a page deciding for itself are two expressions of one rule,
     * compared only by hand. GateVoiceTest walks the two gate bodies that have
     * their own `editable()` and fails if either disagrees with this.
     */
    public static function for(Gate $gate, StoryStatus $status): self
    {
        return new self(
            gate: $gate,
            status: $status,
            // Uniform across all four: a gate is approvable exactly while the
            // story is parked at it.
            approve: $status === $gate->waitsAt(),
            // The status that PRODUCES this gate's material, plus the status
            // the gate waits at. The outline is written at `draft` and stays
            // editable through `outlined`; the scenes are cut at `scripted` and
            // stay editable through `scenes_drafted`; the sheet is written at
            // `rendered` and stays editable through `metadata_ready`. Three
            // gate bodies had already written that range out by hand, one
            // each, and GateVoiceTest holds them against this one.
            edit: $status->rank() >= $gate->waitsAt()->rank() - 1
                && $status->rank() <= $gate->waitsAt()->rank(),
        );
    }

    /**
     * Whether this voice is entitled to a capability.
     *
     * The position three are computed here rather than stored, and from the
     * same `waitsAt()` rank the edit range is derived from, so there is one
     * account of where a gate sits in the lifecycle. `default => false` is
     * load-bearing: a clause keyed to a capability nothing resolves would be a
     * claim nothing can ever check, and GateVoiceTest asserts every capability
     * in `CLAUSES` genuinely varies across the gate-by-status grid.
     */
    public function can(string $capability): bool
    {
        $here = $this->status->rank();
        $gate = $this->gate->waitsAt()->rank();

        return match ($capability) {
            self::APPROVE => $this->approve,
            self::EDIT => $this->edit,
            self::DECIDE => $this->approve || $this->edit,

            // Position, and note that these are NOT complements of each other:
            // at the gate's own status both are false, which is what stops the
            // parked state from being described as either crossed or unreached.
            self::PASSED => $here > $gate,
            self::AHEAD => $here < $gate,

            // A property of the status, not of the gate. Every gate is behind a
            // story at `scripted`; none of them may call `scripted` terminal.
            self::TERMINAL => $this->status === StoryStatus::Published,

            default => false,
        };
    }

    /**
     * One clause's fragment for one capability.
     *
     * The clause bodies build their claiming phrasings out of this, so the
     * fragment the checker greps for is in the emitted sentence by
     * construction. Reaching for a fragment a clause does not declare is a
     * programming error rather than a silent empty string: the failure mode
     * this guards is a clause emitting a claim no list knows about, which is
     * the state Gate 4's strip was in for a phase.
     */
    private static function fragment(string $clause, string $capability): string
    {
        return self::CLAUSES[$clause][$capability]
            ?? throw new InvalidArgumentException(
                sprintf('%s declares no fragment for "%s".', $clause, $capability)
            );
    }

    // -- The clauses ---------------------------------------------------------

    /**
     * The advisory cluster's heading.
     *
     * "Worth a look before you approve" on a published story sat one panel away
     * from a strip saying nothing can be approved. Same list, same loudness, a
     * heading that is true in the state it renders in.
     */
    public function advisoryHeading(string $subject = 'this'): string
    {
        return $this->can(self::DECIDE)
            ? 'Worth a look '.self::fragment('advisoryHeading', self::DECIDE)
            : 'Flagged on '.$subject;
    }

    /**
     * That an advisory is a warning and not a refusal.
     *
     * The fact survives into the settled state; only the tense changes, because
     * the present tense is what implies a pending approval.
     */
    public function blocksApproval(): string
    {
        return $this->can(self::DECIDE)
            ? 'None of these '.self::fragment('blocksApproval', self::DECIDE).'.'
            : 'None of these blocked approval.';
    }

    /**
     * A count of things standing between the page and its gate.
     *
     * Gate 4's own version of the same claim, and it was live: "3 thing(s)
     * block approval" renders on a story at `draft`, which is nine statuses
     * short of a publish sheet existing. The subject differs from
     * blocksApproval()'s, so it is a second clause rather than one clause with
     * an argument — but it claims the same action and carries the same
     * fragment, which is what makes one check cover both.
     */
    public function countBlocking(int $count = 0): string
    {
        return $this->can(self::DECIDE)
            ? sprintf('%d thing(s) %s.', $count, self::fragment('countBlocking', self::DECIDE))
            : sprintf('%d thing(s) are unresolved.', $count);
    }

    /**
     * Where the cheap fix is.
     *
     * The whole point of an advisory at a text gate is that acting on it now
     * costs one call and acting on it later costs the pipeline. That argument
     * is false on a page that cannot act at all, so the settled phrasing names
     * the action that does exist — the reopen — and leaves whether it is
     * available to the strip, which already says so from OperatorAction.
     */
    public function fixHere(): string
    {
        return $this->can(self::EDIT)
            ? 'All of them are '.self::fragment('fixHere', self::EDIT).' than after the gate is crossed.'
            : 'Fixing one now means reopening this gate, and the line above says whether that is '
                .'available from here.';
    }

    /** Whose call it is. */
    public function judgement(): string
    {
        return $this->can(self::DECIDE)
            ? self::fragment('judgement', self::DECIDE).'.'
            : 'These were left as they stand when the gate was crossed.';
    }

    /**
     * Where this story sits relative to this gate.
     *
     * The strip's leading sentence on all four gates, and the clause the whole
     * position axis was added for. Three states, not two: a story can be past
     * this gate, short of it, or parked at it, and the third is what a
     * capability-shaped condition cannot express. `! editable()` is TRUE on
     * both sides and Gate 4 wrote the crossed sentence for all of it.
     *
     * The parked phrasing exists even though no gate currently renders this
     * strip in that state, because "no page renders it there" is an assumption
     * about four templates rather than a property of this method — and the
     * defect being fixed here is what happens when a sentence outlives the
     * condition it was written under.
     */
    public function standing(): string
    {
        $gate = 'Gate '.$this->gate->value;

        return match (true) {
            $this->can(self::PASSED) => sprintf('%s %s.', $gate, self::fragment('standing', self::PASSED)),
            $this->can(self::AHEAD) => sprintf('%s %s.', $gate, self::fragment('standing', self::AHEAD)),
            default => sprintf('%s is waiting on you.', $gate),
        };
    }

    /**
     * That this gate was crossed, for a surface explaining why it is locked.
     *
     * Gate 1's locked banner said "Gate 1 has been approved and the act scripts
     * are written against it" and was RIGHT wherever it rendered — its locked
     * condition happens to coincide exactly with being past the gate. That is a
     * property of where Gate 1 sits in the lifecycle, not of the template, and a
     * sentence that is true for a reason outside itself is one condition change
     * from being Gate 4's defect. The wording is unchanged.
     */
    public function approved(): string
    {
        $gate = 'Gate '.$this->gate->value;

        return $this->can(self::PASSED)
            ? sprintf('%s %s', $gate, self::fragment('approved', self::PASSED))
            : sprintf('%s has not been approved', $gate);
    }

    /**
     * Whether the rate this script is sized against can still change.
     *
     * `stories.sized_against_wpm` is frozen on first use, so "not fixed yet"
     * and "fixed" are two different pages rather than two readings of one. The
     * available half offers a run and is therefore an ACTION claim: on a story
     * past `outlined` there is no next run to fix anything, and saying there is
     * points the operator at a button that is not on the page.
     */
    public function sizingFixed(): string
    {
        return $this->can(self::EDIT)
            ? 'Not fixed yet — '.self::fragment('sizingFixed', self::EDIT)
                .' and it cannot change afterwards.'
            : 'Fixed when the script was written, and it does not move.';
    }

    /**
     * What, if anything, may be said about the status the story is at.
     *
     * A fragment rather than a sentence because the status itself is marked up
     * on the page — `The story is at <span class="mono">published</span>` — so
     * the clause is the tail that follows it. Gate 4 wrote that tail as ", which
     * is terminal: the file is on YouTube and this app does not reach it" for
     * every status it rendered at, including `draft`.
     */
    public function statusQualifier(): string
    {
        return $this->can(self::TERMINAL)
            ? ', '.self::fragment('statusQualifier', self::TERMINAL)
                .': the file is on YouTube and this app does not reach it'
            : '';
    }

    // -- What the test reads -------------------------------------------------

    /**
     * Every phrasing that CLAIMS something, by the capability it claims.
     *
     * The same map the clauses are built from, inverted the way a checker needs
     * it. Not a second list: a hand-written copy of a machine-checked list is a
     * second source of truth that agrees only on the day it is written — this
     * codebase has paid for that twice, once in a retry prompt restating a
     * guard's rules and once in a hint restating a `--max-time`.
     *
     * Two clauses may claim the same thing in the same words — Gate 1's "None
     * of these block approval" and Gate 4's "3 thing(s) block approval" are one
     * claim with two subjects.
     *
     * @return array<string, array<int, string>>
     */
    public static function claims(): array
    {
        $claims = [];

        foreach (self::CLAUSES as $fragments) {
            foreach ($fragments as $capability => $fragment) {
                $claims[$capability][] = $fragment;
            }
        }

        return array_map(
            static fn (array $fragments): array => array_values(array_unique($fragments)),
            $claims,
        );
    }

    /** The fragments one clause declares, by capability. @return array<string, string> */
    public static function claimsOf(string $clause): array
    {
        return self::CLAUSES[$clause] ?? [];
    }

    /** @return array<int, string> */
    public static function clauses(): array
    {
        return array_keys(self::CLAUSES);
    }

    /**
     * What every clause actually says, for THIS voice.
     *
     * Replaces `phrasings()`, which fabricated one "open" and one "settled"
     * voice through the private constructor and asked each clause for its two
     * wordings. That shape could not hold a position clause — it has three
     * phrasings, and two of them are claims — and, more to the point, a
     * fabricated voice is a voice no story can be in. The tests walk real gate
     * and status pairs now, which is the same reason `for()` derives its
     * predicates instead of taking them.
     *
     * Every clause is called with its defaults, so a clause taking an argument
     * has to have one. A clause whose claim only appears for some argument
     * would be a claim the checker cannot see.
     *
     * @return array<string, string>
     */
    public function everyClause(): array
    {
        $out = [];

        foreach (self::clauses() as $clause) {
            $out[$clause] = $this->{$clause}();
        }

        return $out;
    }
}
