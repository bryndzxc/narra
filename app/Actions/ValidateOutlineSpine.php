<?php

namespace App\Actions;

use App\Enums\ActPhase;
use App\Enums\ActTimeframe;
use App\Enums\StoryFormat;
use App\Models\Act;
use App\Models\Chapter;
use App\Models\Story;
use App\Support\ChapterAnnouncement;
use App\Support\SentenceSplitter;

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
 * antagonist nothing, that a refusal answers nothing anybody named earlier, or
 * that a hook closes on the wrong payoff.
 * Those are the ways this genre actually gets written wrong, and all of them
 * are visible in the text.
 *
 * The hook check arrived from a measurement rather than from a watch-back, and
 * the measurement is the reason it is shaped the way it is. Both shipped
 * stories were read against the five beats a hook in this niche runs: FOUR OF
 * THE FIVE ALREADY EXIST IN BOTH, and every one of them lands two to seven
 * minutes late. Story 21's cold action — *I said, "Have a good trip. I'll take
 * you to the airport."* — is at 3:09; story 12 opens a spreadsheet and names it
 * MOM EXPENSES 2020 at 3:24; neither states its betrayal inside forty scenes.
 * Nothing was missing and no writer failed. The act 1 call had no instruction
 * about where the opening starts, and a writer with none writes the
 * chronological beginning, because context is what comes first in time.
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
    public function __construct(
        // The same splitter GenerateActScripts computed the chapter
        // boundaries with and DraftScenes cuts scenes with. A chapter's
        // `first_sentence` is an offset into THAT splitting, so resolving it
        // with any other parser would read a different sentence — and the
        // check would report the sentence beside the one it is judging.
        private readonly SentenceSplitter $splitter,
    ) {}

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
     * People watching the betrayal scene, and occasions that cannot happen
     * without them.
     *
     * NOT `WITNESS_MARKERS`, and the difference is the point. That list admits
     * `room`, `table`, `family` and `dinner`, which is right for an exposure —
     * an exposure is an occasion by construction, so a room means a room full
     * of people. A betrayal scene's default is the opposite: stories 29-32 all
     * staged theirs at a kitchen table, with the husband and his mother, and
     * every one of those words is in that scene. Reusing the exposure's list
     * would pass exactly the scene this check exists to catch, so the private
     * nouns are left out and the drill for it is a kitchen table.
     */
    private const AUDIENCE_MARKERS = [
        'front of', 'everyone', 'everybody', 'whole room', 'whole table',
        'guests', 'relatives', 'families', 'friends', 'colleagues', 'coworkers',
        'classmates', 'clients', 'customers', 'staff', 'diners', 'neighbors',
        'neighbours', 'elders', 'cousins', 'aunts', 'uncles', 'crowd', 'audience',
        'witnesses', 'gathered', 'public', 'people', 'banquet', 'reception',
        'party', 'wedding', 'funeral', 'reunion', 'ceremony',
    ];

    /**
     * Ways of saying the betrayal was FOUND rather than done.
     *
     * Seven stories: a roommate's posted photos (23), a hotel booking the
     * narrator was copied on (25), and a kitchen-table conversation in private
     * (28-32). The first two are this list. Matched whole-word behind the same
     * negation window the departure uses, because "not from a message, from her
     * own mouth at the table" is the good case and says the marker out loud.
     *
     * A WARNING, never a problem, and on the operator's word: not every premise
     * can stage a public betrayal, and a problem would refuse stories the genre
     * allows. The field can still describe the moment she confirms a found
     * betrayal aloud in front of people — and when it does, it usually still
     * names what was found, which is why this reports rather than refuses.
     */
    private const DISCOVERY_MARKERS = [
        'found out', 'finds out', 'find out', 'discovered', 'discovers', 'discover',
        'email', 'e-mail', 'emails', 'text message', 'messages', 'screenshot',
        'screenshots', 'posted', 'photos', 'booking', 'booked', 'receipt',
        'receipts', 'bank statement', 'her phone', 'his phone', 'copied me',
        'overheard', 'overhears', 'overhear',
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
     * Ways of saying the antagonist's search succeeded.
     *
     * This used to REFUSE, on the rule that the search fails and the narrator
     * chooses the moment they are seen. That rule was derived from a reference
     * TITLE, and the first reference transcript read (2026-09-13) runs the
     * other way: she finds him — "I asked everyone I could think of... I had
     * to beg someone at the student records office" — and the finding is what
     * costs her, on her knees on a street in another city. So a found narrator
     * is no longer weak. What the field still has to say is what the narrator
     * PRODUCES there and that the scene is theirs, which the overlap check
     * below decides. The markers are kept so the page can say, as a note,
     * that this is the found shape — and so an outline written to the old
     * rule ("she did not find me, I came") reads exactly as it did.
     *
     * Matched whole-word with the same negation window as the announcement
     * markers, because "she did not find me, I came" says the marker out loud.
     */
    private const FOUND_MARKERS = [
        'finds me', 'found me', 'find me',
        'finds him', 'found him', 'find him',
        'finds her', 'found her', 'find her',
        'finds them', 'found them', 'find them',
        'tracks me down', 'tracked me down', 'tracks him down', 'tracked him down',
        'tracks her down', 'tracked her down', 'tracks them down', 'tracked them down',
        'locates me', 'located me', 'locates him', 'located him', 'locates her', 'located her',
        'traces me', 'traced me', 'traces him', 'traced him', 'traces her', 'traced her',
        'discovers where', 'discovered where', 'finds out where', 'found out where',
        'the agency finds', 'the investigator finds', 'the detective finds',
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

        // The second known age: outlined WITH the reversal phase and before
        // the act timeframe and the narrator's presence at the exposure were
        // asked. Stories 22 through 28. Same treatment as the first — said
        // once, as what it is — and ended the same way, by an operator typing
        // the field in.
        $unasked = ! $legacy && $this->predatesTimeframeAndPresence($story);

        // The third known age, and the first NOT inferred from the acts: every
        // story that had an outline when `betrayal_scene` was added, frozen by
        // that migration. It spans the other two ages as well, so it is read
        // for this one field only. An operator typing the field in ends it.
        $predatesBetrayalScene = (bool) $story->outlined_before_betrayal_scene
            && trim((string) $story->betrayal_scene) === '';

        foreach ($this->fields() as $key => $meta) {
            $value = trim((string) $story->{$key});
            $state = 'ok';

            if ($value === '' && $legacy && ($meta['later'] ?? false)) {
                // Reported once, below, as the one thing it actually is.
                $state = 'absent';
            } elseif ($value === '' && $unasked && ($meta['asked_later'] ?? false)) {
                $state = 'absent';
            } elseif ($value === '' && $predatesBetrayalScene && ($meta['betrayal_later'] ?? false)) {
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
                .'hook, no betrayal scene, no departure, no search and no refusal — it escalates to the last act and pays '
                .'off in the ending. That is the shape this genre loses on: the reversal is a phase, '
                .'not a scene, and the opening is five beats rather than a summary of the premise. '
                .'Four stories are in this position. Re-generating the outline adds both; the acts '
                .'already written against this one stay on record.';
        }

        if ($unasked) {
            $warnings[] = 'This outline was generated before two questions were asked of it: whether '
                .'each act is set in the story\'s present, and how the narrator comes to be in the '
                .'room for the exposure. Measured on the stories in this position, story 28 spent '
                .'two of its three escalation acts staging 2015 and 2017 and its present-day '
                .'betrayal lands at 20:18, and stories 23 and 28 both hear about their own exposure '
                .'from somebody who was there, because a document produced the withheld '
                .'information and the narrator was 800 km away. Neither can be seen from here on '
                .'an outline that was never asked. Re-generating the outline asks both; typing a '
                .'narrator-at-exposure in by hand and marking the acts present gets the ordinary '
                .'checks back.';
        }

        // Said once, and not on the pre-phase four, whose blanket warning
        // above already says a regenerated outline is the repair for all of it.
        if ($predatesBetrayalScene && ! $legacy) {
            $warnings[] = 'This outline was generated before it was asked for the betrayal as a scene. '
                .'Measured on the seven stories in this position, every one of them found its '
                .'betrayal or heard it in private, and first said the antagonist\'s justification in '
                .'front of people at 9-11 minutes or not before the exposure at all — the reference '
                .'stages both at 1:31, in a room of nine, with the other man holding her hand. That '
                .'cannot be seen from here on an outline that was never asked. Re-generating the '
                .'outline asks it; typing a betrayal scene in by hand gets the ordinary checks back.';
        }

        $this->checkHook($story, $warnings, $spine);
        $this->checkJustification($story, $warnings, $spine);
        $this->checkBetrayalScene($story, $warnings, $spine);
        $this->checkExposure($story, $warnings, $spine);
        $this->checkNarratorAtExposure($story, $warnings, $spine);
        $this->checkTimeframe($story, $problems, $legacy || $unasked);
        $this->checkDeparture($story, $warnings, $spine);
        $this->checkReversalBeats($story, $warnings, $spine);
        $this->checkRefusal($story, $warnings, $spine);
        $this->checkEscalation($story, $problems, $warnings);
        $this->checkPhases($story, $problems, $warnings, $legacy);
        $this->checkChapterAnnouncements($story, $warnings);
        $this->checkFormat($story, $warnings);

        return ['problems' => $problems, 'warnings' => $warnings, 'spine' => $spine];
    }

    /**
     * The hook's closing line promises the DEPARTURE.
     *
     * -----------------------------------------------------------------------
     * WHY THIS ONE BEAT AND NOT THE OTHER FOUR
     * -----------------------------------------------------------------------
     *
     * The hook has five beats and this checks the fifth. That is deliberate and
     * it is worth saying which four are NOT checked, so nobody reads the field
     * as covered: one sentence of setup, the betrayal inside the word budget,
     * evidence in exact words, and one small cold action are all instructions
     * to the writer with nothing structural to measure at Gate 1 — the outline
     * holds a paragraph describing the opening, not the opening itself, so
     * counting its sentences would be counting the wrong text.
     *
     * The fifth beat is different in kind. It is a claim about WHICH VIDEO this
     * is, and the story already carries the answer in another column, so the
     * two can be compared. A hook promising revenge on a story whose payoff is
     * a refusal is not a thin field or a missing one — it is the same mismatch
     * class as an outline that escalates to an exposure and stops, which is the
     * defect the reversal phase was added for. It is the one beat that can be
     * wrong rather than merely weak.
     *
     * -----------------------------------------------------------------------
     * HOW
     * -----------------------------------------------------------------------
     *
     * The same way `checkRefusal()` works, for the same reason: overlap of
     * distinctive words, two of them, because one shared word is a coincidence
     * in any two paragraphs about the same family. The last sentence of the
     * hook is the promise; earlier sentences are the setup, the betrayal and
     * the evidence, and letting those match would make almost every hook pass
     * on its own grievance.
     *
     * It reports WHICH part of the departure it promises, sentence by sentence,
     * because "it promises something" is worth less at Gate 1 than "it promises
     * the part where nobody is given an address". That is the same argument as
     * the refusal naming the moment it answers.
     *
     * A closing line that instead reaches the exposure is named as that, rather
     * than as promising nothing. The two are different repairs: one is a hook
     * pointed at the wrong payoff and one is a hook pointed at nothing.
     *
     * @param  array<int, string>  $warnings
     * @param  array<string, array{label: string, value: string, state: string}>  $spine
     */
    private function checkHook(Story $story, array &$warnings, array &$spine): void
    {
        $text = trim((string) $story->hook);
        $departure = trim((string) $story->departure);

        // Nothing to promise against. Four stories are in this position — every
        // outline written before the reversal phase existed — and the blanket
        // legacy warning has already said so once. Reporting "the hook promises
        // nothing" here would be a second finding about the same absence, and a
        // finding the operator cannot act on without regenerating the outline.
        if ($text === '' || $departure === '') {
            return;
        }

        $promise = $this->distinctiveWords($this->lastSentence($text));

        foreach ($this->departureBeats($departure) as $label => $beat) {
            if (count(array_intersect($promise, $this->distinctiveWords($beat))) >= 2) {
                $spine['hook']['promises'] = (string) $label;

                return;
            }
        }

        $exposure = (string) $story->exposure_moment;

        if (trim($exposure) !== ''
            && count(array_intersect($promise, $this->distinctiveWords($exposure))) >= 2) {
            $warnings[] = 'The hook closes on the exposure rather than the departure. That is the '
                .'public payoff and it is what the TITLE promises; the hook has to promise the gap '
                .'before it — that the narrator will be gone, and that somebody will have to look '
                .'for them. A hook selling the reckoning on a story whose middle third is a search '
                .'is selling a different video from the one this outline builds.';

            $spine['hook']['state'] = 'weak';

            return;
        }

        $warnings[] = 'The hook does not close on the departure — its last line shares no specific '
            .'language with how the narrator goes. That last line is the promise the whole video is '
            .'made against, and this genre pays off on the gap: the reference channel frames its '
            .'own videos on "never expecting to see me and our son 5 years later", which is a '
            .'departure and a refusal and no exposure at all. Name the leaving, in the words the '
            .'departure uses.';

        $spine['hook']['state'] = 'weak';
    }

    /**
     * The departure, split into the parts a hook can promise.
     *
     * Sentences rather than fields, which is where this differs from
     * `earlierMoments()`: the departure is one column, and what a hook reaches
     * for is one thing inside it — the leaving, the silence, or the finding out
     * later. Labelled with the text itself, trimmed, because a label reading
     * "the departure's second sentence" tells the operator nothing they can act
     * on and the sentence tells them everything.
     *
     * @return array<string, string>
     */
    private function departureBeats(string $departure): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+/u', trim($departure)) ?: [];
        $beats = [];

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);

            if ($sentence === '') {
                continue;
            }

            $beats[$this->label($sentence)] = $sentence;
        }

        return $beats === [] ? ['the departure' => $departure] : $beats;
    }

    /** The last sentence of a passage, which for a hook is its promise. */
    private function lastSentence(string $text): string
    {
        $sentences = array_values(array_filter(
            array_map('trim', preg_split('/(?<=[.!?])\s+/u', trim($text)) ?: []),
            fn (string $sentence): bool => $sentence !== ''
        ));

        return $sentences === [] ? trim($text) : (string) end($sentences);
    }

    /** A sentence, short enough to sit in a badge beside a field label. */
    private function label(string $sentence): string
    {
        return mb_strlen($sentence) <= 60
            ? rtrim($sentence, '.')
            : rtrim(mb_substr($sentence, 0, 57)).'...';
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
     * The betrayal is a scene: done in front of people, with the justification
     * said aloud to the narrator's face, in chapter one.
     *
     * -----------------------------------------------------------------------
     * THE FINDING
     * -----------------------------------------------------------------------
     *
     * Seven stories measured, and the same shape in all seven: the betrayal
     * found (23's posted photos, 25's cc'd booking) or said in private at a
     * kitchen table (28-32), the justification first staged in private, its
     * first public saying at 9-11 minutes or never before the exposure. The
     * reference stages it at 1:31 of 34:46, straight after the cold open, at a
     * dinner of nine — and the man she is with never says a word.
     *
     * -----------------------------------------------------------------------
     * FOUR THINGS CHECKED, EACH ITS OWN REPAIR, ALL WARNINGS
     * -----------------------------------------------------------------------
     *
     * 1. SOMEBODY IS WATCHING. `AUDIENCE_MARKERS`, which deliberately does not
     *    contain the words a kitchen table is made of. See that constant.
     * 2. THE JUSTIFICATION IS SAID HERE. By overlap against
     *    `antagonist_justification`, sentence by sentence, two distinctive
     *    words — the refusal and hook checks' rule — and it records WHICH
     *    sentence is said aloud, because "she says something" is worth less in
     *    front of an approve button than the sentence she says.
     * 3. IT IS NOT A DISCOVERY. `DISCOVERY_MARKERS`, negation-windowed.
     * 4. ACT 1 CARRIES IT. The act script is written from the act summary, so a
     *    betrayal scene the outline describes and act 1's summary does not
     *    stage is a scene that will land at a banquet in act 2 — which is what
     *    the old "act 2 or 3" instruction produced four times. Overlap of three
     *    distinctive words with proper nouns removed first, because every
     *    passage about one story shares its cast's names and two names would
     *    pass any pair of summaries.
     *
     * WHAT IS NOT CHECKED, said so the field is not read as covered: that the
     * person it is done with is IN THE ROOM. A name in the field cannot be told
     * from a name mentioned, and a guess at that is the kind of check that
     * reports everything or nothing. The operator reads that half.
     *
     * Silent on an empty field: `handle()` has reported it as missing or as
     * unasked already.
     *
     * @param  array<int, string>  $warnings
     * @param  array<string, array{label: string, value: string, state: string}>  $spine
     */
    private function checkBetrayalScene(Story $story, array &$warnings, array &$spine): void
    {
        $text = trim((string) $story->betrayal_scene);

        if ($text === '') {
            return;
        }

        $lower = mb_strtolower($text);

        if ($this->wholeWordHits($lower, self::AUDIENCE_MARKERS) === []) {
            $warnings[] = 'The betrayal scene names nobody watching. Every story before this field '
                .'staged its betrayal at a kitchen table or had it found, and first said the '
                .'justification in public ten minutes later or never; the reference does it at a '
                .'dinner of nine, where a friend asks "Who\'s this?" and she has to answer out loud. '
                .'Name the occasion and the people in the room.';
            $spine['betrayal_scene']['state'] = 'weak';
        }

        $justification = trim((string) $story->antagonist_justification);

        if ($justification !== '') {
            $words = $this->distinctiveWords($text);
            $said = null;

            foreach ($this->departureBeats($justification) as $label => $sentence) {
                if (count(array_intersect($words, $this->distinctiveWords($sentence))) >= 2) {
                    $said = (string) $label;

                    break;
                }
            }

            if ($said !== null) {
                $spine['betrayal_scene']['says'] = $said;
            } else {
                $warnings[] = 'The antagonist\'s justification is not said in the betrayal scene — the '
                    .'scene shares no specific language with it. This is where it is FIRST SAID ALOUD, '
                    .'to the narrator\'s face, in front of people: "I want to see a different view '
                    .'before I get married" at 2:44 in the reference, and again to the friend who '
                    .'challenges her. Saved for a banquet in act 2, it arrives ten minutes late and '
                    .'in private first. Put her words from the justification into the scene.';
                $spine['betrayal_scene']['state'] = 'weak';
            }
        }

        $found = $this->unnegatedHits($lower, self::DISCOVERY_MARKERS);

        if ($found !== []) {
            $warnings[] = sprintf(
                'The betrayal scene reads as FOUND rather than done: it contains %s with nothing '
                .'negating it. Stories 23 and 25 had their betrayals found — posted photos, a booking '
                .'the narrator was copied on — and neither ever put the antagonist in a room saying '
                .'it. If the premise needs the discovery, make this scene the moment she confirms it '
                .'aloud in front of people, not the moment it was found.',
                '"'.implode('", "', array_slice($found, 0, 3)).'"'
            );
            $spine['betrayal_scene']['state'] = 'weak';
        }

        if ($story->format === StoryFormat::Anthology) {
            return;
        }

        $first = $story->acts()->where('sequence', 1)->first();

        if ($first === null || trim((string) $first->summary) === '') {
            return;
        }

        $scene = array_diff($this->distinctiveWords($text), $this->properNouns($text));
        $summary = array_diff(
            $this->distinctiveWords((string) $first->summary),
            $this->properNouns((string) $first->summary),
        );

        if (count(array_intersect($scene, $summary)) >= 3) {
            return;
        }

        $warnings[] = sprintf(
            'Act 1\'s summary does not stage the betrayal scene — it shares almost nothing with it '
            .'once the names are set aside. Act 1 is written from its summary and nothing else, so '
            .'a scene the spine describes and act 1 does not is a scene that lands somewhere later; '
            .'stories 29-32 put their first public saying at a banquet at 9-11 minutes. Act 1 opens '
            .'"%s".',
            $this->label($this->firstSentence((string) $first->summary)),
        );
        $spine['betrayal_scene']['state'] = 'weak';
    }

    /**
     * Capitalised words that are not the first word of their sentence.
     *
     * A proxy for names, and a deliberately crude one: it misses a name that
     * opens a sentence and catches "Mother" used as a name, both of which are
     * harmless here — this only removes words from an overlap count, so a
     * miss makes the check slightly easier to pass and never makes it fire.
     *
     * @return array<int, string>
     */
    private function properNouns(string $text): array
    {
        $names = [];

        foreach (preg_split('/(?<=[.!?])\s+/u', trim($text)) ?: [] as $sentence) {
            $tokens = preg_split('/\s+/u', trim($sentence)) ?: [];
            array_shift($tokens);

            foreach ($tokens as $token) {
                $word = preg_replace("/[^\p{L}\p{N}']/u", '', $token) ?? '';

                if ($word !== '' && preg_match('/^\p{Lu}/u', $word)) {
                    $names[] = mb_strtolower($word);
                }
            }
        }

        return array_values(array_unique($names));
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
     * The narrator is in the room for the exposure, by their own choice, and
     * produces the withheld information in person.
     *
     * -----------------------------------------------------------------------
     * THE FINDING
     * -----------------------------------------------------------------------
     *
     * Two of three phase stories deliver their public payoff as hearsay. Story
     * 23: "I was eight hundred kilometers away that night, and I did not hear
     * about any of it for nine days." Story 28: "my mother had flown down for
     * the new year and told me the whole thing at my kitchen table." In both,
     * the spine let something other than the narrator produce the withheld
     * information — a developer's account manager and a roommate, an automated
     * bank notice — and the writer, told in the search phase that the narrator
     * "is not watching", left him where the departure put him.
     *
     * Story 25 is the exception and the model. Its withheld information needs
     * the narrator's body: the trust "requires the settlor physically present
     * to authorize a vote". So he comes back, uninvited, in a work jacket at
     * the service door, and raises his hand. The search still fails — 590,000
     * yuan and no address — and she reaches him in the corridor afterwards
     * because he came.
     *
     * -----------------------------------------------------------------------
     * TWO THINGS CHECKED, IN THIS ORDER
     * -----------------------------------------------------------------------
     *
     * First, that the field does not read as the SEARCH succeeding. "She finds
     * me at the banquet" hands the whole search phase's cost back to her: the
     * rule is that the search fails and the narrator chooses the moment they
     * are seen. Whole-word markers with the same negation window the
     * announcement check uses, because "she did not find me — I came" is the
     * good case and it contains the marker.
     *
     * Second, by overlap against `withheld_information`, sentence by sentence
     * — the way the hook is checked against the departure — that the field
     * names the specific thing only the narrator can produce. It records WHICH
     * sentence, because "they produce something" is worth less in front of an
     * approve button than "they produce the 2016 transfer agreement".
     *
     * Silent on an empty field: `fields()` has already reported that as
     * missing, absent or unasked, and a second finding about the same
     * absence is the kind the operator learns to scroll past.
     *
     * @param  array<int, string>  $warnings
     * @param  array<string, array{label: string, value: string, state: string}>  $spine
     */
    private function checkNarratorAtExposure(Story $story, array &$warnings, array &$spine): void
    {
        $text = trim((string) $story->narrator_at_exposure);

        if ($text === '') {
            return;
        }

        $hits = $this->unnegatedHits(mb_strtolower($text), self::FOUND_MARKERS);

        if ($hits !== []) {
            // A note, not a weakness. The search succeeding is the reference's
            // own shape; what decides the field is the overlap check below.
            $spine['narrator_at_exposure']['found'] = implode('", "', array_slice($hits, 0, 3));
        }

        $words = $this->distinctiveWords($text);
        $withheld = trim((string) $story->withheld_information);

        if ($withheld !== '') {
            foreach ($this->departureBeats($withheld) as $label => $sentence) {
                if (count(array_intersect($words, $this->distinctiveWords($sentence))) >= 2) {
                    $spine['narrator_at_exposure']['produces'] = (string) $label;

                    return;
                }
            }
        }

        $warnings[] = 'The narrator at the exposure names nothing that only they can produce — it '
            .'shares no specific language with the withheld information. That is the lever: when a '
            .'document, a lawyer or a friend can put the fact on the table, the writer leaves the '
            .'narrator 800 km away and the public payoff arrives as a report (stories 23 and 28). '
            .'When it takes the narrator\'s own hand — a signature only they give, a vote that needs '
            .'them present, a bag only they carry — the writer brings them back (story 25). Name '
            .'the thing, in the withheld information\'s own words.';

        $spine['narrator_at_exposure']['state'] = 'weak';
    }

    /**
     * Every escalation act is set in the story's present.
     *
     * -----------------------------------------------------------------------
     * THE FINDING
     * -----------------------------------------------------------------------
     *
     * Story 28's present-day betrayal lands at 20:18 of 43:27. The hook opens
     * on it and obeys every rule it has; then act 1 stages the 2015 betrayal
     * in full and act 2 stages the 2017 one in full — 13:15 of staged history,
     * two of the three escalation acts — and the outline said so in as many
     * words: "Act 2 tells the second betrayal in full." Story 23 spends act 1
     * on the Spring Festival table of that year and act 2 on four years of
     * night shifts; its betrayal is at 15:01. Nothing could refuse either,
     * because nothing had asked an act WHEN it was set.
     *
     * The outline writer declares it per act now, and this refuses `prior` on
     * any act: a prior incident is cited in one sentence inside a present-day
     * act, and the line the antagonist said years ago is staged at its most
     * recent saying. Story 25 is the model — "a wife who earns more" quoted at
     * 0:18, staged at 9:17 in a present-day dinner — and it is the story that
     * works on every measure.
     *
     * A PROBLEM, not a warning, because the act script is written from the
     * summary and nothing else: an approved prior act is a bought prior act.
     * The message quotes the act's own opening sentence, so the operator is
     * looking at the text that has to change rather than a sequence number.
     *
     * A partial set — some acts declared, some null — is a problem the way an
     * unphased act in a phased outline is: the act writer reads this column,
     * and a null beside a present cannot be read as either.
     *
     * Skipped for an anthology (no shared present) and for the two known ages
     * of outline that were never asked, each of which is reported once above.
     *
     * @param  array<int, string>  $problems
     */
    private function checkTimeframe(Story $story, array &$problems, bool $unasked): void
    {
        if ($story->format === StoryFormat::Anthology || $unasked) {
            return;
        }

        $acts = $story->acts()->orderBy('sequence')->get();

        if ($acts->isEmpty()) {
            return;
        }

        $undeclared = $acts->filter(fn (Act $act): bool => $act->timeframe === null);

        if ($undeclared->isNotEmpty() && $undeclared->count() < $acts->count()) {
            $problems[] = sprintf(
                'Act(s) %s do not say whether they are set in the story\'s present while the rest '
                .'of the outline does. The act writer reads this to decide whether an earlier '
                .'incident is cited or staged, and a blank beside a "present" cannot be read as '
                .'either. Mark each one, or re-generate the outline.',
                $undeclared->pluck('sequence')->implode(', ')
            );

            return;
        }

        if ($undeclared->count() === $acts->count()) {
            // Not the unasked case — that was decided above, on the narrator
            // field as well. An outline that carries the narrator field and no
            // timeframe on any act is one somebody started fixing by hand.
            $problems[] = 'No act says whether it is set in the story\'s present. Every escalation '
                .'act has to be, and the outline writer declares it per act: mark each act, or '
                .'re-generate the outline.';

            return;
        }

        foreach ($acts as $act) {
            if ($act->timeframe !== ActTimeframe::Prior) {
                continue;
            }

            $problems[] = sprintf(
                'Act %d is set before the story\'s present: "%s" A prior incident is cited in one '
                .'sentence, with its date, inside a present-day act — never staged as the act. Story '
                .'28 spent two of its three escalation acts on 2015 and 2017 and its present-day '
                .'betrayal landed at 20:18. Rewrite this summary as what happens NOW, quote the '
                .'earlier line in a sentence, and mark the act present.',
                $act->sequence,
                $this->label($this->firstSentence((string) $act->summary)),
            );
        }
    }

    /** The first sentence of a passage, which for a summary is what it is about. */
    private function firstSentence(string $text): string
    {
        $sentences = array_values(array_filter(
            array_map('trim', preg_split('/(?<=[.!?])\s+/u', trim($text)) ?: []),
            fn (string $sentence): bool => $sentence !== ''
        ));

        return $sentences === [] ? trim($text) : $sentences[0];
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
    /**
     * Every chapter says its own number out loud, and this reads the prose to
     * see whether it did.
     *
     * -----------------------------------------------------------------------
     * THE INSTANCE
     * -----------------------------------------------------------------------
     *
     * Story 30 announced eight of its twelve chapters. The four it missed are
     * not scattered: they are both chapters of act 1 and both of act 4 — THE
     * ONLY TWO ACTS WITH A SPECIAL OPENING INSTRUCTION, act 1 carrying the
     * five hook beats and act 4 carrying the departure. Acts 2, 3, 5 and 6
     * announced both of theirs and numbered them correctly across the whole
     * story.
     *
     * That is the shape worth checking for rather than the count. An act with
     * two instructions about how it opens drops one of them, and until now
     * nothing looked at the prose to notice — the chapter rows were all
     * present, titled and sequenced, and the Gate 1 page listed them as such.
     *
     * -----------------------------------------------------------------------
     * WHY A WARNING AND NOT A PROBLEM
     * -----------------------------------------------------------------------
     *
     * Not a judgement about severity — a judgement about the panel. The
     * problems panel is headed "The outline is missing part of its structure"
     * and every finding in it is about a spine field or an act declaration.
     * This is a finding about returned PROSE, and its nearest sibling — an
     * act with no re-hook written, which is the same defect one level up —
     * is already a structural warning. Two findings of one kind in two
     * different panels is how an operator learns to read neither.
     *
     * -----------------------------------------------------------------------
     * WHAT IT DELIBERATELY DOES NOT DO
     * -----------------------------------------------------------------------
     *
     * It is silent when announcements are off, because then the prose is
     * asked to carry no marker and looking for one would report every chapter
     * in the story the day somebody flips the config. It is silent on an act
     * with no chapters, which is every story written before the chapters
     * table existed: `YoutubeMetadata::chapters()` reads acts for those by
     * design, so that is a supported state and not a defect to report on
     * twenty stories.
     *
     * And it reports WHICH of the three things went wrong — nothing spoken,
     * spoken in the wrong place, spoken with the wrong number — because they
     * are three different repairs. A wrong number is the known limit of
     * rewriting one act in the middle of a story; nothing spoken is an
     * instruction that lost a collision.
     *
     * @param  array<int, string>  $warnings
     */
    private function checkChapterAnnouncements(Story $story, array &$warnings): void
    {
        if (! ChapterAnnouncement::enabled()) {
            return;
        }

        $number = 0;

        foreach ($story->acts()->orderBy('sequence')->get() as $act) {
            $chapters = $act->chapters()->orderBy('sequence')->get();
            $sentences = $this->splitter->split((string) $act->script);

            if ($chapters->isEmpty() || $sentences === []) {
                // An act written before chapters existed, or one whose script
                // has not been written yet. Neither is this check's business
                // and both are reported elsewhere.
                $number += $chapters->count();

                continue;
            }

            foreach ($chapters as $index => $chapter) {
                $number++;

                $from = max(0, (int) $chapter->first_sentence - 1);
                $next = $chapters[$index + 1] ?? null;
                $to = $next === null
                    ? count($sentences)
                    : max($from, (int) $next->first_sentence - 1);

                $window = array_slice($sentences, $from, max(1, $to - $from));

                // The one chapter whose announcement is NOT its first
                // sentence, by contract: act 1 chapter 1 opens on the hook —
                // the cold open — and says "Chapter one." after the five
                // beats. Checking its first sentence would report the correct
                // shape as the defect, which is how a guard gets switched off.
                $openerOnly = ! ($act->sequence === 1 && $index === 0);

                $this->judgeAnnouncement($chapter, $act, $number, $window, $openerOnly, $warnings);
            }
        }
    }

    /**
     * One chapter's announcement, against the sentences it owns.
     *
     * @param  array<int, string>  $window
     * @param  array<int, string>  $warnings
     */
    private function judgeAnnouncement(
        Chapter $chapter,
        Act $act,
        int $number,
        array $window,
        bool $openerOnly,
        array &$warnings,
    ): void {
        $where = sprintf('Act %d, chapter %d ("%s")', $act->sequence, $chapter->sequence, $chapter->title);
        $wanted = ChapterAnnouncement::sentenceFor($number);

        $spokenAt = null;
        $spokenAs = null;

        foreach ($window as $offset => $sentence) {
            if (($found = ChapterAnnouncement::numberIn($sentence)) !== null) {
                $spokenAt = $offset;
                $spokenAs = $found;
                break;
            }
        }

        if ($spokenAt === null) {
            $warnings[] = sprintf(
                '%s never says its number out loud. Every chapter opens by speaking it — "%s" — '
                .'and this one opens on "%s" instead. Story 30 missed exactly four chapters this '
                .'way and all four were in the two acts that carry a second instruction about how '
                .'they open, act 1 with the hook and act 4 with the departure. Rewriting the act '
                .'is what fixes it.',
                $where,
                $wanted,
                $this->label($window[0] ?? ''),
            );

            return;
        }

        if ($spokenAs !== $number) {
            $warnings[] = sprintf(
                '%s announces itself as chapter %d, and counting from the start of the story it is '
                .'chapter %d. Chapter numbers run across the whole video, so rewriting one act to '
                .'a different chapter count shifts every act after it — rewrite from the changed '
                .'act onward rather than one act in the middle.',
                $where,
                $spokenAs,
                $number,
            );

            return;
        }

        if ($openerOnly && $spokenAt !== 0) {
            $warnings[] = sprintf(
                '%s says "%s" %d sentence(s) in rather than opening on it. The number is the '
                .'chapter\'s first sentence and the re-hook is its second; a viewer who hears the '
                .'marker after the chapter has already started cannot use it to find their place.',
                $where,
                $wanted,
                $spokenAt,
            );
        }
    }

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

        foreach (['hook', 'departure', 'reversal_beats', 'refusal'] as $field) {
            if (trim((string) $story->{$field}) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether this outline was generated after the reversal phase and before
     * the act timeframe and the narrator's presence at the exposure were asked.
     *
     * Decided on both at once: every outline generated since carries a
     * timeframe on every act and a narrator-at-exposure on the story, so
     * "no act has a timeframe AND the field is empty" cannot mean anything
     * else. Either one being present means somebody has started fixing it by
     * hand, and the ordinary per-field checks come back — the same rule
     * `predatesReversalPhase()` applies to the hook and the reversal three.
     */
    private function predatesTimeframeAndPresence(Story $story): bool
    {
        if ($story->format === StoryFormat::Anthology) {
            return false;
        }

        if (trim((string) $story->narrator_at_exposure) !== '') {
            return false;
        }

        $acts = $story->acts()->get();

        return $acts->isNotEmpty()
            && $acts->every(fn (Act $act): bool => $act->timeframe === null);
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
            // Where that justification was first said aloud, in front of
            // people — the strongest sentence a refusal can hand back, and the
            // consumer question for this field asked of the refusal check.
            'the betrayal scene' => (string) $story->betrayal_scene,
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
     * @return array<string, array{label: string, why: string, later?: bool, asked_later?: bool, betrayal_later?: bool}>
     */
    private function fields(): array
    {
        return [
            'hook' => [
                'label' => 'Hook',
                // Grouped with the reversal three not because it is part
                // of the reversal but because the flag means the same
                // thing for all four: the pre-phase outline generator
                // never wrote this field, so its absence on those four
                // stories is a fact about when they were outlined and not
                // a fault in them.
                'later' => true,
                'why' => 'The first thirty seconds, as five beats: one sentence of setup, the '
                    .'betrayal inside twenty seconds, evidence in exact words, one small cold '
                    .'action, and a closing line promising the DEPARTURE. Both shipped stories '
                    .'already contain four of the five and land every one of them two to seven '
                    .'minutes late, because act 1 was never told where the opening starts.',
            ],
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
            'betrayal_scene' => [
                'label' => 'Betrayal scene',
                // Absent on every story outlined before the field existed —
                // all three earlier ages at once — which is why it has its own
                // flag rather than borrowing `later` or `asked_later`.
                'betrayal_later' => true,
                'why' => 'The betrayal DONE, in chapter one, in front of people: the room, who is '
                    .'watching, the person it is done with standing there, the justification said '
                    .'aloud to the narrator\'s face, and the narrator\'s line back. Seven stories found '
                    .'their betrayal or heard it in private; the reference stages it at 1:31, at a '
                    .'dinner of nine, and the other man never says a word.',
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
            'narrator_at_exposure' => [
                'label' => 'Narrator at the exposure',
                // Absent on the pre-phase four, which have no departure for a
                // narrator to come back from; and absent-as-unasked on the
                // post-phase stories outlined before the question existed.
                'later' => true,
                'asked_later' => true,
                'why' => 'How the narrator comes to be in the room — by their own choice, unexpected, '
                    .'the search having failed — and what only they can produce there. When a '
                    .'document can produce it, the writer leaves the narrator 800 km away and the '
                    .'public payoff arrives as a report; stories 23 and 28 both did. Story 25 needed '
                    .'the narrator\'s body in the room and he came back.',
            ],
            'departure' => [
                'label' => 'Departure',
                'later' => true,
                'why' => 'How and when the narrator goes, and whether they announce it. They must not: '
                    .'an announced departure cannot be searched for, and the search is the next third '
                    .'of the video.',
            ],
            'reversal_beats' => [
                'label' => 'Reversal beats',
                'later' => true,
                'why' => 'What the antagonist does to find them, and what each attempt costs HER. The '
                    .'humiliation beats running the other way, escalating the same. Without them the '
                    .'middle of the reversal is empty and the refusals are unearned.',
            ],
            'refusal' => [
                'label' => 'Refusal',
                'later' => true,
                'why' => 'What the narrator says when the antagonist reaches them after the exposure, '
                    .'and which earlier moment it answers. The exposure is the public payoff; this is '
                    .'the private one, and it is what viewers wait forty minutes for.',
            ],
        ];
    }
}
