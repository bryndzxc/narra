<?php

namespace App\Support;

use App\Enums\StoryEnding;
use App\Models\Story;

/**
 * The spine questions, one method each. The ONLY copy.
 *
 * ---------------------------------------------------------------------------
 * WHY THESE LEFT THE OUTLINE PROMPT
 * ---------------------------------------------------------------------------
 *
 * Until 2026-09-19 every question was a clause inside one `sprintf` in
 * `ClaudeScriptWriter::outlinePrompt()`. That was fine while the outline was
 * the only caller. The premise generator asks six of the same questions before
 * the story has an outline, and the only alternative to this class was a
 * second copy of those six — the fourth place the genre's rules would live,
 * beside `genreGuidance()`, the outline prompt and the Gate 1 checks, and the
 * one that drifts first because nothing compares it with the others.
 *
 * So a question is asked by calling its method, from both prompts. The outline
 * prompt was captured before the move and compared after it: byte-identical
 * except the one space `withheld_information:the` was missing.
 *
 * Each method returns the question's BODY. `bullet()` adds the "- field: "
 * lead and the newline, so the composition stays with the caller and the text
 * cannot be edited in one prompt and not the other.
 *
 * Each question still names the check Gate 1 runs on its answer ("Gate 1
 * checks this against it"). That stays true in the premise prompt: the
 * premise generator runs the same checks on its candidates.
 */
final class SpineQuestions
{
    /** The spine in generation order, as the outline asks it. */
    public const OUTLINE_ORDER = [
        'narrator_grievance',
        'antagonist_justification',
        'accomplice_motive',
        'accomplice_performance',
        'betrayal_scene',
        'withheld_information',
        'exposure_moment',
        'narrator_at_exposure',
        'departure',
        'reversal_beats',
        'accomplice_fall',
        'running_thought',
        'refusal',
        'antagonist_regret',
        'hook',
    ];

    /**
     * The spine this story's outline is asked for, in OUTLINE_ORDER.
     *
     * `antagonist_regret` only when the chosen ending is the antagonist's
     * chapter (StoryEnding::asksForRegret). It was required on every outline
     * until 2026-09-19, which is how every story from 37 on was asked for her
     * chapter AND the epilogue. A story with no ending — outlined before the
     * choice — keeps the full list, which is what it was asked for. Dropping
     * the field also gives the outline back ~439 output tokens (story 37's
     * regret), at 91% of its ceiling.
     *
     * @return array<int, string>
     */
    public static function outlineOrderFor(?StoryEnding $ending): array
    {
        if ($ending === null || $ending->asksForRegret()) {
            return self::OUTLINE_ORDER;
        }

        return array_values(array_diff(self::OUTLINE_ORDER, ['antagonist_regret']));
    }

    /** One question as a prompt bullet. */
    public static function bullet(string $field, Story $story): string
    {
        return '- '.$field.': '.self::body($field, $story)."\n";
    }

    /** @param  array<int, string>  $fields */
    public static function bullets(array $fields, Story $story): string
    {
        return implode('', array_map(static fn (string $f): string => self::bullet($f, $story), $fields));
    }

    public static function body(string $field, Story $story): string
    {
        return match ($field) {
            'narrator_grievance' => self::narratorGrievance(),
            'antagonist_justification' => self::antagonistJustification(),
            'accomplice_motive' => self::accompliceMotive(),
            'accomplice_performance' => self::accomplicePerformance(),
            'betrayal_scene' => self::betrayalScene(),
            'withheld_information' => self::withheldInformation(),
            'exposure_moment' => self::exposureMoment(),
            'narrator_at_exposure' => self::narratorAtExposure(),
            'departure' => self::departure(),
            'reversal_beats' => self::reversalBeats(),
            'accomplice_fall' => self::accompliceFall(),
            'running_thought' => self::runningThought(),
            'refusal' => self::refusal(),
            'antagonist_regret' => self::antagonistRegret(),
            'hook' => self::hook($story),
        };
    }

    public static function narratorGrievance(): string
    {
        return "who wronged the narrator and how, in the narrator's own "
            .'first-person words. Two or three sentences. Name the relationship and the '
            .'specific thing that was taken.';
    }

    public static function antagonistJustification(): string
    {
        return "the antagonist's own account of why they were "
            .'entitled to do it, in THEIR words. It has to be something a real person would '
            .'say and believe. If it reads as an admission of wrongdoing, it is wrong.';
    }

    public static function accompliceMotive(): string
    {
        return 'WHAT THE ACCOMPLICE WANTS FOR HIMSELF — the person in your cast '
            .'with the accomplice role. Not what she wants: his own stake, which she does not know '
            .'about. Money, a position, shares, a house, a debt he needs cleared. It is already true '
            .'when the story starts, it is why he is in the room at all, and it comes out in front '
            .'of her in the last three acts. An accomplice with no stake has nothing to say and '
            .'stands there; one with a stake talks. If your cast has no accomplice, return an empty '
            .'string here, in accomplice_performance and in accomplice_fall.';
    }

    public static function accomplicePerformance(): string
    {
        return 'THE HARMLESS ACT HE PUTS ON FOR HER, and what the narrator '
            .'sees through. The role he plays to be let close — the old friend who only wants to '
            .'help, the loyal colleague, the considerate relative who hates to see anyone upset — '
            .'and the voice he plays it in: reasonable, apologetic, eager to smooth things over, '
            .'offering to take the blame so that she defends him. Quote at least one line he says '
            .'TO THE NARRATOR in that voice — to the narrator\'s face, not to the room, the table or '
            .'the guests; the room overhears it. He talks in the betrayal scene and in every escalation '
            .'act, and he wins those rounds with her, because she takes his side. The narrator '
            .'sees through the act and says so only in their head. BUILD THE ACT ON A ROLE, NEVER '
            .'ON SEXUAL ORIENTATION, GENDER EXPRESSION, OR A MANNER MOCKED AS UNMANLY — no "he is not '
            .'into women", no soft voice as the tell. That is excluded, and an outline that uses it '
            .'is refused.';
    }

    public static function betrayalScene(): string
    {
        return 'THE BETRAYAL AS A SCENE, NOT A DISCOVERY. The first chapter after the '
            .'hook is the betrayal being DONE, in the story\'s present, in a room with people in it — '
            .'not a message found, a booking read, photos posted or news heard later. Say where it '
            .'happens and say who is watching, including the one who asks the question that makes '
            .'her say it out loud — by who they are, unless they are in your cast. Put WHO IT IS DONE '
            .'WITH OR FOR in the room, by their cast name, AND HAVE THEM SPEAK TO THE NARRATOR in the '
            .'act you wrote in accomplice_performance: the reasonable, apologetic line, quoted, said '
            .'to the narrator\'s face with everyone listening, that makes her defend him. Then she says '
            .'the antagonist_justification ALOUD, to the narrator\'s face, in front of all of them, '
            .'in the words you wrote above — this is where it is first said, not a banquet in act 2. '
            .'QUOTE HER HERE: write her words into this scene, never "she delivers the justification" '
            .'or "she says it" — a pointer to another field is not a scene, and Gate 1 checks this '
            .'scene for her words. '
            .'Then the narrator\'s one line back, and how the narrator still loses the round. If the '
            .'premise has the betrayal found, the scene is the moment she confirms it aloud in front '
            .'of people rather than the moment it was found.';
    }

    public static function withheldInformation(): string
    {
        return 'the specific thing the narrator knows and the '
            .'antagonist does not. It must already be true at the start of the story, and '
            .'the narrator must have a plausible reason not to say it — one the narrator can '
            .'state out loud in their own words in act 1, in a sentence, because the audience is '
            .'told all of this early and the antagonist is not. Write the reason here as that '
            .'sentence. AND WHAT THE NARRATOR '
            .'MUST PRODUCE IN PERSON: a fact a document, a lawyer or a friend can produce on '
            .'their own lets the narrator stay eight hundred kilometers away while it comes '
            .'out, and the public payoff arrives as hearsay. Make it something only the '
            .'narrator, present in the room, can put on the table — a signature only they can '
            .'give, a vote that needs them there, a bag only they carry.';
    }

    public static function exposureMoment(): string
    {
        return 'where the truth comes out, and WHO IS IN THE ROOM. Name the '
            .'occasion and say who the witnesses are — named only if they are in your cast. This '
            .'is the PUBLIC payoff, and the narrator is in '
            .'the room for it.';
    }

    public static function narratorAtExposure(): string
    {
        return 'how the narrator comes to be in that room — by their own '
            .'choice, unexpected, or because she has found where they are and come — and what only '
            .'they produce there. Either way the scene is the NARRATOR\'S: they decide where and how '
            .'long, and she does not get it on her terms. Reuse the specific thing from '
            .'withheld_information, because Gate 1 checks this against it.';
    }

    public static function departure(): string
    {
        return 'how and when the narrator leaves, and what finally makes staying '
            .'impossible. The break may be said out loud once; WHERE THEY WENT IS NOT ANNOUNCED — no '
            .'note, no farewell speech, no address, and the people who know are asked not to tell '
            .'her. She finds out later, from somebody else, that they are gone.';
    }

    public static function reversalBeats(): string
    {
        return 'what the antagonist does to find them and to reach them, as at least '
            .'two escalating attempts, and what each one COSTS HER. Money, then standing, then the '
            .'people who backed her excuse, then her face in public. She and the narrator are IN THE '
            .'SAME SCENE in at least two of these — she runs into them, follows them, sits down '
            .'across from them — and each meeting goes worse for her than the last. These are the '
            .'humiliation beats running the other way, and a search that costs her nothing is a '
            .'montage of somebody looking worried.';
    }

    public static function accompliceFall(): string
    {
        return 'WHERE THE ACCOMPLICE LOSES, AND HOW OFTEN. Not one humiliation: a '
            .'run of losses across the last three acts, starting no earlier than the departure act, '
            .'in at least three scenes, most of them shared with the narrator and every one of them '
            .'in front of people, each costing him more than the last — his position, his standing '
            .'with her, the people his act fooled, and finally the thing he wanted. Name the moment '
            .'his motive comes out in front of her, in the words of accomplice_motive, because Gate 1 '
            .'checks the two against each other. Then where he ends up, and that it is worse than '
            .'where she ends up. The narrator does not engineer any of it: the truth, the people in '
            .'the room and his own act do. His losses are her side losing, not the narrator winning '
            .'early. Empty if your cast has no accomplice.';
    }

    /**
     * The ONE joke that travels between acts. Not the act's supply of jokes —
     * `genreGuidance()` asks for three one-offs per act, and this field is
     * explicitly not them.
     *
     * -----------------------------------------------------------------------
     * THE TWO EXAMPLES THAT WERE HERE PRODUCED FOUR IDENTICAL STORIES
     * -----------------------------------------------------------------------
     *
     * It used to offer three shapes: "a running tally, a bill they are
     * mentally sending somebody, a name they privately give someone". Every
     * story in the database that has a running thought came back with an
     * ITEMIZED INVOICE — 36, 37, 38 and 39, four of four, and 36 wrote a name
     * as well. The examples were read as a list to draw from, which is the
     * en-CN guidance's own defect one field over: sixteen of seventy-two cast
     * entries were that list's example names (3f).
     *
     * Two of the three were accounting metaphors, and "petty and precise"
     * pushed the same way. The third is the one that produced the only line
     * of story 39 that works the way this genre's jokes work — "the volunteer
     * fireman. Always first at the fire, always smelling faintly of gasoline."
     * So the survivor is the picture, and the reason is mechanical rather than
     * taste: THIS FIELD GETS SAID ALOUD IN THE REFUSAL, and a concept has to
     * be re-explained where it pays off while a picture does not. Story 39's
     * payoff spends 47 words reading the invoice back — the terms, the
     * amount, the interest — because an invoice means nothing unless you
     * restate it. "There he is, the volunteer fireman" is six words.
     */
    public static function runningThought(): string
    {
        return 'THE NARRATOR\'S ONE PRIVATE JOKE, THE ONE THAT COMES BACK. Not the act\'s '
            .'jokes — those are written per act and are different every time. This is the single '
            .'one that travels: a short, funny thought the narrator first has in chapter one and '
            .'keeps having, in their head, as the story goes on. MAKE IT A PICTURE, NOT A CONCEPT '
            .'— something a viewer can see and could have thought of themselves, needing nothing '
            .'explained before it is funny. A name they privately give someone works best: "the '
            .'volunteer fireman, always first at the fire, always smelling faintly of gasoline". '
            .'A joke built on an idea the audience has to hold in their head — an account, a '
            .'ledger, a running total, anything itemized — is the shape to avoid: it needs setting '
            .'up the first time and re-explaining at the end. Petty and precise, never crude, and '
            .'about the situation, the other side or themselves. Nobody in the story hears it. '
            .'Write it as the narrator thinks it. In the refusal it is said aloud, once, and it '
            .'has to land in one line when it is.';
    }

    public static function refusal(): string
    {
        return 'what the narrator says when the antagonist asks them to come back, and '
            .'WHICH EARLIER MOMENT EACH REFUSAL ANSWERS. Name that moment. The strongest version '
            .'hands back the sentence she said in the betrayal scene, in her words, from the other '
            .'side of it, and names a loss she can no longer repair. ONE REFUSAL PAYS OFF THE '
            .'running_thought: the narrator finally says it out loud — frank, not crude, and not a '
            .'gloat — in its own specific words, because Gate 1 checks the two against each other. '
            .'This is the PRIVATE payoff and it is what viewers stay forty minutes for.';
    }

    public static function antagonistRegret(): string
    {
        return 'THE ANTAGONIST\'S LAST CHANCE, AND WHAT A YEAR LOOKS LIKE. The '
            .'final chapter of the video is told by the antagonist, in their own first person, about '
            .'a year after the refusal. Write what it reveals. FIRST, one chance to put it right that '
            .'somebody offered the antagonist during the story and they threw away, which the narrator '
            .'never learned about: who offered it (someone in your cast), on which day of the story — '
            .'tie it to an event above, by name — what they said, and what the antagonist said back, '
            .'in those words. SECOND, what about a year later looks like from inside the antagonist\'s '
            .'life: one or two things that can be seen — an object still where it was left, a seat '
            .'nobody sits in, their parents\' faces at a dinner — and ONE fact about where the narrator '
            .'is now that the antagonist only knows from outside. One, not a list of the narrator\'s '
            .'year: this is the only ending the video has, and it is the antagonist\'s, not a report '
            .'on the narrator. ABOUT A YEAR, NOT TEN OR TWENTY: the '
            .'antagonist is drawn from one picture of their face at the age they are in this story. '
            .'No moral, no apology they get to deliver, no reunion. '
            .'It ends on the loss, in one concrete sentence.';
    }

    /**
     * The five beats of the first thirty seconds. Takes the story because the
     * betrayal deadline is in words at the story's own sizing rate, from the
     * one place that owns the conversion (ScriptSizing).
     */
    public static function hook(Story $story): string
    {
        return 'the first thirty seconds of the video, as five beats in this order. This is '
            .'the highest-leverage text in the whole script, and it is the one place where writing '
            ."the chronological beginning loses the viewer. DO NOT START AT THE BEGINNING:\n"
            .'    1. ONE sentence of setup. One. Not a second one, and never a sentence about the '
            .'video itself, no "I want to start there", no "to understand this you need to know". '
            ."A second sentence of setup is the beat this most often loses.\n"
            .sprintf(
                '    2. The betrayal itself, stated within %s words. Not its aftermath and not a '
                .'summary of how it turned out: the thing that was done, being done, in the room '
                ."it happened in — the betrayal_scene you wrote above, compressed to a sentence. "
                ."That is %d seconds of narration at the rate this script is being written to.\n",
                number_format(ScriptSizing::hookBetrayalWords($story)),
                (int) round(ScriptSizing::hookBetrayalSeconds()),
            )
            .'    3. Evidence in EXACT WORDS. A line of dialogue, a message or a document, quoted '
            .'rather than described. Draw on the antagonist_justification you wrote above: the '
            .'hook is where it lands first, as the bait. THE BETRAYAL SCENE KEEPS IT. This genre '
            .'plays the same line twice, once here in a single sentence and again straight after '
            .'the hook, in chapter one, in full, said aloud in the room with everyone watching — '
            .'and the second landing is stronger for the first. Do not spend it here, and do not '
            ."paraphrase it in either place.\n"
            .'    4. ONE small, cold action by the narrator. Not a confrontation, not a speech and '
            .'not a threat: something quiet and exact. A spreadsheet opened and named, a bag '
            .'packed, a flat courtesy said to somebody expecting a fight. THE RECKONING is the '
            .'final act, and spending it here spends the video. A round the narrator LOSES is not '
            .'the reckoning: the betrayal scene after the hook is one, and the narrator answers '
            ."back in it.\n"
            .'    5. A closing line promising the DEPARTURE. Not revenge, not the exposure and not '
            .'a reckoning: that the narrator is going to be GONE, and that somebody is going to '
            .'have to look for them. Use the specific language of the departure you wrote above, '
            .'because that is the promise this video actually pays off, and Gate 1 checks this '
            .'line against it. A hook promising revenge on a story whose payoff is a refusal is '
            .'selling a different video.';
    }
}
