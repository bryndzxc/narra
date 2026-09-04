<?php

namespace App\Support;

use App\Models\Character;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Assembles the final image prompt for one scene.
 *
 * The generator writes ONE part of a prompt — the frame. This class supplies
 * everything else, and that division is the point of the class existing.
 *
 * Two things are deliberately taken out of the model's hands:
 *
 *  1. **The art style.** Stated once in config/scenes.php and appended here,
 *     identically, 150-250 times. A style a language model restates per prompt
 *     is a style that drifts per prompt: by scene 90 the wording has wandered
 *     and the palette, lens and rendering have wandered with it. Tuning the
 *     channel's look then means editing one config value rather than
 *     re-generating every scene and hoping.
 *
 *  2. **Character descriptions.** Pasted verbatim from the `characters` table,
 *     never re-described. Character consistency across a long video is the
 *     single biggest quality risk in this format and it gets worse the longer
 *     the video runs. The mechanism is that this text does not vary — the same
 *     bytes, every scene, for the whole story.
 *
 * What the model contributes is the part that SHOULD change every scene: who is
 * in the frame, where they are, what their expression is, what the light is
 * doing. That is a composed picture. A prompt that restated the narration would
 * be a literal illustration of a sentence, and two hundred of those in a row
 * read as a slideshow of captions.
 */
class ImagePromptBuilder
{
    /**
     * @param  Collection<int, Character>|array<int, Character>  $cast
     * @param  array<int, string>  $present  Character names in this frame.
     */
    public function build(string $frame, iterable $cast, array $present): string
    {
        $frame = trim((string) preg_replace('/\s+/u', ' ', $frame));

        $sections = array_filter([
            $frame,
            $this->castBlock($cast, $present),
            trim((string) config('scenes.art_style')),
            trim((string) config('scenes.constraints')),
        ], fn (string $section): bool => $section !== '');

        return implode("\n\n", $sections);
    }

    /**
     * The frame back out of an assembled prompt.
     *
     * The inverse of the first section of build(), and it lives here for that
     * reason: the sections are joined by a blank line by this class, so the
     * convention for taking them apart is this class's to state. A caller that
     * split on a blank line itself would be a second copy of a rule only one
     * place writes.
     *
     * The frame is the part that varies per scene and describes the shot — the
     * only part of a stored prompt that says anything about how the picture is
     * composed. Everything after it is the frozen cast text and the art style,
     * identical across every scene of the story, so a search over the whole
     * prompt answers the same for all of them.
     */
    public static function frameFrom(string $prompt): string
    {
        $prompt = trim(str_replace('
', '
', $prompt));

        if ($prompt === '') {
            return '';
        }

        return trim(explode('

', $prompt, 2)[0]);
    }

    /**
     * The prompt for one character's reference portrait.
     *
     * Built from the same two frozen pieces every scene prompt uses — the
     * character's description and the channel's art style — plus the reference
     * frame from config/characters.php in place of the scene's frame. That
     * substitution is the whole design: a reference is a scene prompt with the
     * scene taken out.
     *
     * The style block is inherited rather than restated, and that is not
     * tidiness. A reference rendered in a different style from the stills it
     * conditions is actively harmful: the generator is being shown a face in
     * one look and asked to draw it in another, and what it resolves that
     * conflict into is anybody's guess. If the channel's look is retuned, the
     * sheets are stale and the operator is told so rather than the mismatch
     * being absorbed silently.
     */
    public function buildReference(Character $character): string
    {
        $description = trim((string) $character->description);

        if ($description === '') {
            // Refused rather than defaulted. A reference generated from a name
            // alone is a face the model invented, and it would then be pinned
            // into 150-250 stills as though somebody had chosen it.
            throw new InvalidArgumentException(sprintf(
                'Character "%s" has no description, so there is nothing to generate a reference '
                .'from. The description is the fixed text every prompt this character appears in '
                .'is built from; generating a face without one would pin an invented likeness '
                .'into every still they are in.',
                $character->name
            ));
        }

        $sections = array_filter([
            trim((string) config('characters.reference_frame')),

            // Named, not just described. The models that take several
            // references take them as an unlabelled array, so the name is what
            // ties this image to the same name in a scene's cast block.
            sprintf(
                'The person in this image, described exactly:
%s: %s%s',
                $character->name,
                $description,
                trim((string) $character->style_notes) !== ''
                    ? ' '.trim((string) $character->style_notes)
                    : ''
            ),

            config('characters.inherit_scene_style')
                ? trim((string) config('scenes.art_style'))
                : '',

            trim((string) config('scenes.constraints')),
        ], fn (string $section): bool => $section !== '');

        return implode('

', $sections);
    }

    /**
     * The fixed descriptions of the characters in this frame, and only those.
     *
     * Only those, because a prompt carrying the descriptions of six people when
     * two are in the picture invites the generator to put all six in it.
     *
     * @param  Collection<int, Character>|array<int, Character>  $cast
     * @param  array<int, string>  $present
     */
    private function castBlock(iterable $cast, array $present): string
    {
        if ($present === []) {
            return '';
        }

        $byName = [];

        foreach ($cast as $character) {
            $byName[$this->key($character->name)] = $character;
        }

        $lines = [];

        foreach ($present as $name) {
            $character = $byName[$this->key($name)] ?? null;

            if ($character === null || trim((string) $character->description) === '') {
                continue;
            }

            // Name first, then the frozen description, then wardrobe. Written
            // as a definition rather than a sentence so the generator reads it
            // as a constraint on the subject rather than as more scene.
            $lines[] = trim(sprintf(
                '%s: %s%s',
                $character->name,
                trim($character->description),
                trim((string) $character->style_notes) !== ''
                    ? ' '.trim((string) $character->style_notes)
                    : ''
            ));
        }

        return $lines === []
            ? ''
            : "The people in this image, described exactly:\n".implode("\n", $lines);
    }

    /**
     * Names are matched loosely on purpose.
     *
     * The cast is stored as "Kyle Bennett" and a frame will reasonably say
     * "Kyle". Failing to match would silently drop the one description the
     * whole consistency mechanism depends on, so first-name and case-folded
     * matches both resolve.
     */
    private function key(string $name): string
    {
        $name = mb_strtolower(trim($name));

        return (string) preg_replace('/[^a-z ]/', '', $name);
    }

    /**
     * Resolve a name the generator used against the stored cast.
     *
     * Exposed so the drafting Action can record which characters a scene
     * actually resolved to, and warn about a frame naming somebody who is not
     * in the cast — usually a sign the generator invented a person.
     *
     * @param  Collection<int, Character>|array<int, Character>  $cast
     */
    public function resolve(string $name, iterable $cast): ?Character
    {
        $wanted = $this->key($name);

        if ($wanted === '') {
            return null;
        }

        $first = explode(' ', $wanted)[0];

        foreach ($cast as $character) {
            $stored = $this->key($character->name);

            if ($stored === $wanted) {
                return $character;
            }
        }

        foreach ($cast as $character) {
            $stored = $this->key($character->name);

            if ($stored === $first || str_starts_with($stored.' ', $first.' ')) {
                return $character;
            }
        }

        return null;
    }
}
