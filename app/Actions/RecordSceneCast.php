<?php

namespace App\Actions;

use App\Models\Character;
use App\Models\Scene;
use App\Models\Story;
use App\Support\ImagePromptBuilder;
use Illuminate\Support\Collection;

/**
 * Writes down who is in each frame.
 *
 * DraftScenes already worked this out — it resolves the names a generated frame
 * mentions against the stored cast, and uses the result to decide whose frozen
 * descriptions get pasted into the image prompt — and then dropped it on the
 * floor. The prompt's prose was the only surviving record of who was in scene
 * 147.
 *
 * That was fine while a prompt was the only consumer and stopped being fine the
 * moment a reference image had to be attached per character. "A scene featuring
 * a character with no reference must fail loudly" is the strongest guarantee in
 * this feature, and it cannot rest on substring-matching names against a text
 * field the operator is free to rewrite at Gate 2.
 *
 * `backfill()` exists because the first real story was drafted before this
 * table did. It re-derives presence from the cast block ImagePromptBuilder
 * wrote into each prompt, which is safe to parse precisely because the app
 * wrote it in a fixed shape rather than a model having phrased it — and because
 * it is free. Re-running the scene generator to recover data the generator
 * already produced would be paying twice for the same answer.
 */
class RecordSceneCast
{
    public function __construct(private readonly ImagePromptBuilder $prompts) {}

    /**
     * @param  array<int, string>  $names  Already resolved against the cast.
     * @param  Collection<int, Character>  $cast
     */
    public function handle(Scene $scene, array $names, Collection $cast): void
    {
        $ids = [];

        foreach ($names as $name) {
            $character = $this->prompts->resolve($name, $cast);

            if ($character !== null) {
                $ids[$character->id] = true;
            }
        }

        // sync() rather than attach(): re-drafting a scene must leave the pivot
        // describing the new frame, not the union of every frame it has ever
        // had. A character removed from a scene who stayed attached would keep
        // blocking Gate 2 for a face nobody needs.
        $scene->characters()->sync(array_keys($ids));
    }

    /**
     * Recover presence for scenes drafted before the pivot existed.
     *
     * @return array{scenes: int, links: int, unresolved: array<int, string>}
     */
    public function backfill(Story $story, bool $overwrite = false): array
    {
        $cast = $story->characters()->get();
        $scenes = 0;
        $links = 0;
        $unresolved = [];

        if ($cast->isEmpty()) {
            return ['scenes' => 0, 'links' => 0, 'unresolved' => []];
        }

        $story->scenes()->with('characters')->chunkById(200, function ($chunk) use ($cast, $overwrite, &$scenes, &$links, &$unresolved): void {
            foreach ($chunk as $scene) {
                // A scene that already has presence recorded is left alone
                // unless asked otherwise. The prompt is operator-editable and
                // the pivot is not derived from it after drafting; overwriting
                // would let an edited prompt silently rewrite who is in a frame.
                if (! $overwrite && $scene->characters->isNotEmpty()) {
                    continue;
                }

                $names = $this->namesInPrompt((string) $scene->image_prompt, $cast, $unresolved);

                $this->handle($scene, $names, $cast);

                $scenes++;
                $links += count($names);
            }
        });

        return ['scenes' => $scenes, 'links' => $links, 'unresolved' => array_values(array_unique($unresolved))];
    }

    /**
     * Read the cast block back out of a built prompt.
     *
     * Parses the block ImagePromptBuilder emits — a fixed header followed by
     * one `Name: description` line per character — and stops at the blank line
     * that ends it. Anchored on the header rather than scanning the whole
     * prompt for names, so a frame that merely MENTIONS somebody ("a photo of
     * Kyle on the shelf") does not record them as present and demand a face for
     * a person who is not in the picture.
     *
     * @param  Collection<int, Character>  $cast
     * @param  array<int, string>  $unresolved
     * @return array<int, string>
     */
    private function namesInPrompt(string $prompt, Collection $cast, array &$unresolved): array
    {
        $header = 'The people in this image, described exactly:';
        $position = mb_strpos($prompt, $header);

        if ($position === false) {
            return [];
        }

        $block = mb_substr($prompt, $position + mb_strlen($header));
        $block = explode("\n\n", ltrim($block, "\n"))[0];

        $names = [];

        foreach (explode("\n", $block) as $line) {
            $line = trim($line);

            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }

            $name = trim(explode(':', $line, 2)[0]);

            if ($name === '') {
                continue;
            }

            if ($this->prompts->resolve($name, $cast) === null) {
                // Reported rather than dropped. A name in a prompt that matches
                // nobody in the cast means either the cast changed after
                // drafting or the generator invented a person, and both are
                // things the operator should be told rather than have quietly
                // skipped.
                $unresolved[] = $name;

                continue;
            }

            $names[] = $name;
        }

        return $names;
    }
}
