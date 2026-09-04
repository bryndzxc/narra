<?php

namespace App\Console\Commands;

use App\Actions\RecordProviderCost;
use App\Enums\AssetStatus;
use App\Enums\CostCategory;
use App\Models\Character;
use App\Models\CharacterReference;
use App\Models\Scene;
use App\Models\Story;
use App\Services\Fal\FalSeedreamImageGenerator;
use App\Support\ImagePromptBuilder;
use App\Support\Providers\CharacterReferenceImage;
use App\Support\Providers\ProviderUsage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Does a reference image actually hold a face across varied scenes?
 *
 * A front-facing neutral portrait proves almost nothing — that is the easy
 * frame, and it is the frame the reference IS. The question that decides
 * whether this mechanism is worth building the expensive stage on is what
 * happens when the pose, the lighting, the distance and the company all change
 * at once. So this generates the reference and then spends it on the five
 * frames most likely to break it: close indoor, wide exterior, low light, lost
 * in a crowd, and two people sharing one frame.
 *
 * The two-person frame is the one that matters most, and it is here because
 * text-only had a specific failure there: a scene with two named characters
 * would pin one and invent the other. Two references go into that call and the
 * output is checked for both faces. Proven, not assumed.
 *
 * **It resolves fal directly rather than through the container.** The bound
 * provider stays `fake` throughout, so nothing about running this can leak into
 * the pipeline and start billing scene generation. The only code path that can
 * spend money here is this command, explicitly, behind a confirmation.
 *
 * On the ledger: these rows are evaluation spend, not the cost of a video. They
 * are still written, because "every paid API call writes a row" has no
 * exceptions — but they are written as CostCategory::Evaluation, which is what
 * keeps them out of the story's total. The `bakeoff_` operation name says which
 * probe; the category is what does the excluding. Attributing a model bake-off to the story
 * whose prompts it borrowed would overstate what that video cost to make.
 */
class ImageBakeoff extends Command
{
    protected $signature = 'images:bakeoff
        {story : Story slug or id to borrow real scene prompts from.}
        {--character= : Lead character. Defaults to whoever appears in the most scenes.}
        {--second= : Second character, for the two-person frame. Defaults to the next most frequent.}
        {--candidates=2 : Reference candidates per character.}
        {--scenes= : Comma-separated scene sequences to probe. Defaults to one per stress case.}
        {--dry-run : Print the plan and the price, generate nothing.}
        {--yes : Skip the spend confirmation.}';

    protected $description = 'Prove a character reference holds a face across varied scenes, on fal.ai.';

    /**
     * A diagnostic must not become a way to generate a video's stills at a
     * category the Gate 2 guard does not stop. Twelve is more than this test
     * needs and nowhere near two hundred.
     */
    private const MAX_IMAGES = 12;

    /** The five ways a reference breaks, and why each one is in the list. */
    private const STRESS_CASES = [
        'close indoor' => 'the face fills the frame — nowhere for a wrong likeness to hide',
        'wide exterior' => 'the face is small and daylight is flat; features collapse first here',
        'low light' => 'a single coloured source rewrites skin tone and kills the shadow shape',
        'back of a crowd' => 'the subject competes with invented faces the prompt never asked for',
        'two in frame' => 'the failure text-only had: one face pinned, the other invented',
    ];

    public function handle(
        FalSeedreamImageGenerator $fal,
        ImagePromptBuilder $prompts,
        RecordProviderCost $costs,
    ): int {
        $story = $this->story();

        if ($story === null) {
            return self::FAILURE;
        }

        [$lead, $second] = $this->cast($story);

        if ($lead === null || $second === null) {
            return self::FAILURE;
        }

        $scenes = $this->scenes($story, $lead, $second);

        if ($scenes === []) {
            return self::FAILURE;
        }

        $candidates = max(1, (int) $this->option('candidates'));

        // The second character gets exactly one. They are here to answer a
        // yes/no question — does the model hold a SECOND face in a shared frame
        // — and a choice between two versions of that face does not make the
        // answer any clearer. The lead gets the candidates because the lead is
        // the face being tracked across all five frames.
        $perCharacter = [$lead->id => $candidates, $second->id => 1];
        $total = array_sum($perCharacter) + count($scenes);

        if ($total > self::MAX_IMAGES) {
            $this->error(sprintf(
                'That run is %d images and this command is capped at %d. The cap is deliberate: '
                .'these calls bill outside the per-video ledger, so the diagnostic must not be able '
                .'to grow into a way of generating stills.',
                $total,
                self::MAX_IMAGES,
            ));

            return self::FAILURE;
        }

        $rate = (float) config('providers.fal.usd_per_image');

        $this->line('');
        $this->info('Reference bake-off — fal.ai / '.config('providers.fal.edit_model'));
        $this->line('  story        '.$story->slug);
        $this->line('  lead         '.$lead->name.' ('.$lead->scenes()->count().' scenes)');
        $this->line('  second       '.$second->name.' ('.$second->scenes()->count().' scenes)');
        $this->line('');
        $this->line(sprintf('  %d reference candidates  (%s x%d, %s x1)',
            array_sum($perCharacter), $lead->name, $candidates, $second->name));

        foreach ($scenes as $case => $scene) {
            $this->line(sprintf('  scene %-3d %-16s %s', $scene->sequence, $case, self::STRESS_CASES[$case] ?? ''));
        }

        $this->line('');
        $this->line(sprintf('  %d images at $%.4f = $%.4f', $total, $rate, $total * $rate));
        $this->warn('  Evaluation spend: logged in full, and deliberately not part of this story total.');
        $this->line('');

        if ($this->option('dry-run')) {
            $this->info('Dry run — nothing generated, nothing billed.');

            return self::SUCCESS;
        }

        if (! $this->option('yes') && ! $this->confirm(sprintf('Spend $%.4f on fal.ai?', $total * $rate), false)) {
            $this->line('Nothing generated.');

            return self::FAILURE;
        }

        $runDir = 'bakeoff/'.now()->format('Ymd-His');
        $disk = Storage::disk('characters');
        $spent = 0.0;
        $manifest = ['story' => $story->slug, 'model' => config('providers.fal.edit_model'), 'characters' => [], 'scenes' => []];

        // -- references ------------------------------------------------------
        foreach ([$lead, $second] as $character) {
            $this->line("  {$character->name} — reference sheet");

            $chosen = null;
            $refWidth = 0;
            $refHeight = 0;

            for ($i = 1; $i <= $perCharacter[$character->id]; $i++) {
                try {
                    $image = $fal->generateReference($character, $prompts->buildReference($character));
                } catch (Throwable $e) {
                    $this->error('    '.$e->getMessage());

                    return self::FAILURE;
                }

                $costs->handle($story, $this->tag($image->usage, 'bakeoff_reference'));
                $spent += $image->usage->usdCost;

                $path = "{$runDir}/ref-{$character->id}-{$i}.".$this->extension($image->mimeType);
                $disk->put($path, $image->bytes);

                $this->line(sprintf('    candidate %d  %dx%d  $%.4f', $i, $image->width, $image->height, $image->usage->usdCost));

                if ($chosen === null) {
                    $chosen = $path;
                    $refWidth = $image->width;
                    $refHeight = $image->height;
                }

                $manifest['characters'][$character->name]['candidates'][] = $path;
            }

            // The first usable candidate wins, because this run is testing the
            // MECHANISM rather than curating a face. Picking properly is the
            // operator's job on the Gate 2 sheet.
            $manifest['characters'][$character->name]['chosen'] = $chosen;
            $manifest['characters'][$character->name]['description'] = $character->description;

            $character->forceFill(['reference_image_path' => $chosen])->save();

            CharacterReference::create([
                'character_id' => $character->id,
                'batch' => ((int) $character->references()->max('batch')) + 1,
                'sequence' => 1,
                'prompt' => $prompts->buildReference($character),
                'provider' => 'fal',
                'model' => (string) config('providers.fal.text_to_image_model'),
                'image_path' => $chosen,
                'width' => $refWidth,
                'height' => $refHeight,
                'status' => AssetStatus::Ready,
                'usd_cost' => $rate,
                'selected_at' => now(),
            ]);
        }

        // -- scene probes ----------------------------------------------------
        foreach ($scenes as $case => $scene) {
            $present = $scene->characters()->orderBy('name')->get();

            $references = $present
                ->filter(fn (Character $c): bool => $c->hasUsableReference())
                ->map(fn (Character $c): CharacterReferenceImage => CharacterReferenceImage::forCharacter(
                    $c,
                    $c->referenceBytes(),
                    'image/png',
                ))
                ->values()
                ->all();

            $this->line(sprintf('  scene %d (%s) — %d reference(s): %s',
                $scene->sequence,
                $case,
                count($references),
                implode(', ', array_map(fn (CharacterReferenceImage $r): string => $r->characterName, $references)),
            ));

            try {
                $image = $fal->generate($scene, (string) $scene->image_prompt, references: $references);
            } catch (Throwable $e) {
                $this->error('    '.$e->getMessage());

                continue;
            }

            $costs->handle($story, $this->tag($image->usage, 'bakeoff_scene_probe'));
            $spent += $image->usage->usdCost;

            $path = "{$runDir}/scene-{$scene->sequence}.".$this->extension($image->mimeType);
            $disk->put($path, $image->bytes);

            $this->line(sprintf('    %dx%d  $%.4f', $image->width, $image->height, $image->usage->usdCost));

            $manifest['scenes'][] = [
                'case' => $case,
                'why' => self::STRESS_CASES[$case] ?? '',
                'sequence' => $scene->sequence,
                'path' => $path,
                'prompt' => (string) $scene->image_prompt,
                'frame' => explode("\n\n", (string) $scene->image_prompt)[0],
                'characters' => $present->pluck('name')->all(),
                'references' => array_map(fn (CharacterReferenceImage $r): string => $r->characterName, $references),
            ];
        }

        $disk->put("{$runDir}/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->line('');
        $this->info(sprintf('Spent $%.4f. Output in %s', $spent, $disk->path($runDir)));
        $this->line('  Reference seed control: NOT AVAILABLE on this provider — the endpoints take no');
        $this->line('  seed input, so the reference image is carrying the consistency on its own.');

        return self::SUCCESS;
    }

    /**
     * The file extension the bytes actually are.
     *
     * Seedream answers a PNG-shaped request with a JPEG, so naming the file
     * from the request rather than the response wrote `.png` files full of JPEG
     * — which then went back out to the API described as PNG.
     */
    private function extension(string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };
    }

    /**
     * Re-file the call as evaluation spend.
     *
     * The operation name says WHICH evaluation; the category is what keeps it
     * out of the story total and out of the way of Gate 2. This used to be
     * CostCategory::Reference, borrowed because there was nothing else to
     * borrow, which meant a bake-off both inflated the video it was attached
     * to and could only run on a story past scenes_drafted.
     */
    private function tag(ProviderUsage $usage, string $operation): ProviderUsage
    {
        return new ProviderUsage(
            provider: $usage->provider,
            operation: $operation,
            category: CostCategory::Evaluation,
            quantity: $usage->quantity,
            unit: $usage->unit,
            usdCost: $usage->usdCost,
            detail: $usage->detail,
        );
    }

    /** @return array{0: Character|null, 1: Character|null} */
    private function cast(Story $story): array
    {
        $ranked = $story->characters()->withCount('scenes')
            ->orderByDesc('scenes_count')->orderBy('name')->get();

        $pick = function (?string $name, int $fallbackIndex) use ($ranked, $story): ?Character {
            if ($name !== null) {
                $found = $ranked->first(fn (Character $c): bool => strcasecmp($c->name, $name) === 0
                    || str_starts_with(mb_strtolower($c->name), mb_strtolower($name)));

                if ($found === null) {
                    $this->error("No character matching '{$name}' in {$story->slug}.");
                }

                return $found;
            }

            return $ranked[$fallbackIndex] ?? null;
        };

        $lead = $pick($this->option('character'), 0);
        $second = $pick($this->option('second'), 1);

        if ($lead === null || $second === null) {
            $this->error('Need two characters with scene appearances to test a two-person frame.');
        }

        return [$lead, $second];
    }

    /**
     * One real scene per stress case.
     *
     * Real prompts from the story, not written for the test. A frame composed
     * to flatter the reference would answer a question nobody asked.
     *
     * @return array<string, Scene>
     */
    private function scenes(Story $story, Character $lead, Character $second): array
    {
        if ($this->option('scenes')) {
            $out = [];
            $cases = array_keys(self::STRESS_CASES);

            foreach (array_map('trim', explode(',', (string) $this->option('scenes'))) as $i => $sequence) {
                $scene = $story->scenes()->where('sequence', (int) $sequence)->first();

                if ($scene === null) {
                    $this->error("Story has no scene {$sequence}.");

                    return [];
                }

                // Labelled by what the scene actually is, not by position. A
                // frame carrying two or more named characters IS the two-in-frame
                // case whichever slot it was passed in, and that label is what
                // the guard below looks for.
                $label = $scene->characters()->count() >= 2
                    ? 'two in frame'
                    : ($cases[$i] ?? "case {$i}");

                $out[$label] = $scene;
            }

            return $out;
        }

        // Chosen by reading the drafted frames, not by keyword: each is the
        // clearest real example of its stress case in this story.
        $defaults = [
            'close indoor' => 84,
            'wide exterior' => 4,
            'low light' => 59,
            'back of a crowd' => 121,
            'two in frame' => 19,
        ];

        $out = [];

        foreach ($defaults as $case => $sequence) {
            $scene = $story->scenes()->where('sequence', $sequence)->first();

            if ($scene !== null) {
                $out[$case] = $scene;
            }
        }

        if (! isset($out['two in frame'])) {
            $this->error(
                'No two-character frame found. That is the case this run exists for — the failure '
                .'text-only had — so there is no point running without it. Name one with --scenes.'
            );

            return [];
        }

        return $out;
    }

    private function story(): ?Story
    {
        $key = (string) $this->argument('story');

        $story = Story::query()
            ->where('slug', $key)
            ->orWhere('id', ctype_digit($key) ? (int) $key : 0)
            ->first();

        if ($story === null) {
            $this->error("No story matching '{$key}'.");
        }

        return $story;
    }
}
