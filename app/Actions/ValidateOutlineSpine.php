<?php

namespace App\Actions;

use App\Enums\ActPhase;
use App\Enums\StoryFormat;
use App\Models\Act;
use App\Models\Story;

/**
 * The genre check, run at Gate 1.
 *
 * An aggrieved-narrator melodrama fails in ways that look fine in the database:
 * every act has a title, a summary and a word count, and the video is still
 * unwatchable because the antagonist is a cartoon or nothing gets worse. Those
 * failures are structural, so they can be checked structurally — and they have
 * to be checked here, before 5,500-8,000 words are generated against the
 * outline and long before 150-250 stills are paid for.
 *
 * What this can and cannot do is worth being honest about. It cannot judge
 * whether writing is good. It CAN tell that a required field is missing, that
 * an antagonist's justification reads as a confession rather than an excuse,
 * that an exposure has no witnesses in it, that two acts claim the same
 * escalation, that a departure was announced, that a search costs the
 * antagonist nothing, or that a refusal answers nothing anybody named earlier.
 * Those are the ways this genre actually gets written wrong, and all of them
 * are visible in the text.
 *
 * The last three arrived after story 21 was watched back. It ran escalation ->
 * escalation -> exposure -> end and gave the narrator one scene of power out of
 * two hundred and seventy, and nothing here could see it: every check passed.
 * The reversal is a PHASE — the narrator leaves, the antagonist searches, the
 * narrator refuses — and a spine with no departure in it cannot produce one.
 *
 * Nothing here blocks. Gate 1 is an operator decision and these are the notes
 * they should have in front of them when they make it — an outline the
 * operator judges to work despite a warning is theirs to approve.
 */
class ValidateOutlineSpine
{
    /** Below this a spine field is a label rather than an answer. */
    private const MIN_SPINE_CHARS = 60;

    private const MIN_BEAT_CHARS = 25;

    /**
     * Phrases that mean the antagonist knows they are the villain.
     *
     * The single most common way this genre is written wrong. An antagonist who
     * admits fault, or whose "justification" is really an admission, gives the
     * audience nothing to be angry at for thirty-five minutes — the format runs
     * on a person who believes they were owed it.
     */
    private const CONFESSION_MARKERS = [
        'knew it was wrong', 'knew she was wrong', 'knew he was wrong',
        'admitted', 'confessed', 'did not care', "didn't care", 'not care',
        'out of spite', 'to hurt', 'wanted to hurt', 'enjoyed', 'laughed at',
        'jealous', 'jealousy', 'greedy', 'evil', 'cruel', 'malicious',
        'no reason', 'just because', 'always hated', 'wanted to see',
    ];

    /** An exposure with nobody watching is a private conversation. */
    private const WITNESS_MARKERS = [
        'front of', 'everyone', 'guests', 'family', 'room', 'table', 'party',
        'wedding', 'funeral', 'reunion', 'dinner', 'church', 'reception',
        'crowd', 'relatives', 'friends', 'colleagues', 'congregation',
        'toast', 'speech', 'audience', 'gathered', 'witnesses', 'public',
    ];

    /**
     * Ways of saying the narrator told somebody they were going.
     *
     * The one detail in `departure` that decides whether the rest of the video
     * exists. A narrator who announces their departure cannot be searched for,
     * and the search is the next third of the runtime — so an announced
     * departure does not weaken the reversal, it deletes it.
     *
     * Matched as whole phrases, and each hit is tested for a negation in front
     * of it, because the field is as likely to say "she leaves without telling
     * anyone" as the opposite and flagging that would be flagging the good
     * case. See NEGATIONS.
     */
    private const ANNOUNCEMENT_MARKERS = [
        'announces', 'announced', 'announcing', 'announcement',
        'tells her', 'told her', 'tells him', 'told him', 'tells them', 'told them',
        'tells the family', 'told the family', 'tells everyone', 'told everyone',
        'says goodbye', 'said goodbye', 'saying goodbye', 'says her goodbyes',
        'gives notice', 'gave notice', 'giving notice',
        'leaves a note', 'left a note', 'leaving a note',
        'leaves a letter', 'left a letter',
        'informs', 'informed', 'informing',
        'lets them know', 'let them know', 'letting them know',
        'warns', 'warned', 'declares', 'declared', 'a farewell',
    ];

    /**
     * Words that turn an announcement marker into its opposite.
     *
     * Looked for in the text immediately before a hit. "She leaves without
     * telling them" and "she tells them" differ by one word and mean opposite
     * things about whether the next third of the video can happen.
     */
    private const NEGATIONS = [
        'not', 'never', 'without', 'no', 'nobody', 'nothing', 'neither', 'nor',
        "doesn't", "didn't", "won't", "wouldn't", "isn't",
        'refuses', 'refusing', 'refused', 'avoids', 'avoiding', 'avoided',
        'instead', 'rather', 'silently', 'quietly', 'secretly', 'unannounced',
    ];

    /**
     * Ways of saying the search cost her something.
     *
     * The mirror of WITNESS_MARKERS, and required for the same reason: a search
     * that costs the antagonist nothing is a montage of her looking worried. The
     * humiliation beats escalated against the narrator; these have to escalate
     * against her, or the reversal is asserted rather than earned.
     *
     * `face` is here deliberately and matters most in the en-CN profile, where
     * losing face IS the cost and no money changes hands.
     */
    private const COST_MARKERS = [
        'money', 'spends', 'spent', 'spend', 'pays', 'paid', 'pay',
        'sells', 'sold', 'sell', 'borrows', 'borrowed', 'borrow', 'loan', 'debt',
        'savings', 'mortgage', 'credit', 'dollars', 'yuan', 'thousand', 'expense',
        'hires', 'hired', 'hire', 'investigator', 'lawyer', 'detective',
        'quits', 'quit', 'loses', 'lost', 'lose', 'losing',
        'job', 'house', 'car', 'ring', 'apartment', 'business',
        'flies', 'flew', 'travels', 'travelled', 'traveled', 'drives', 'drove',
        'begs', 'begged', 'beg', 'begging', 'pleads', 'pleaded',
        'apology', 'apologise', 'apologize', 'apologises', 'apologizes', 'apologised', 'apologized',
        'humiliated', 'humiliation', 'standing', 'reputation', 'face',
        'friends', 'allies', 'church', 'admits', 'admitting', 'costs', 'cost',
    ];

    /**
     * Words too common to prove a refusal is answering anything in particular.
     *
     * The refusal check works by overlap: the refusal has to reuse the specific
     * language of an earlier moment. "She said that I would have to think about
     * it" overlaps with everything and names nothing.
     */
    private const COMMON_WORDS = [
        'about', 'after', 'again', 'against', 'because', 'been', 'before', 'being',
        'could', 'does', 'doing', 'done', 'down', 'each', 'even', 'ever', 'every',
        'from', 'gets', 'give', 'going', 'have', 'having', 'here', 'himself',
        'herself', 'into', 'just', 'know', 'like', 'made', 'make', 'more', 'most',
        'much', 'must', 'myself', 'never', 'only', 'other', 'over', 'said', 'same',
        'says', 'should', 'some', 'still', 'such', 'take', 'takes', 'than',
        'that', 'them', 'then', 'there', 'these', 'they', 'thing', 'things', 'this',
        'those', 'through', 'time', 'told', 'tell', 'tells', 'very', 'want', 'wants',
        'were', 'what', 'when', 'where', 'which', 'while', 'will', 'with', 'without',
        'would', 'your', 'their', 'nothing', 'anything', 'something',
    ];

    /**
     * @return array{
     *     problems: array<int, string>,
     *     warnings: array<int, string>,
     *     spine: array<string, array{label: string, value: string, state: string}>
     * }
     */
    public function handle(Story $story): array
    {
        $problems = [];
        $warnings = [];
        $spine = [];

        // An outline written before the reversal phase existed is a known,
        // nameable thing rather than four independent failures, and saying it
        // once is more use than saying it four times. Detected from the acts,
        // not the fields: every outline generated since carries a phase on
        // every act, assigned deterministically, so all-null cannot mean
        // anything else. See GenerateOutline.
        $legacy = $this->predatesReversalPhase($story);

        foreach ($this->fields() as $key => $meta) {
            $value = trim((string) $story->{$key});
            $state = 'ok';

            if ($value === '' && $legacy && ($meta['reversal'] ?? false)) {
                // Reported once, below, as the one thing it actually is.
                $state = 'absent';
            } elseif ($value === '') {
                $problems[] = "{$meta['label']} is missing. {$meta['why']}";
                $state = 'missing';
            } elseif (mb_strlen($value) < self::MIN_SPINE_CHARS) {
                $warnings[] = sprintf(
                    '%s is only %d characters — that is a label, not an answer. %s',
                    $meta['label'],
                    mb_strlen($value),
                    $meta['why']
                );
                $state = 'thin';
            }

            $spine[$key] = ['label' => $meta['label'], 'value' => $value, 'state' => $state];
        }

        if ($legacy) {
            $warnings[] = 'This outline was generated before the reversal phase existed, so it has no '
                .'departure, no search and no refusal — it escalates to the last act and pays off in '
                .'the ending. That is the shape this genre loses on: the reversal is a phase, not a '
                .'scene. Re-generating the outline adds it; the acts already written against this one '
                .'stay on record.';
        }

        $this->checkJustification($story, $warnings, $spine);
        $this->checkExposure($story, $warnings, $spine);
        $this->checkDeparture($story, $warnings, $spine);
        $this->checkReversalBeats($story, $warnings, $spine);
        $this->checkRefusal($story, $warnings, $spine);
        $this->checkEscalation($story, $problems, $warnings);
        $this->checkPhases($story, $problems, $warnings, $legacy);
        $this->checkFormat($story, $warnings);

        return ['problems' => $problems, 'warnings' => $warnings, 'spine' => $spine];
    }

    /**
     * The antagonist has to believe their own excuse.
     *
     * @param  array<int, string>  $warnings
     * @param  array<string, array{label: string, value: string, state: string}>  $spine
     */
    private function checkJustification(Story $story, array &$warnings, array &$spine): void
    {
        $text = mb_strtolower((string) $story->antagonist_justification);

        if (trim($text) === '') {
            return;
        }

        $hits = array_values(array_filter(
            self::CONFESSION_MARKERS,
            fn (string $marker): bool => str_contains($text, $marker)
        ));

        if ($hits === []) {
            return;
        }

        $warnings[] = sprintf(
            'The antagonist reads as a cartoon: their justification contains %s. The infuriating '
            .'part of this format is the EXCUSE, not the villainy — an antagonist who knows they '
            .'are being cruel gives the audience nothing to stay angry at. Rewrite it as something '
            .'they would say out loud and believe.',
            '"'.implode('", "', array_slice($hits, 0, 3)).'"'
        );

        $spine['antagonist_justification']['state'] = 'weak';
    }

    /**
     * The public payoff is exposure in front of people.
     *
     * @param  array<int, string>  $warnings
     * @param  array<string, array{label: string, value: string, state: string}>  $spine
     */
    private function checkExposure(Story $story, array &$warnings, array &$spine): void
    {
        $text = mb_strtolower((string) $story->exposure_moment);

        if (trim($text) === '') {
            return;
        }

        foreach (self::WITNESS_MARKERS as $marker) {
            if (str_contains($text, $marker)) {
                return;
            }
        }

        $warnings[] = 'The exposure moment does not name anyone who is there to see it. Witnesses are '
            .'the payoff of this format — the same reveal in private is a different and much worse '
            .'video. Name the occasion and who is in the room.';

        $spine['exposure_moment']['state'] = 'weak';
    }

    /**
     * A departure that was announced cannot be searched for.
     *
     * The whole reversal phase rests on the antagonist not knowing where they
     * went. This is the check with the least margin in it: everything else here
     * makes a video weaker, and this one makes the next third of it impossible.
     *
     * @param  array<int, string>  $warnings
     * @param  array<string, array{label: string, value: string, state: string}>  $spine
     */
    private function checkDeparture(Story $story, array &$warnings, array &$spine): void
    {
        $text = mb_strtolower((string) $story->departure);

        if (trim($text) === '') {
            return;
        }

        $hits = $this->unnegatedHits($text, self::ANNOUNCEMENT_MARKERS);

        if ($hits === []) {
            return;
        }

        $warnings[] = sprintf(
            'The departure reads as announced: it contains %s with nothing negating it. An announced '
            .'departure cannot be searched for, and the search is the next third of the video — so '
            .'this does not weaken the reversal, it removes it. The narrator goes without saying so; '
            .'the antagonist finds out that they are gone, later, from someone else.',
            '"'.implode('", "', array_slice($hits, 0, 3)).'"'
        );

        $spine['departure']['state'] = 'weak';
    }

    /**
     * The search has to cost her, and cost her more each time.
     *
     * @param  array<int, string>  $warnings
     * @param  array<string, array{label: string, value: string, state: string}>  $spine
     */
    private function checkReversalBeats(Story $story, array &$warnings, array &$spine): void
    {
        $text = mb_strtolower((string) $story->reversal_beats);

        if (trim($text) === '') {
            return;
        }

        if ($this->wholeWordHits($text, self::COST_MARKERS) === []) {
            $warnings[] = 'The search costs the antagonist nothing that is named. This is the mirror '
                .'of the humiliation beats and it has to escalate the same way — money, standing, the '
                .'people who backed her excuse, in that order. A search that costs her nothing is a '
                .'montage of somebody looking worried, and the refusals it leads to are unearned.';

            $spine['reversal_beats']['state'] = 'weak';

            return;
        }

        // One attempt is a scene. The reversal is a phase, which is the entire
        // reason this field exists separately from the exposure.
        if (preg_match_all('/[.!?](?=\s|$)/u', trim($text)) < 2) {
            $warnings[] = 'The search reads as a single attempt. It is a phase, not a scene: she '
                .'tries, it fails and costs her something, and she tries again from a worse position. '
                .'Name at least two attempts and what each one took.';

            $spine['reversal_beats']['state'] = 'thin';
        }
    }

    /**
     * A refusal answers a specific earlier moment, by name.
     *
     * The private payoff, opposite the public one — and the thing viewers wait
     * forty minutes for. "I said no" is not it. What lands is the narrator
     * returning a sentence the antagonist used on them in act 2, which means
     * the refusal has to reuse that act's actual language.
     *
     * So the check is overlap: distinctive words shared with the grievance, the
     * justification or one of the escalation beats. It records which moment it
     * matched, because being told WHICH earlier beat a refusal answers is more
     * use at Gate 1 than being told that one exists.
     *
     * @param  array<int, string>  $warnings
     * @param  array<string, array{label: string, value: string, state: string}>  $spine
     */
    private function checkRefusal(Story $story, array &$warnings, array &$spine): void
    {
        $text = trim((string) $story->refusal);

        if ($text === '') {
            return;
        }

        $refusalWords = $this->distinctiveWords($text);

        foreach ($this->earlierMoments($story) as $label => $moment) {
            $shared = array_intersect($refusalWords, $this->distinctiveWords($moment));

            // Two, not one. A single shared word is a coincidence in any two
            // paragraphs about the same family; two is the refusal reaching
            // back for something specific.
            if (count($shared) >= 2) {
                $spine['refusal']['answers'] = (string) $label;

                return;
            }
        }

        $warnings[] = 'The refusal does not answer any earlier moment by name — it shares no specific '
            ."language with the grievance, the antagonist's justification or any escalation beat. This "
            .'is the private payoff and it only lands as an inversion: the narrator hands back the '
            .'exact sentence that was used on them, in the same words, from the other side of it. '
            .'Name which earlier humiliation each refusal answers.';

        $spine['refusal']['state'] = 'weak';
    }

    /**
     * Every act costs somebody more than the last, and none of them resolves
     * anything before the exposure.
     *
     * Phase-aware since the reversal was added: before the departure the beat
     * names what the act costs the NARRATOR, after it what the attempt costs the
     * ANTAGONIST. Same column, opposite direction, and the phase is what says
     * which — so a missing beat is reported in terms that fit either.
     *
     * @param  array<int, string>  $problems
     * @param  array<int, string>  $warnings
     */
    private function checkEscalation(Story $story, array &$problems, array &$warnings): void
    {
        $acts = $story->acts()->orderBy('sequence')->get();

        if ($acts->isEmpty()) {
            return;
        }

        $missing = $acts->filter(fn (Act $act): bool => trim((string) $act->escalation_beat) === '');

        if ($missing->isNotEmpty()) {
            $problems[] = sprintf(
                'Act(s) %s have no beat. Each act has to name what it costs and to whom — the narrator '
                .'before the departure, the antagonist after it. An act that costs nobody anything is '
                .'where the retention graph falls off.',
                $missing->pluck('sequence')->implode(', ')
            );
        }

        $thin = $acts->filter(fn (Act $act): bool => trim((string) $act->escalation_beat) !== ''
            && mb_strlen(trim((string) $act->escalation_beat)) < self::MIN_BEAT_CHARS);

        if ($thin->isNotEmpty()) {
            $warnings[] = sprintf(
                'Act(s) %s have a one-phrase beat. Name the specific thing it costs.',
                $thin->pluck('sequence')->implode(', ')
            );
        }

        // Two acts claiming the same beat means that stretch of the video is
        // flat, which is exactly where this format loses people.
        $seen = [];

        foreach ($acts as $act) {
            $beat = mb_strtolower(trim((string) $act->escalation_beat));

            if ($beat === '') {
                continue;
            }

            // Digits are KEPT. Stripping them was the first version and it was
            // wrong in a way specific to this genre: escalation is usually
            // expressed as a number going up, so "costs me four thousand" and
            // "costs me twelve thousand" are the same shape but not the same
            // beat, and "cost 4000" vs "cost 8000" would have collapsed into
            // one. Only punctuation and repeated whitespace are normalised.
            $fingerprint = trim((string) preg_replace(
                '/\s+/',
                ' ',
                (string) preg_replace('/[^a-z0-9 ]/', '', $beat)
            ));

            if (isset($seen[$fingerprint])) {
                $warnings[] = sprintf(
                    'Acts %d and %d escalate identically. That stretch of the video is flat — each '
                    .'act has to cost more than the one before it, not the same again.',
                    $seen[$fingerprint],
                    $act->sequence
                );
            }

            $seen[$fingerprint] = $act->sequence;
        }
    }

    /**
     * The arc has four phases and the acts have to be laid out across them.
     *
     * This is the check story 21 needed and nothing had: it escalated to the
     * last act and paid off in the ending, and every other check on this page
     * passed. An outline with no departure act cannot produce a reversal phase
     * however good the prose is, because there is nowhere for it to go.
     *
     * Skipped for an anthology, where every act is a self-contained story
     * running the whole arc internally — a per-act phase there would be a claim
     * about five different narrators. checkFormat() has its own warning.
     *
     * @param  array<int, string>  $problems
     * @param  array<int, string>  $warnings
     */
    private function checkPhases(Story $story, array &$problems, array &$warnings, bool $legacy): void
    {
        if ($story->format === StoryFormat::Anthology || $legacy) {
            return;
        }

        $acts = $story->acts()->orderBy('sequence')->get();

        if ($acts->isEmpty()) {
            return;
        }

        $unphased = $acts->filter(fn (Act $act): bool => $act->phase === null);

        if ($unphased->isNotEmpty()) {
            // Not the legacy case — that one is every act. A partial set means
            // an outline that was half rewritten, and the act generator reads
            // this column to decide whether an act ends worse for the narrator
            // or for the antagonist. It cannot be guessed.
            $problems[] = sprintf(
                'Act(s) %s have no phase while the rest of the outline does. The act generator reads '
                .'the phase to decide whether the act ends worse for the narrator or for the '
                .'antagonist, so an unphased act in a phased outline gets written as neither. '
                .'Re-generate the outline.',
                $unphased->pluck('sequence')->implode(', ')
            );

            return;
        }

        $phases = $acts->pluck('phase');

        if (! $phases->contains(ActPhase::Departure)) {
            $problems[] = 'No act contains the departure. This outline escalates to the last act and '
                .'pays its reversal off in the ending, which is the shape this genre loses on: what '
                .'the niche pays for is the narrator LEAVING, the antagonist SEARCHING, and the '
                .'narrator REFUSING — a phase each, not a scene between them.';

            return;
        }

        foreach ([ActPhase::Search, ActPhase::Refusal] as $required) {
            if (! $phases->contains($required)) {
                $problems[] = sprintf(
                    'No act carries the %s phase. The reversal needs both: the antagonist looking, at '
                    .'a rising cost to her, and then the narrator answering. One without the other is '
                    .'half a payoff.',
                    $required->value,
                );
            }
        }

        $departureAt = $acts->first(fn (Act $act): bool => $act->phase === ActPhase::Departure)?->sequence;

        if ($departureAt !== null && $acts->count() - $departureAt < 2) {
            $warnings[] = sprintf(
                'The narrator leaves in act %d of %d, so the search and the refusals share what is '
                .'left. That is the compression this structure exists to undo — the reversal wants '
                .'roughly the last third, not the last few minutes.',
                $departureAt,
                $acts->count(),
            );
        }
    }

    /**
     * @param  array<int, string>  $warnings
     */
    private function checkFormat(Story $story, array &$warnings): void
    {
        if ($story->format !== StoryFormat::Anthology) {
            return;
        }

        $warnings[] = 'This story is an anthology. Escalating humiliation compounds across one '
            .'continuous narrative and cannot compound across separate ones — five self-contained '
            .'acts each have a fifth of the runtime to build and pay off their own escalation, and '
            .'none of them has room for a departure, a search and a refusal on top of it. '
            .'Single-narrative is the shape this genre needs.';
    }

    /**
     * Whether this outline was generated before the reversal phase existed.
     *
     * Decided on the acts rather than the fields: every outline generated since
     * carries a phase on every act, assigned deterministically by the Action
     * rather than asked of the model, so "no act has a phase" cannot mean
     * anything else. The reversal fields are checked too, so an operator who
     * has started filling them in by hand gets the ordinary per-field checks
     * back rather than one blanket excuse.
     */
    private function predatesReversalPhase(Story $story): bool
    {
        if ($story->format === StoryFormat::Anthology) {
            return false;
        }

        $acts = $story->acts()->get();

        if ($acts->isEmpty() || $acts->contains(fn (Act $act): bool => $act->phase !== null)) {
            return false;
        }

        foreach (['departure', 'reversal_beats', 'refusal'] as $field) {
            if (trim((string) $story->{$field}) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * The earlier moments a refusal can be answering, labelled.
     *
     * Only what happened BEFORE the narrator left. A refusal that echoes the
     * search act two scenes earlier is not answering a humiliation, it is
     * answering itself.
     *
     * @return array<string, string>
     */
    private function earlierMoments(Story $story): array
    {
        $moments = [
            'the grievance' => (string) $story->narrator_grievance,
            "the antagonist's justification" => (string) $story->antagonist_justification,
        ];

        foreach ($story->acts()->orderBy('sequence')->get() as $act) {
            $phase = $act->phase;

            if ($phase !== null && $phase !== ActPhase::Escalation && $phase !== ActPhase::Departure) {
                continue;
            }

            $moments[sprintf('act %d — %s', $act->sequence, $act->title)] = (string) $act->escalation_beat;
        }

        return array_filter($moments, fn (string $text): bool => trim($text) !== '');
    }

    /**
     * The words in a passage that could identify it.
     *
     * @return array<int, string>
     */
    private function distinctiveWords(string $text): array
    {
        preg_match_all("/[\p{L}\p{N}']{4,}/u", mb_strtolower($text), $matches);

        return array_values(array_unique(array_diff($matches[0], self::COMMON_WORDS)));
    }

    /**
     * Markers present as whole words or phrases, minus any that something in
     * front of them negates.
     *
     * @param  array<int, string>  $markers
     * @return array<int, string>
     */
    private function unnegatedHits(string $text, array $markers): array
    {
        $hits = [];

        foreach ($markers as $marker) {
            if (! preg_match('/\b'.preg_quote($marker, '/').'\b/u', $text, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            // A short window, deliberately. "She does not tell them" negates;
            // "she told them, and later nobody knew where she was" does not,
            // and a whole-field search for a negation would swallow both.
            $offset = $m[0][1];
            $before = substr($text, max(0, $offset - 45), min(45, $offset));

            $negated = false;

            foreach (self::NEGATIONS as $negation) {
                if (preg_match('/\b'.preg_quote($negation, '/').'\b/u', $before)) {
                    $negated = true;

                    break;
                }
            }

            if (! $negated) {
                $hits[] = $marker;
            }
        }

        return $hits;
    }

    /**
     * @param  array<int, string>  $markers
     * @return array<int, string>
     */
    private function wholeWordHits(string $text, array $markers): array
    {
        return array_values(array_filter(
            $markers,
            fn (string $marker): bool => (bool) preg_match('/\b'.preg_quote($marker, '/').'\b/u', $text)
        ));
    }

    /**
     * @return array<string, array{label: string, why: string, reversal?: bool}>
     */
    private function fields(): array
    {
        return [
            'narrator_grievance' => [
                'label' => 'Narrator grievance',
                'why' => 'The narrator has to be the person who was wronged, not someone watching it '
                    .'happen to a third party. Without this the video has no first person to be angry for.',
            ],
            'antagonist_justification' => [
                'label' => "Antagonist's justification",
                'why' => 'This is the engine of the format. The audience stays for thirty-five minutes '
                    .'because someone is being unreasonable and believes they are being fair.',
            ],
            'withheld_information' => [
                'label' => 'Withheld information',
                'why' => 'What the narrator knows and the antagonist does not. It is what makes '
                    .'escalating humiliation watchable rather than merely unpleasant — the viewer is '
                    .'waiting for a specific thing to land.',
            ],
            'exposure_moment' => [
                'label' => 'Exposure moment',
                'why' => 'The public payoff. Exposure in front of witnesses, not revenge — and it is '
                    .'the thing the title promises, so it cannot be decided later.',
            ],
            'departure' => [
                'label' => 'Departure',
                'reversal' => true,
                'why' => 'How and when the narrator goes, and whether they announce it. They must not: '
                    .'an announced departure cannot be searched for, and the search is the next third '
                    .'of the video.',
            ],
            'reversal_beats' => [
                'label' => 'Reversal beats',
                'reversal' => true,
                'why' => 'What the antagonist does to find them, and what each attempt costs HER. The '
                    .'humiliation beats running the other way, escalating the same. Without them the '
                    .'middle of the reversal is empty and the refusals are unearned.',
            ],
            'refusal' => [
                'label' => 'Refusal',
                'reversal' => true,
                'why' => 'What the narrator says when they are finally found, and which earlier moment '
                    .'it answers. The exposure is the public payoff; this is the private one, and it '
                    .'is what viewers wait forty minutes for.',
            ],
        ];
    }
}
