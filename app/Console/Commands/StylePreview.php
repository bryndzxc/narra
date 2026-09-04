<?php

namespace App\Console\Commands;

use App\Actions\RecordProviderCost;
use App\Enums\CostCategory;
use App\Models\Character;
use App\Models\Scene;
use App\Models\Story;
use App\Services\Fal\FalSeedreamImageGenerator;
use App\Support\ImagePromptBuilder;
use App\Support\Providers\ProviderUsage;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * See a candidate art style before it becomes the house style.
 *
 * `config/scenes.php` says the art style "is the channel's visual identity and
 * it will be tuned often". Until now there was no way to look at a tuning
 * except to apply it and generate a video — which means the first thing
 * rendered in a new style is 150-250 stills of a real story, and the way you
 * find out it was wrong is by watching them.
 *
 * Four frames, chosen because they are the four things a style block has to
 * survive and they fail differently:
 *
 *   - **a single face, close** — the frame with nowhere to hide. If the style
 *     renders a sixty-year-old as a thirty-year-old, this is where it shows.
 *   - **two people in one frame** — the model is juggling two descriptions and
 *     a composition, and the style is the first thing it drops.
 *   - **nobody in frame** — roughly a quarter of a real story. A style block
 *     written while thinking about characters usually says nothing useful
 *     about background art, and this is the frame that proves it.
 *   - **three people at distance** — the hardest of the four and the one that
 *     decides a retune. It has to hold three distinguishable silhouettes AND
 *     three legible relative ages at a range where no facial detail survives.
 *
 * **The candidate is never written to config.** It is passed to
 * ImagePromptBuilder through a runtime override for this process only, so a
 * preview run cannot leave the house style changed behind it. Applying a style
 * stays a deliberate edit to `.env` or `config/scenes.php`.
 *
 * **It resolves fal directly rather than through the container**, exactly as
 * `images:bakeoff` does and for the same reason: the bound image provider stays
 * whatever it was, so nothing about running this can leak into the pipeline and
 * start billing scene generation. The only code path here that can spend money
 * is this command, explicitly, behind a confirmation.
 *
 * On the ledger: these rows are CostCategory::Evaluation. They are written in
 * full, because "every paid API call writes a row" has no exceptions, and they
 * are kept out of the borrowed story's total, because attributing a style test
 * to the video whose cast it happened to use would overstate what that video
 * cost to make. The `style_preview_*` operation name says which frame; the
 * category is what does the excluding. That order matters — for two phases the
 * name was the whole mechanism, which meant the exclusion only happened if
 * whoever wrote the query remembered the prefix.
 */
class StylePreview extends Command
{
    protected $signature = 'style:preview
        {story : Story slug or id to borrow real characters from.}
        {--style= : The candidate style string. Overrides --style-file.}
        {--style-file= : Path to a file holding the candidate style string.}
        {--current : Preview the style currently in config, for a side-by-side.}
        {--lead= : Character for the portrait. Defaults to whoever appears in the most scenes.}
        {--second= : Second character for the two-hander. Defaults to the next most frequent.}
        {--trio= : Comma-separated names for the wide trio shot. Defaults to the three most frequent.}
        {--dry-run : Print the prompts and the price, generate nothing.}
        {--yes : Skip the spend confirmation.}';

    protected $description = 'Generate four test images against a candidate art style, without applying it.';

    /**
     * The four frames, and what each one is for.
     *
     * Deliberately fixed rather than borrowed from the story's scenes. A real
     * scene's frame carries its own composition, and comparing two styles
     * across two different frames compares the frames. These are constant, so
     * the only thing that changes between runs is the style.
     */
    private const FRAMES = [
        'portrait' => [
            'why' => 'one face, close — nowhere for a wrong age or a wrong likeness to hide',
            'frame' => 'Close interior shot, chest up, of one person standing at a kitchen window in '
                .'late afternoon light, turned three-quarters toward the camera, looking past it. '
                .'A quiet unguarded moment, not posed.',
        ],
        'two_hander' => [
            'why' => 'two descriptions and a composition at once — the style is the first thing dropped',
            'frame' => 'Interior mid shot of two people on opposite sides of a kitchen table in a '
                .'small suburban house, mid-conversation and not looking at each other. '
                .'Afternoon light from a window to the left.',
        ],
        'establishing' => [
            'why' => 'nobody in frame — roughly a quarter of a real story, and the frame a '
                .'character-focused style block says nothing about',
            'frame' => 'Wide exterior establishing shot of a modest two-storey clapboard house on a '
                .'quiet American residential street, bare trees, an empty driveway, overcast '
                .'late-winter afternoon. No people anywhere in the frame.',
        ],
        'trio' => [
            'why' => 'three women at distance — the frame that decides whether descriptions carry '
                .'silhouette or only texture',
            'frame' => 'Wide interior shot of three women standing apart from each other in a '
                .'crowded living room after a funeral, full figures visible, none of them close '
                .'to the camera. Grey daylight through a large window behind them.',
        ],
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

        $style = $this->candidateStyle();

        if ($style === null) {
            return self::FAILURE;
        }

        [$lead, $second] = $this->cast($story);

        if ($lead === null || $second === null) {
            return self::FAILURE;
        }

        $trio = $this->trio($story, $lead, $second);

        if ($trio === null) {
            return self::FAILURE;
        }

        // For this process only. Never written back — see the class docblock.
        // ImagePromptBuilder reads config, so overriding it here is what makes
        // the previewed prompt the SAME assembly production would send rather
        // than a hand-built lookalike.
        config()->set('scenes.art_style', $style);

        $cast = $story->characters()->get();
        // The generator's signature takes a Scene, and with an empty reference
        // set it reads nothing off it — the call is text-to-image on the prompt
        // this command built. A real scene is used when the story has one so
        // nothing here depends on that staying true; an in-memory row stands in
        // when it does not, which is the ordinary case for a story extracted
        // with --cast-only precisely so no scenes exist yet.
        $anchor = $story->scenes()->orderBy('sequence')->first()
            ?? new Scene(['sequence' => 0, 'story_id' => $story->id]);

        $built = [];

        foreach (self::FRAMES as $key => $spec) {
            $present = match ($key) {
                'portrait' => [$lead->name],
                'two_hander' => [$lead->name, $second->name],
                'trio' => $trio->pluck('name')->all(),
                default => [],
            };

            $built[$key] = [
                'why' => $spec['why'],
                'present' => $present,
                'prompt' => $prompts->build($spec['frame'], $cast, $present),
            ];
        }

        // No gate check here, and its absence is the fix rather than an
        // oversight. This used to refuse any story below `scenes_drafted`,
        // because the rows were written as CostCategory::Reference and the
        // ledger gates that category at Gate 2 — so a style test, which wants
        // the earliest story that HAS a cast, could only run on a story that
        // had been walked forward through a gate for no editorial reason. The
        // rows are CostCategory::Evaluation now, which is ungated because
        // nothing about a four-image preview is what Gate 2 holds back.

        $rate = (float) config('providers.fal.usd_per_image');
        $total = count($built);

        $this->line('');
        $this->info('Style preview — fal.ai / '.config('providers.fal.text_to_image_model'));
        $this->line('  story    '.$story->slug);
        $this->line('  lead     '.$lead->name);
        $this->line('  second   '.$second->name);
        $this->line('  trio     '.$trio->pluck('name')->implode(', '));
        $this->line('');
        $this->line('  CANDIDATE STYLE');

        foreach (explode("\n", wordwrap($style, 84)) as $line) {
            $this->line('    '.$line);
        }

        $this->line('');

        foreach ($built as $key => $spec) {
            $this->line(sprintf('  %-13s %s', $key, $spec['why']));
        }

        $this->line('');
        $this->line(sprintf('  %d images at $%.4f = $%.4f', $total, $rate, $total * $rate));
        $this->warn('  Evaluation spend: logged in full, and deliberately not part of this story total.');
        $this->warn('  Nothing is written to config. Applying a style stays a deliberate edit.');
        $this->line('');

        if ($this->option('dry-run')) {
            foreach ($built as $key => $spec) {
                $this->line("--- {$key} ---");
                $this->line($spec['prompt']);
                $this->line('');
            }

            $this->info('Dry run — nothing generated, nothing billed.');

            return self::SUCCESS;
        }

        if (! $this->option('yes') && ! $this->confirm(sprintf('Spend $%.4f on fal.ai?', $total * $rate), false)) {
            $this->line('Nothing generated.');

            return self::FAILURE;
        }

        $runDir = 'style-preview/'.now()->format('Ymd-His');
        $disk = Storage::disk('characters');
        $spent = 0.0;

        $manifest = [
            'story' => $story->slug,
            'model' => config('providers.fal.text_to_image_model'),
            'style' => $style,
            'constraints' => (string) config('scenes.constraints'),
            'frames' => [],
        ];

        foreach ($built as $key => $spec) {
            $this->line("  {$key}...");

            try {
                // Text-to-image, and deliberately not conditioned on the stored
                // reference sheets. Those were generated in the CURRENT style,
                // so feeding them in would ask the model to draw a face from one
                // look in another and average the two — which would show neither
                // style honestly. What this run answers is what the candidate
                // does from the description alone.
                $image = $fal->generate($anchor, $spec['prompt'], references: []);
            } catch (Throwable $e) {
                $this->error('    '.$e->getMessage());

                continue;
            }

            $costs->handle($story, $this->tag($image->usage, "style_preview_{$key}"));
            $spent += $image->usage->usdCost;

            $path = "{$runDir}/{$key}.".$this->extension($image->mimeType);
            $disk->put($path, $image->bytes);

            $this->line(sprintf('    %dx%d  $%.4f  %s', $image->width, $image->height, $image->usage->usdCost, $path));

            $manifest['frames'][$key] = [
                'why' => $spec['why'],
                'characters' => $spec['present'],
                'path' => $path,
                'prompt' => $spec['prompt'],
            ];
        }

        $disk->put(
            "{$runDir}/manifest.json",
            (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );

        $this->line('');
        $this->info(sprintf('Spent $%.4f. Output in %s', $spent, $disk->path($runDir)));
        $this->line('  The style is NOT applied. To adopt it, set SCENE_ART_STYLE or edit config/scenes.php.');

        return self::SUCCESS;
    }

    /**
     * The candidate, from a flag or a file.
     *
     * A file is the realistic route: a style block is five or six sentences and
     * passing that through a shell argument is how quotes get mangled.
     */
    private function candidateStyle(): ?string
    {
        if ($this->option('current')) {
            return trim((string) config('scenes.art_style'));
        }

        $inline = trim((string) $this->option('style'));

        if ($inline !== '') {
            return $inline;
        }

        $file = trim((string) $this->option('style-file'));

        if ($file === '') {
            $this->error('Give a candidate style: --style, --style-file, or --current to preview what '
                .'config already holds.');

            return null;
        }

        if (! is_readable($file)) {
            $this->error("Cannot read style file '{$file}'.");

            return null;
        }

        $style = trim((string) file_get_contents($file));

        if ($style === '') {
            $this->error("Style file '{$file}' is empty.");

            return null;
        }

        return $style;
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

    /**
     * The two characters for the portrait and the two-hander.
     *
     * @return array{0: ?Character, 1: ?Character}
     */
    private function cast(Story $story): array
    {
        $byFrequency = $this->byFrequency($story);

        if ($byFrequency->count() < 2) {
            $this->error("Story {$story->slug} has fewer than two characters, so there is no "
                .'two-hander to draw.');

            return [null, null];
        }

        $lead = $this->named((string) $this->option('lead'), $byFrequency) ?? $byFrequency->first();
        $second = $this->named((string) $this->option('second'), $byFrequency)
            ?? $byFrequency->reject(fn (Character $c): bool => $c->id === $lead->id)->first();

        return [$lead, $second];
    }

    /**
     * The three for the wide shot, named rather than inherited.
     *
     * Separate from the two-hander's cast on purpose. The trio frame answers
     * one specific question — are THESE people distinguishable at distance —
     * and the people worth asking it about are the ones whose descriptions look
     * alike, which is rarely the same set as the two most frequent. On the
     * story this was built for, the two-hander is a woman and her brother
     * (trivially distinguishable) while the risk sits with three women whose
     * hair was described only by length and colour.
     *
     * @return Collection<int, Character>|null
     */
    private function trio(Story $story, Character $lead, Character $second): ?Collection
    {
        $byFrequency = $this->byFrequency($story);
        $requested = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->option('trio')),
        )));

        if ($requested !== []) {
            $found = collect($requested)->map(fn (string $name): ?Character => $this->named($name, $byFrequency));

            if ($found->contains(null)) {
                $this->error('Could not resolve every name in --trio against this story\'s cast.');

                return null;
            }

            if ($found->count() !== 3) {
                $this->error('--trio takes exactly three names; the frame is a three-person shot.');

                return null;
            }

            return $found->values();
        }

        if ($byFrequency->count() < 3) {
            $this->error("Story {$story->slug} has fewer than three characters, so the wide trio "
                .'shot — the frame that decides whether the descriptions carry silhouette — cannot '
                .'be drawn.');

            return null;
        }

        return $byFrequency->reject(
            fn (Character $c): bool => in_array($c->id, [$lead->id, $second->id], true)
        )->prepend($second)->prepend($lead)->take(3)->values();
    }

    /** @return Collection<int, Character> */
    private function byFrequency(Story $story): Collection
    {
        return $story->characters()
            ->withCount('scenes')
            ->orderByDesc('scenes_count')
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  Collection<int, Character>  $cast
     */
    private function named(string $name, $cast): ?Character
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        $found = $cast->first(
            fn (Character $c): bool => (int) $c->id === (int) $name
                || str_contains(mb_strtolower($c->name), mb_strtolower($name))
        );

        if ($found === null) {
            $this->warn("No character matching '{$name}' — falling back to frequency.");
        }

        return $found;
    }

    /**
     * The file extension the bytes actually are.
     *
     * Seedream answers a PNG-shaped request with a JPEG, so naming the file
     * from the request rather than the response writes `.png` files full of
     * JPEG.
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
     * The operation name says WHICH frame; the category is what keeps this out
     * of the borrowed story's total and out of the way of Gate 2. It used to be
     * CostCategory::Reference, which is why this command needed a story past
     * `scenes_drafted` to answer a question about a cast that exists from
     * `scripted`.
     *
     * `detail`, `simulated` and `model` are carried through. The first version
     * of this dropped all three, which left a preview row unable to say which
     * model drew it — the audit trail behind usd_cost, discarded on the way to
     * renaming the operation.
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
            simulated: $usage->simulated,
            model: $usage->model,
        );
    }
}
