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
use App\Support\StyleFingerprint;
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
        {--axis=scene : Which axis to vary — `scene` (the five stress frames) or `expression`.}
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

    /**
     * The base frame for the expression axis, held constant.
     *
     * Fixed rather than borrowed from a scene, and that reverses this class's
     * usual rule on purpose. The `scene` axis varies the FRAME, so its frames
     * have to be real ones — a frame composed to flatter the reference would
     * answer a question nobody asked. The `expression` axis varies the
     * EXPRESSION, so the frame is the thing that has to hold still, and
     * StylePreview's rule applies instead: comparing two rungs across two
     * different frames compares the frames.
     *
     * Written to be free of the confounds this project has already measured:
     *
     *   - **It names its setting.** A close frame that names nowhere for the
     *     camera to be comes back as the reference sheet with props added — see
     *     ValidateSceneDrafts::checkCloseFramesNameTheirSetting(). Leaving the
     *     room out would mix that failure into every rung.
     *   - **It is evenly lit.** `low light` is already one of the scene axis's
     *     five stress cases because a single coloured source rewrites a face.
     *   - **It says nothing about the face.** Whatever a rung appends is then
     *     the only expression instruction in the prompt.
     *   - **It carries no pronoun**, so the same string serves any character.
     */
    private const EXPRESSION_BASE_FRAME =
        'Close on %s in a small university dormitory room in the early evening, seated at a '
        .'desk with a phone held near one shoulder, a reading lamp lit on the desk and the '
        .'window dark beyond it.';

    /**
     * The rungs, escalating, all in the NEGATIVE direction.
     *
     * Negative because that is the direction with no coverage at all. Across
     * 899 drafted scenes in four stories — every one of them a betrayal,
     * departure and refusal — exactly 11 peopled frames carry any overt
     * negative affect: 1.7%. The positive direction already has a field answer
     * (story 21 scene 189 asked for "grinning, cheeks flushed" against a
     * reference and got it), so the unmeasured case is a referenced face asked
     * to fall apart.
     *
     * Two of the rungs are not invented. `hedged` is the wording from story 21
     * scene 204, which rendered indistinguishable from the neutral reference
     * sheet; `plain` is the wording from scene 14, which rendered a hard glare.
     * Putting both in the ladder is what turns a field observation into a
     * controlled one.
     */
    private const EXPRESSION_RUNGS = [
        'absent' => [
            'clause' => '',
            'why' => 'no expression instruction at all — what about half of every story already does',
        ],
        'hedged' => [
            'clause' => 'Jaw tight, eyes narrowed slightly.',
            'why' => 'story 21 scene 204 verbatim: rendered as the neutral reference sheet',
        ],
        'plain' => [
            'clause' => 'Brows drawn together, mouth set hard.',
            'why' => 'story 21 scene 14 verbatim: rendered a hard glare',
        ],
        'strong' => [
            'clause' => 'Openly crying, eyes wet and red-rimmed, tears on both cheeks.',
            'why' => 'grief stated plainly — never once asked for in 899 drafted scenes',
        ],
        'extreme' => [
            'clause' => 'Shouting, mouth wide open, brows driven down, face contorted.',
            'why' => 'the far end: where a face is furthest from a neutral portrait',
        ],
    ];

    /**
     * The rung the unreferenced control is run at.
     *
     * The far end, because that is where the instruction and the reference
     * disagree most, so a drag is largest and most legible there. It also makes
     * the answer cheap in the good case: if the REFERENCED image at this rung is
     * fully expressive, there is no drag to find at any rung below it and the
     * control has settled the question with one image.
     */
    private const EXPRESSION_CONTROL_RUNG = 'extreme';

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

        // Routed BEFORE anything else, because everything below this line
        // generates reference candidates and writes the winner onto
        // `characters.reference_image_path`. The expression axis must not: its
        // entire subject is the face that is already approved and already
        // conditioning stills, and regenerating that face would replace the
        // thing being measured with a fresh sample of it.
        if ((string) $this->option('axis') === 'expression') {
            return $this->runExpressionAxis($story, $fal, $prompts, $costs);
        }

        if ((string) $this->option('axis') !== 'scene') {
            $this->error("Unknown axis '{$this->option('axis')}'. Use `scene` or `expression`.");

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
     * Does asking a referenced face for an expression cost the face?
     *
     * The question every proposed fix for inert faces depends on, and one
     * nothing in this app had measured. Character consistency here rests on ONE
     * unmeasured image: the provider honours no seed (see
     * FalSeedreamImageGenerator's note), so `characters.seed` is the number the
     * model happened to use rather than a lever, and the reference sheet is
     * carrying the whole load on its own. That sheet is deliberately a neutral
     * front-facing portrait — so the further a requested expression is from
     * neutral, the further the generator is from the image it is matching to.
     *
     * Six images, one variable:
     *
     *   five rungs, WITH the approved reference, escalating from no expression
     *   instruction at all to a face coming apart — plus ONE unreferenced
     *   control at the far rung.
     *
     * **What the control can and cannot say, stated here because the limit is
     * structural rather than an oversight.** The edit endpoint requires an input
     * image, so "without a reference" necessarily means the text-to-image
     * endpoint and therefore a different model. The pair answers the operational
     * question — does the path this pipeline actually uses damp affect relative
     * to the path it uses for faceless frames — and it CANNOT separate "the
     * reference drags" from "the two endpoints differ". Reporting it as the
     * former would be exactly the substitution this project keeps paying for.
     * The same confound sits in the field observation that prompted it: story 21
     * scene 249's expressive face is an unnamed extra, who has no reference
     * because he has no cast row.
     *
     * Nothing here is written back. No reference is generated, no
     * `reference_image_path` moves, no `character_references` row is created,
     * and the story's scenes are untouched — the run reads the approved face and
     * spends six images looking at it.
     */
    private function runExpressionAxis(
        Story $story,
        FalSeedreamImageGenerator $fal,
        ImagePromptBuilder $prompts,
        RecordProviderCost $costs,
    ): int {
        $lead = $this->expressionLead($story);

        if ($lead === null) {
            return self::FAILURE;
        }

        // Any scene, and it is used for nothing but the provider's argument
        // shape and its error messages — the prompt is built here. StylePreview
        // borrows an anchor the same way and for the same reason.
        $anchor = $story->scenes()->orderBy('sequence')->first();

        if ($anchor === null) {
            $this->error("Story {$story->slug} has no scenes to anchor the call on.");

            return self::FAILURE;
        }

        $rate = (float) config('providers.fal.usd_per_image');
        $total = count(self::EXPRESSION_RUNGS) + 1;

        $built = [];

        foreach (self::EXPRESSION_RUNGS as $rung => $spec) {
            $frame = trim(sprintf(self::EXPRESSION_BASE_FRAME, $lead->name.'\'s face')
                .' '.$spec['clause']);

            $built[$rung] = [
                'why' => $spec['why'],
                'clause' => $spec['clause'],
                'referenced' => true,
                'prompt' => $prompts->build($frame, [$lead], [$lead->name]),
            ];
        }

        $control = self::EXPRESSION_CONTROL_RUNG;
        $built[$control.'_unreferenced'] = [
            'why' => 'the same rung with no reference attached — the drag control',
            'clause' => self::EXPRESSION_RUNGS[$control]['clause'],
            'referenced' => false,
            'prompt' => $built[$control]['prompt'],
        ];

        $this->line('');
        $this->info('Expression axis — does asking a referenced face for an expression cost the face?');
        $this->line('  story        '.$story->slug);
        $this->line('  character    '.$lead->name.' ('.$lead->scenes()->count().' scenes)');
        $this->line('  reference    '.$lead->reference_image_path.'  ['.$lead->referenceStyleState().']');
        $this->line('  fingerprint  '.StyleFingerprint::current());
        $this->line('  edit model   '.config('providers.fal.edit_model'));
        $this->line('  t2i model    '.config('providers.fal.text_to_image_model').'  (control only)');
        $this->line('');

        foreach ($built as $rung => $spec) {
            $this->line(sprintf('  %-22s %s', $rung, $spec['why']));
        }

        $this->line('');
        $this->line(sprintf('  %d images at $%.4f = $%.4f', $total, $rate, $total * $rate));
        $this->warn('  Evaluation spend: logged in full, and deliberately not part of this story total.');
        $this->warn('  Nothing is written back — no reference is generated or replaced.');
        $this->line('');

        if ($this->option('dry-run')) {
            foreach ($built as $rung => $spec) {
                $this->line("  -- {$rung}");
                $this->line('     '.ImagePromptBuilder::frameFrom($spec['prompt']));
            }

            $this->line('');
            $this->info('Dry run — nothing generated, nothing billed.');

            return self::SUCCESS;
        }

        if (! $this->option('yes') && ! $this->confirm(sprintf('Spend $%.4f on fal.ai?', $total * $rate), false)) {
            $this->line('Nothing generated.');

            return self::FAILURE;
        }

        $runDir = 'expression-axis/'.now()->format('Ymd-His');
        $disk = Storage::disk('characters');
        $spent = 0.0;

        $reference = CharacterReferenceImage::forCharacter(
            $lead,
            $lead->referenceBytes(),
            $this->mimeOf((string) $lead->reference_image_path),
        );

        $manifest = [
            'axis' => 'expression',
            'story' => $story->slug,
            'character' => $lead->name,
            'description' => $lead->description,
            // The field that makes this sheet worth keeping. A later retune can
            // only be compared against a measurement whose look is known, and
            // "the style changed" is otherwise indistinguishable from "the
            // expression rendered differently". Same reason
            // `character_references` carries one.
            'style_fingerprint' => StyleFingerprint::current(),
            'reference' => $lead->reference_image_path,
            'reference_style_state' => $lead->referenceStyleState(),
            'edit_model' => (string) config('providers.fal.edit_model'),
            'text_to_image_model' => (string) config('providers.fal.text_to_image_model'),
            'base_frame' => sprintf(self::EXPRESSION_BASE_FRAME, $lead->name.'\'s face'),
            'control_caveat' => 'The unreferenced control necessarily runs on the text-to-image '
                .'endpoint, because the edit endpoint requires an input image. It cannot separate '
                .'"the reference drags on affect" from "the two endpoints differ".',
            'rungs' => [],
        ];

        $panels = [['label' => 'REFERENCE', 'file' => $disk->path((string) $lead->reference_image_path)]];

        foreach ($built as $rung => $spec) {
            $this->line("  {$rung}...");

            try {
                $image = $fal->generate(
                    $anchor,
                    $spec['prompt'],
                    references: $spec['referenced'] ? [$reference] : [],
                );
            } catch (Throwable $e) {
                $this->error('    '.$e->getMessage());

                continue;
            }

            $costs->handle($story, $this->tag($image->usage, "bakeoff_expression_{$rung}"));
            $spent += $image->usage->usdCost;

            $path = "{$runDir}/{$rung}.".$this->extension($image->mimeType);
            $disk->put($path, $image->bytes);

            $this->line(sprintf('    %dx%d  $%.4f  %s', $image->width, $image->height, $image->usage->usdCost, $path));

            $manifest['rungs'][$rung] = [
                'why' => $spec['why'],
                'clause' => $spec['clause'],
                'referenced' => $spec['referenced'],
                'path' => $path,
                'frame' => ImagePromptBuilder::frameFrom($spec['prompt']),
            ];

            $panels[] = [
                'label' => strtoupper(str_replace('_', ' ', $rung)).($spec['referenced'] ? '' : '  [NO REF]'),
                'file' => $disk->path($path),
            ];
        }

        $disk->put(
            "{$runDir}/manifest.json",
            (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );

        $sheet = $this->composeContactSheet($panels, $disk->path("{$runDir}/contact-sheet.jpg"));

        $this->line('');
        $this->info(sprintf('Spent $%.4f. Output in %s', $spent, $disk->path($runDir)));

        if ($sheet) {
            $this->line('  Contact sheet: contact-sheet.jpg — the reference first, then the ladder.');
        }

        $this->line('  Judging it is yours: the question is at which rung the face stops being');
        $this->line('  the same person, and no check in this stack can answer that.');

        return self::SUCCESS;
    }

    /**
     * The character whose approved face is being measured.
     *
     * Refuses rather than generating one. A run that quietly bought a reference
     * would be measuring a face nobody picked, and would answer a different
     * question from the one asked — the subject here is specifically the sheet
     * that is already conditioning this story's stills.
     */
    private function expressionLead(Story $story): ?Character
    {
        $ranked = $story->characters()->withCount('scenes')
            ->orderByDesc('scenes_count')->orderBy('name')->get();

        $name = trim((string) $this->option('character'));

        $lead = $name !== ''
            ? $ranked->first(fn (Character $c): bool => strcasecmp($c->name, $name) === 0
                || str_starts_with(mb_strtolower($c->name), mb_strtolower($name)))
            : $ranked->first(fn (Character $c): bool => $c->hasUsableReference());

        if ($lead === null) {
            $this->error($name !== ''
                ? "No character matching '{$name}' in {$story->slug}."
                : "No character in {$story->slug} has a reference sheet on disk. This axis measures "
                    .'the approved face and will not generate one — pick a story whose cast has been '
                    .'through Gate 2.');

            return null;
        }

        if (! $lead->hasUsableReference()) {
            $this->error("{$lead->name} has no reference sheet on disk. This axis measures the "
                .'approved face and deliberately does not generate one.');

            return null;
        }

        return $lead;
    }

    /**
     * The mime the bytes actually are, from the name they were filed under.
     *
     * `characters:verify` exists because Seedream answers a PNG-shaped request
     * with a JPEG, and a `.png` full of JPEG misdescribes itself to the very
     * call it is meant to condition. Passing a hardcoded 'image/png' here would
     * reintroduce that on the one call this run depends on.
     */
    private function mimeOf(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'image/png',
        };
    }

    /**
     * One image holding the reference and every rung, in order.
     *
     * The point of the run is a comparison, and a comparison spread across seven
     * files is one made from memory. GD rather than FFmpeg because the labels
     * matter and `drawtext` needs a font file this machine cannot be assumed to
     * have; `imagestring` carries its own bitmap font and cannot fail that way.
     *
     * Best-effort. The individual files and the manifest are the record; if this
     * cannot be composed the measurement is still intact, so it reports and does
     * not fail the run.
     *
     * @param  array<int, array{label: string, file: string}>  $panels
     */
    private function composeContactSheet(array $panels, string $outputPath): bool
    {
        if ($panels === [] || ! function_exists('imagecreatetruecolor')) {
            return false;
        }

        $cellW = 640;
        $cellH = 360;
        $label = 22;
        $cols = 4;
        $rows = (int) ceil(count($panels) / $cols);

        $sheet = imagecreatetruecolor($cols * $cellW, $rows * ($cellH + $label));

        if ($sheet === false) {
            return false;
        }

        $ink = imagecolorallocate($sheet, 235, 235, 235);
        imagefill($sheet, 0, 0, imagecolorallocate($sheet, 18, 18, 22));

        foreach ($panels as $i => $panel) {
            if (! is_file($panel['file'])) {
                continue;
            }

            $source = @imagecreatefromstring((string) file_get_contents($panel['file']));

            if ($source === false) {
                continue;
            }

            $x = ($i % $cols) * $cellW;
            $y = intdiv($i, $cols) * ($cellH + $label);

            // Letterboxed rather than stretched. A reference is square and a
            // still is 16:9, and stretching one to the other changes the shape
            // of the face being compared.
            $scale = min($cellW / imagesx($source), $cellH / imagesy($source));
            $w = (int) (imagesx($source) * $scale);
            $h = (int) (imagesy($source) * $scale);

            imagecopyresampled(
                $sheet, $source,
                $x + intdiv($cellW - $w, 2), $y + $label + intdiv($cellH - $h, 2),
                0, 0, $w, $h, imagesx($source), imagesy($source),
            );

            imagestring($sheet, 4, $x + 6, $y + 4, $panel['label'], $ink);
            imagedestroy($source);
        }

        $ok = imagejpeg($sheet, $outputPath, 88);
        imagedestroy($sheet);

        return $ok;
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
