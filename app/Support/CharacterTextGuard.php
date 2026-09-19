<?php

namespace App\Support;

use App\Support\Providers\CharacterProfile;
use RuntimeException;

/**
 * Refuses character text that describes anything conditional, carried, or
 * momentary.
 *
 * Both fields this guards are applied unconditionally. `description` and
 * `style_notes` are pasted, unchanged, into every prompt their character
 * appears in — 36 of them for a supporting character, 92 for a lead. That
 * single fact makes a whole class of otherwise reasonable English wrong here:
 *
 *   "often holding a phone or a handheld microphone"
 *
 * That was written by the extractor for a man who picks up a microphone in
 * exactly one scene, and it put a microphone in his hand in a parking-lot
 * argument four acts earlier. The model was not wrong; it did what the prompt
 * said. Anything qualified by "often" is by definition not always true, and
 * these fields are always applied — so a hedge in one is a contradiction, not a
 * nuance.
 *
 * The extraction prompt forbids all of this, and that is necessary but not
 * sufficient: a prompt is a request and this is an invariant. A generator that
 * drifts, a model swap, or an operator editing a field by hand all bypass the
 * prompt and none of them bypass this.
 *
 * **This was `StyleNotesGuard` and it checked one of the two fields.** The
 * other one had the identical defects the whole time, unguarded and in
 * production: `usually pulled back` and `usually worn down` in two leads'
 * descriptions, and `walks with a noticeable stiffness in one hip` in a third —
 * a conditional, a conditional, and a gait, each pasted into every frame those
 * people appear in. A guard aimed at one field while the same bug sat in the
 * field beside it is the shape this project keeps finding: the check could not
 * fire, and a check that cannot fire is indistinguishable from one that passed.
 *
 * The two fields do not have identical rules, which is why there are two entry
 * points. Clothing belongs in `style_notes` and nowhere else; a description
 * additionally may not carry posture, expression, or photoreal ageing texture,
 * because the first two change with every frame and the third loses an argument
 * the style constant cannot win from where it sits.
 *
 * There is a third kind of finding, and it is not a refusal. `advisories()`
 * reports headwear, which is real clothing and is sometimes what the script
 * says — but which also replaces the hair silhouette this whole cast is told
 * apart by. It is surfaced at Gate 2 and never thrown, because a rule that must
 * sometimes be overridden cannot be enforced by an exception.
 */
class CharacterTextGuard
{
    /**
     * Hedges. Every one concedes the thing is not always true, which is the
     * only mode these fields have.
     */
    private const CONDITIONALS = [
        'often', 'sometimes', 'usually', 'occasionally', 'frequently',
        'typically', 'generally', 'normally', 'at times', 'when',
        'may', 'might', 'can be seen', 'tends to', 'mostly', 'commonly',
        'habitually', 'from time to time', 'now and then', 'as a rule',
        'more often than not', 'on occasion', 'prone to', 'apt to',
    ];

    /**
     * Verbs and phrasings of carrying. Clothing is worn; a prop is held, and
     * the difference is the whole rule.
     *
     * The object list below is the primary net and this is the secondary one —
     * "and a wooden cane" names no verb at all, which is exactly how it got
     * past the first version of this guard.
     */
    private const CARRYING = [
        'holding', 'holds', 'held', 'carrying', 'carries', 'carried',
        'clutching', 'clutches', 'gripping', 'grips', 'toting', 'lugging',
        'hauling', 'cradling', 'cradles', 'wielding', 'wields', 'brandishing',
        'accompanied by', 'in hand', 'in one hand', 'in his hand', 'in her hand',
        'in their hand', 'slung over', 'tucked under', 'under one arm',
        'never without', 'always has',
    ];

    /**
     * Objects a hand closes around, which a frame decides and a character
     * does not.
     *
     * Deliberately not a list of every noun — glasses, a watch, a brooch and a
     * wedding ring are worn and stay. Matching is on whole words, so `stick`
     * does not fire on "lipstick", `card` does not fire on "cardigan", and
     * `frame` is absent entirely because "thin-framed rectangular glasses" is
     * a real description in this project's own data.
     *
     * A mobility aid is an object like any other and belongs to the frame that
     * needs it. "Plain short-sleeved button shirts, suspenders, and a wooden
     * cane" is live data from story 9: it named no carrying verb and no listed
     * object, so it passed, and that man holds a cane in all thirty-odd of his
     * scenes including the ones where he is sitting down.
     */
    private const HANDHELD = [
        // Mobility aids — the gap that prompted this list being rewritten.
        //
        // `walker` is deliberately absent: it is a common American surname on a
        // channel written for an American audience, and a false refusal here
        // burns an extraction retry and then throws. `walking frame` says the
        // same thing and cannot be a person.
        'cane', 'walking stick', 'walking frame', 'zimmer frame',
        'crutch', 'crutches', 'wheelchair',

        // the original set
        'microphone', 'mic', 'phone', 'folder', 'purse', 'handbag', 'bag',
        'cup', 'mug', 'glass of', 'bottle', 'can of', 'keys', 'clipboard',
        'camera', 'briefcase', 'notebook', 'notepad', 'pen', 'pencil',
        'papers', 'paperwork', 'documents', 'envelope', 'tablet', 'laptop',
        'book', 'cigarette', 'umbrella', 'wallet', 'remote', 'toolbox',

        // everything else a hand closes around
        'stick', 'suitcase', 'luggage', 'backpack', 'rucksack',
        'satchel', 'tote', 'basket', 'box', 'crate', 'tray', 'plate', 'bowl',
        'flask', 'thermos', 'newspaper', 'magazine', 'letter', 'letters',
        'card', 'cards', 'photograph', 'photo', 'binder', 'ledger', 'file',
        'files', 'map', 'ticket', 'receipt', 'clipboard', 'broom', 'mop',
        'rake', 'shovel', 'hammer', 'wrench', 'screwdriver', 'toolbelt',
        'knife', 'gun', 'rifle', 'pistol', 'weapon', 'bat', 'racket',
        'guitar', 'violin', 'instrument', 'toy', 'doll', 'teddy', 'bouquet',
        'flowers', 'gift', 'parcel', 'package', 'leash', 'dog',
        'wine glass', 'tumbler', 'casserole',
    ];

    /**
     * Phrases in which a listed object is not an object at all.
     *
     * -----------------------------------------------------------------------
     * THE WORD IS RIGHT AND THE READING IS WRONG
     * -----------------------------------------------------------------------
     *
     * Matching is a bare `\b<word>\b` with no head noun behind it, so `pencil`
     * fires on "knee-length pencil skirts" — a garment, in the field that is
     * FOR garments. Live instance: story 25's Amy Sun, refused twice at
     * extraction for $0.0869 of billed calls, both times on the same word,
     * because the repair loop re-asks the whole cast rather than the clause.
     *
     * **The fix is not to drop the words.** `pencil` is a real handheld object
     * and story 12 has a man carrying a leather folder; a list that removed
     * every noun with a second sense would stop catching the thing it exists
     * for. What is wrong is reading two words as one, so the exception is
     * scoped to the phrase rather than to the term.
     *
     * **Every occurrence must be excused, not merely one.** "Carries a pencil
     * and wears pencil skirts" is a violation, and a rule that suppressed the
     * term on first sight of an innocent phrase would let a real prop hide
     * behind a garment in the same sentence. The count has to match.
     *
     * `mop` and `bowl` are here on the same evidence as `pencil` and rank
     * ABOVE it: they land on HAIR, and idealised faces converge, so hair is
     * carrying more of the identification than it used to. "A mop of dark
     * hair" and "a blunt bowl cut" are ordinary phrasing for this register and
     * neither is a thing a hand closes around.
     *
     * Deliberately narrow. "A pencil case", "a bowl of soup" and "mopping the
     * floor" are untouched — each is either the object or a different word, and
     * a broad exception would be the guard going quiet, which is the one
     * direction this class must never move in.
     *
     * @var array<string, array<int, string>>
     */
    private const NOT_AN_OBJECT = [
        // Garments. The head noun is what makes it clothing.
        'pencil' => ['pencil skirt', 'pencil dress'],
        'box' => ['box pleat', 'box-pleat'],
        'knife' => ['knife pleat', 'knife-pleat'],
        'cigarette' => ['cigarette trouser', 'cigarette pant', 'cigarette leg'],
        'teddy' => ['teddy coat', 'teddy jacket'],
        'dog' => ['dog collar'],

        // Hair, and the reason these two were asked for by name.
        'mop' => ['mop of'],
        'bowl' => ['bowl cut'],

        // A colour, not a thing. The hyphen is already a word boundary, so the
        // bare match fires on "bottle-green" exactly as it does on "bottle".
        'bottle' => ['bottle green', 'bottle-green', 'bottle blonde', 'bottle-blonde'],
    ];

    /**
     * Movement and stance. Description only.
     *
     * A gait is not a face. `walks with a noticeable stiffness in one hip` is
     * live data, and it asks the generator for a man mid-stride in every frame
     * he is in — including the ones where he is sitting at a kitchen table.
     * What a person is doing belongs to the frame, and the frame is written
     * separately for each of the 150-250 of them.
     */
    private const POSTURE = [
        'walks', 'walking', 'stands', 'standing', 'sits', 'sitting',
        'leans', 'leaning', 'stoops', 'stooping', 'hunches', 'hunching',
        'slouches', 'slouching', 'limps', 'limping', 'shuffles',
        'shuffling', 'strides', 'striding', 'gestures', 'gesturing',
        'posture', 'gait', 'stance', 'carries himself', 'carries herself',
        'holds himself', 'holds herself', 'moves with',
    ];

    /*
     * Note what is NOT in that list: `stooped` and `hunched`.
     *
     * The verb forms are banned and the adjectival ones are not, and the line
     * between them is exactly the line this guard is for. "Stoops" is something
     * a person is doing in one frame; "stooped" is the shape their shoulders
     * hold in every frame.
     *
     * The justification for allowing it has since changed and the behaviour has
     * not, deliberately. It used to be allowed because it was the most useful
     * age marker available; build was then measured and found not to render at
     * all, so the extraction prompt no longer asks for it and a description that
     * still says "stooped" is merely wasting words rather than causing harm.
     * Turning a useless word into a refusal would fail paid extractions — the
     * fake writer's own reference cast among them — to enforce a preference.
     * The prompt is where "do not bother" belongs; a guard is for "must not".
     *
     * The first version of this list banned both, and it fired on the fake
     * writer's own reference cast — which is how the distinction got noticed
     * rather than shipping as a rule nobody could satisfy.
     */

    /**
     * Mood and face. Description only.
     *
     * The single most frame-dependent thing there is. A description that says
     * "warm smile" puts one in the frame where she is being told her mother
     * died.
     */
    private const EXPRESSION = [
        'smiling', 'smiles', 'grinning', 'grins', 'frowning', 'frowns',
        'scowling', 'scowls', 'glaring', 'glares', 'laughing', 'laughs',
        'crying', 'weeping', 'expression', 'demeanour', 'demeanor',
        'looking tired', 'weary', 'worn down', 'kindly', 'stern-faced',
    ];

    /**
     * Photoreal ageing texture. Description only, and a refusal.
     *
     * The style constant already says age is carried by hair colour, hairline,
     * face shape, neck and shoulder line and build, "never by wrinkles,
     * creases, liver spots, sagging or any other photoreal ageing texture".
     * That instruction was correct and it lost, twice, because the character
     * description is pasted into the prompt AHEAD of the style block and is
     * scoped to one person. "Late sixties to early seventies, small and frail
     * with rounded stooped shoulders, thinning white hair pulled into a soft
     * low bun, deeply lined round face, pale watery blue eyes, soft sagging
     * jawline" beat it in two consecutive style previews: everyone else
     * idealised and she came back at eighty-five with the lines drawn on.
     *
     * So the rule moves upstream of the thing it distrusts. A style line cannot
     * out-argue a per-character instruction sitting in front of it; a guard at
     * extraction means the per-character instruction is never written.
     *
     * WHAT IS DELIBERATELY ABSENT, and the line is the same one `stooped` and
     * `hunched` sit on: face SHAPE stays. `jowled`, `gaunt`, `hollow`,
     * `sunken`, `angular` and `softly rounded` are all structure, they all
     * survive a wide shot, and the extraction prompt explicitly asks for them.
     * Only skin is banned here — what a face has been through, rather than what
     * shape it is.
     */
    private const AGEING_TEXTURE = [
        // Lines, in every form the extractor has actually produced
        'wrinkle', 'wrinkles', 'wrinkled', 'deeply lined', 'lined face',
        'smile lines', 'laugh lines', 'mouth lines', 'frown lines',
        'worry lines', 'fine lines', 'age lines', 'crow\'s feet', 'crows feet',
        'creased', 'furrowed', 'weathered', 'leathery', 'crepey', 'papery',

        // Slack, as distinct from the shape a face is
        'sagging', 'saggy', 'sagged', 'drooping jowls', 'loose skin',

        // Pigment
        'liver spots', 'age spots', 'sun spots', 'blotchy', 'mottled',
        'liver-spotted',
    ];

    /**
     * Headwear. An advisory, never a refusal — see advisories().
     */
    private const HEADWEAR = [
        // Bare nouns only. Matching is whole-word, so 'cap' already covers
        // "ball cap", "baseball cap" and "knit cap", and 'hat' covers "sun hat"
        // and "bucket hat" — listing the compounds as well reported one hat
        // three times on the Gate 2 panel. Neither fires inside another word:
        // 'cap' does not match "capri" and 'hat' does not match "that".
        'hat', 'hats', 'cap', 'caps', 'beanie', 'beret', 'visor', 'fedora',
        'headscarf', 'head scarf', 'bandana', 'bandanna', 'hairnet',
        'turban', 'helmet', 'hood up',
    ];

    /**
     * @param  array<int, CharacterProfile>  $characters
     *
     * @throws RuntimeException
     */
    public function assert(array $characters, string $stage): void
    {
        $problems = [];

        foreach ($characters as $profile) {
            // The offending TEXT, not only the rule it broke.
            //
            // This message is what an operator reads when a paid stage
            // dies, and it used to name the term and withhold the sentence
            // it came from — while textProblems(), the note nobody sees,
            // carried both. An operator-facing failure strictly less
            // informative than an internal one is backwards, and answering
            // "on what text" cost a billed call that should have been a grep.
            foreach ($this->violations($profile->styleNotes) as $violation) {
                $problems[] = sprintf(
                    '%s — style_notes: %s%s      in: "%s"',
                    $profile->name,
                    $violation,
                    PHP_EOL,
                    trim((string) $profile->styleNotes),
                );
            }

            foreach ($this->descriptionViolations($profile->description) as $violation) {
                $problems[] = sprintf(
                    '%s — description: %s%s      in: "%s"',
                    $profile->name,
                    $violation,
                    PHP_EOL,
                    trim((string) $profile->description),
                );
            }
        }

        if ($problems === []) {
            return;
        }

        throw new RuntimeException(sprintf(
            "%s produced character text that will misfire in every prompt it touches:\n\n  %s\n\n"
            .'Both fields are pasted unchanged into every scene their character appears in, so '
            .'anything conditional, carried or momentary becomes unconditional and permanent. A '
            .'character who "usually" wears their hair back wears it back in all of them; a '
            .'character who "walks with a limp" is mid-stride in the ones where they are sitting '
            .'down; and a "deeply lined" face is drawn with the lines on it in all of them, '
            .'because this text reaches the generator ahead of the art style and beats it. '
            .'description is fixed physical fact, and age belongs in hairline, hair color, face '
            .'shape and build rather than in skin. style_notes is clothing. Props, poses and '
            .'expressions belong to the frame that needs them.',
            ucfirst($stage),
            implode("\n  ", $problems),
        ));
    }

    /**
     * Warnings rather than a refusal, for surfacing stored rows at a gate.
     *
     * The same check pointed at data that already exists. A story drafted
     * before this guard has the defect baked into its prompts and cannot be
     * fixed by throwing at it.
     *
     * @return array<int, string>
     */
    public function violations(?string $styleNotes): array
    {
        return $this->scan($styleNotes, [
            'conditional "%s"' => self::CONDITIONALS,
            'carried, not worn: "%s"' => self::CARRYING,
            'handheld object "%s"' => self::HANDHELD,
        ]);
    }

    /**
     * The same, for `description`, plus the two categories only a description
     * can get wrong.
     *
     * @return array<int, string>
     */
    public function descriptionViolations(?string $description): array
    {
        return $this->scan($description, [
            'conditional "%s"' => self::CONDITIONALS,
            'carried, not worn: "%s"' => self::CARRYING,
            'handheld object "%s"' => self::HANDHELD,
            'posture or movement "%s" — that belongs to the frame' => self::POSTURE,
            'expression or mood "%s" — that belongs to the frame' => self::EXPRESSION,
            'ageing texture "%s" — put age in hairline, hair color, face shape and build instead' => self::AGEING_TEXTURE,
        ]);
    }

    /**
     * What this guard refuses, in the model's own terms, generated from the
     * lists above.
     *
     * This exists because the retry prompt used to restate the rules by hand
     * and the two copies disagreed. The guard banned `weathered`; the rejection
     * note listed "no wrinkles, no deeply lined, no sagging, no liver spots"
     * and did not. So an extraction that failed on `weathered` was handed a
     * correction that never mentioned the word, re-asked, and produced it
     * again — six billed calls across three dispatches, every one of them
     * refused for the same term.
     *
     * A hand-written summary of a machine-checked list is a second source of
     * truth for the same rule, and the two only have to agree on the day they
     * are written. Generating it removes the possibility rather than fixing
     * this instance of it.
     *
     * Not the whole vocabulary — that is several hundred terms and most of a
     * retry's budget. A few representative ones per category plus the exact
     * offending term, which the caller passes separately and is the thing that
     * actually needs to land.
     */
    public function ruleSummary(): string
    {
        $sample = static fn (array $terms, int $take): string => implode(', ', array_slice($terms, 0, $take)).', and similar';

        return implode("\n", [
            'description is FIXED PHYSICAL FACT. It is refused if it contains:',
            '  - a hedge: '.$sample(self::CONDITIONALS, 6),
            '  - a carried object or a verb of carrying: '.$sample(self::CARRYING, 5)
                .' / '.$sample(self::HANDHELD, 6),
            '  - posture, gait or movement: '.$sample(self::POSTURE, 6),
            '  - expression or mood: '.$sample(self::EXPRESSION, 5),
            // Listed in FULL, alone among the categories, because this is the
            // one with a demonstrated escape. The prompt banned "weathered
            // skin"; the model wrote "weathered square jaw" and read itself as
            // compliant. A near-miss on a partial list is how that happens, so
            // this category gets no near-misses to aim at.
            '  - ageing texture, and this list is exhaustive: '.implode(', ', self::AGEING_TEXTURE),
            'style_notes is CLOTHING. It is refused for a hedge or a carried object, the same two lists.',
            'Face SHAPE is not texture and is wanted: gaunt, jowled, angular, softly rounded, hollow-cheeked.',
        ]);
    }

    /**
     * Headwear: worth an operator's eye, never worth a refusal.
     *
     * A hat is genuinely clothing, so none of the rules above reach it — and it
     * should not be refused, because a script can legitimately require one. But
     * it is not neutral clothing either. Hair is the primary identifier in this
     * style and the thing the whole cast-level distinctness check is built on,
     * and a cap replaces that silhouette with the same silhouette every other
     * character in a cap has. Kyle's "ball cap pushed back" also simply read as
     * wrong once the look moved to idealised anime.
     *
     * So this is the third kind of finding in this class, and the reason it
     * needed its own method rather than a row in the lists above: those two
     * feed assert(), which throws. Putting headwear in one of them would make
     * "allow it where the script requires it" impossible — the extraction would
     * refuse the cast and retry until the model dropped a hat the story needs.
     *
     * Surfaced at Gate 2 beside the other character-text findings, where an
     * operator can look at it and say yes. A soft rule with no surface is not a
     * soft rule, it is a comment.
     *
     * @return array<int, string>
     */
    public function advisories(?string $text): array
    {
        return $this->scan($text, [
            'headwear "%s" — it covers the hair silhouette this cast is told apart by, '
                .'so keep it only if the script needs it' => self::HEADWEAR,
        ]);
    }

    public function isClean(?string $styleNotes): bool
    {
        return $this->violations($styleNotes) === [];
    }

    public function isDescriptionClean(?string $description): bool
    {
        return $this->descriptionViolations($description) === [];
    }

    /**
     * Whole-word matching, which is the other half of the fix.
     *
     * The first version used `str_contains`, so the list carried trailing-space
     * hacks — `'mic '`, `'pen '` — to stop `mic` firing on "microphone" and
     * `pen` on "open". Those hacks then failed at a line end or before a comma,
     * which is most of the places these words actually appear. A word boundary
     * says what was meant, and it is what makes it safe to list `stick` next to
     * "lipstick" and `card` next to "cardigan".
     *
     * @param  array<string, array<int, string>>  $categories  message template => needles
     * @return array<int, string>
     */
    private function scan(?string $text, array $categories): array
    {
        $text = mb_strtolower(trim((string) $text));

        if ($text === '') {
            return [];
        }

        $found = [];

        foreach ($categories as $template => $needles) {
            foreach ($needles as $needle) {
                $hits = preg_match_all('/\b'.preg_quote($needle, '/').'\b/u', $text);

                if ($hits < 1) {
                    continue;
                }

                // Every occurrence excused is not a violation; one left over
                // is. See NOT_AN_OBJECT — "carries a pencil and wears pencil
                // skirts" must still fire.
                if ($hits - $this->excused($text, $needle) < 1) {
                    continue;
                }

                $found[] = sprintf($template, $needle);
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * How many occurrences of `$needle` in `$text` are part of a phrase that
     * makes it not an object.
     *
     * Counted rather than flagged, so a garment cannot excuse a prop standing
     * beside it in the same sentence.
     *
     * The phrase is matched with a word boundary at the FRONT only: "pencil
     * skirt" has to cover "pencil skirts" and "cigarette trouser" has to cover
     * "cigarette trousers", and listing every plural separately is the
     * hand-maintained second copy this class already refuses to keep.
     */
    private function excused(string $text, string $needle): int
    {
        $excused = 0;

        foreach (self::NOT_AN_OBJECT[$needle] ?? [] as $phrase) {
            $excused += preg_match_all('/\b'.preg_quote($phrase, '/').'/u', $text);
        }

        return $excused;
    }
}
