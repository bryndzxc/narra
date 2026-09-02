<?php

namespace App\Support;

use App\Support\Providers\CharacterProfile;
use RuntimeException;

/**
 * Refuses a `style_notes` that describes anything conditional or carried.
 *
 * The field is applied unconditionally. It is pasted, unchanged, into every
 * prompt its character appears in — 36 of them for a supporting character, 92
 * for a lead. That single fact makes a whole class of otherwise reasonable
 * English wrong here:
 *
 *   "often holding a phone or a handheld microphone"
 *
 * That was written by the extractor for a man who picks up a microphone in
 * exactly one scene, and it put a microphone in his hand in a parking-lot
 * argument four acts earlier. The model was not wrong; it did what the prompt
 * said. Anything qualified by "often" is by definition not always true, and
 * this field is always applied — so a hedge in it is a contradiction, not a
 * nuance.
 *
 * The extraction prompt now forbids props outright, and that is necessary but
 * not sufficient: a prompt is a request and this is an invariant. A generator
 * that drifts, a model swap, or an operator editing the field by hand all
 * bypass the prompt and none of them bypass this. The defect is invisible
 * downstream — the still looks fine, it just has a microphone in it — and by
 * the time anyone notices, 150-250 images have been paid for.
 *
 * Clothing is what belongs here. A cardigan is on her in every scene; a folder
 * is in her hand in some. Only the first kind of thing is a property of the
 * character.
 */
class StyleNotesGuard
{
    /**
     * Hedges. Every one of them concedes the thing is not always true, which
     * is the only mode this field has.
     */
    private const CONDITIONALS = [
        'often', 'sometimes', 'usually', 'occasionally', 'frequently',
        'typically', 'generally', 'normally', 'at times', 'when ',
        'may ', 'might ', 'can be seen', 'tends to',
    ];

    /**
     * Verbs of carrying. Clothing is worn; a prop is held, and the difference
     * is the whole rule.
     */
    private const CARRYING = [
        'holding', 'holds', 'carrying', 'carries', 'clutching', 'clutches',
        'gripping', 'grips', 'toting', 'with a ', 'accompanied by',
    ];

    /**
     * Objects that are held rather than worn.
     *
     * Deliberately not a list of every noun — glasses, a watch and a wedding
     * ring are worn and stay. These are the things a hand closes around, which
     * a frame decides and a character does not.
     */
    private const HANDHELD = [
        'microphone', 'mic ', 'phone', 'folder', 'purse', 'handbag', 'bag',
        'cup', 'mug', 'glass of', 'bottle', 'can of', 'keys', 'clipboard',
        'camera', 'briefcase', 'notebook', 'notepad', 'pen ', 'pencil',
        'papers', 'paperwork', 'documents', 'envelope', 'tablet', 'laptop',
        'book', 'cigarette', 'umbrella', 'wallet', 'remote', 'toolbox',
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
            foreach ($this->violations($profile->styleNotes) as $violation) {
                $problems[] = sprintf('%s — %s', $profile->name, $violation);
            }
        }

        if ($problems === []) {
            return;
        }

        throw new RuntimeException(sprintf(
            "%s produced style_notes that will misfire in every prompt they touch:\n\n  %s\n\n"
            .'style_notes is pasted unchanged into every scene its character appears in, so anything '
            .'conditional or carried in it becomes unconditional and permanent. A character who '
            .'"often" holds something holds it in all of their scenes. Clothing only: what they '
            .'wear in every frame. Props belong to the frame that needs them.',
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
        $text = mb_strtolower(trim((string) $styleNotes));

        if ($text === '') {
            return [];
        }

        $found = [];

        foreach (self::CONDITIONALS as $needle) {
            if (str_contains($text, $needle)) {
                $found[] = sprintf('conditional "%s"', trim($needle));
            }
        }

        foreach (self::CARRYING as $needle) {
            if (str_contains($text, $needle)) {
                $found[] = sprintf('carried, not worn: "%s"', trim($needle));
            }
        }

        foreach (self::HANDHELD as $needle) {
            if (str_contains($text, $needle)) {
                $found[] = sprintf('handheld object "%s"', trim($needle));
            }
        }

        return array_values(array_unique($found));
    }

    public function isClean(?string $styleNotes): bool
    {
        return $this->violations($styleNotes) === [];
    }
}
