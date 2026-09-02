<?php

namespace App\Console\Commands;

use App\Enums\AssetStatus;
use App\Models\Character;
use App\Models\CharacterReference;
use App\Models\Story;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Checks that every stored reference is the image the database says it is.
 *
 * Three things can drift between a `character_references` row and the bytes on
 * disk, and all three are silent until the expensive stage:
 *
 *  1. **The file is gone.** A row saying `ready` with no file behind it reads
 *     as done. `isUsable()` already asks this question per reference; this asks
 *     it for the whole story at once.
 *
 *  2. **The extension lies about the format.** The reference disk is written
 *     from whatever a provider returns, and Seedream answers a PNG-shaped
 *     request with a JPEG. `CharacterReference::mimeType()` derives the mime
 *     from the filename, and that mime goes back out to the image API inside a
 *     `data:` URI — so a `.png` full of JPEG misdescribes itself to the very
 *     call it exists to condition.
 *
 *  3. **The recorded dimensions are wrong.** fal declares `width` and `height`
 *     as null, so a provider that trusted the response stored zeroes.
 *
 * `--fix` repairs 2 and 3 by renaming the file to match its real format and
 * re-reading its real size. It cannot repair 1 — a missing image has to be
 * regenerated, which costs money and is therefore the operator's call.
 */
class VerifyCharacterReferences extends Command
{
    protected $signature = 'characters:verify
        {story? : Story slug or id. Omit to check every story.}
        {--fix : Rename mislabelled files and correct stored dimensions. Free — no generation.}';

    protected $description = 'Check stored character references against the bytes on disk.';

    public function handle(): int
    {
        $disk = Storage::disk((string) config('characters.disk', 'characters'));

        $query = CharacterReference::query()->with('character');

        if ($this->argument('story')) {
            $story = Story::query()
                ->where('slug', $key = (string) $this->argument('story'))
                ->orWhere('id', ctype_digit($key) ? (int) $key : 0)
                ->first();

            if ($story === null) {
                $this->error("No story matching '{$key}'.");

                return self::FAILURE;
            }

            $query->whereIn('character_id', Character::where('story_id', $story->id)->pluck('id'));
        }

        $missing = 0;
        $relabelled = 0;
        $resized = 0;
        $checked = 0;

        foreach ($query->get() as $reference) {
            if ($reference->status !== AssetStatus::Ready || $reference->image_path === null) {
                continue;
            }

            $checked++;

            if (! $disk->exists($reference->image_path)) {
                $missing++;
                $this->error(sprintf(
                    '  MISSING  %s b%02d-%02d  %s',
                    $reference->character?->name ?? '?',
                    $reference->batch,
                    $reference->sequence,
                    $reference->image_path,
                ));

                continue;
            }

            $bytes = $disk->get($reference->image_path);
            $probed = @getimagesizefromstring($bytes);

            if ($probed === false) {
                $missing++;
                $this->error(sprintf(
                    '  UNDECODABLE  %s  %s — FFmpeg does not fail on these, it loops forever.',
                    $reference->character?->name ?? '?',
                    $reference->image_path,
                ));

                continue;
            }

            $wanted = match ($probed['mime']) {
                'image/jpeg' => 'jpg',
                'image/webp' => 'webp',
                default => 'png',
            };

            $actual = strtolower(pathinfo($reference->image_path, PATHINFO_EXTENSION));

            if ($actual !== $wanted) {
                $relabelled++;
                $newPath = preg_replace('/\.[^.]+$/', '.'.$wanted, $reference->image_path);

                $this->warn(sprintf(
                    '  %s  %s is %s  (%s -> %s)',
                    $this->option('fix') ? 'RELABELLED' : 'MISLABELLED',
                    $reference->character?->name ?? '?',
                    $probed['mime'],
                    basename($reference->image_path),
                    basename((string) $newPath),
                ));

                if ($this->option('fix')) {
                    $disk->move($reference->image_path, (string) $newPath);

                    // The character's own pointer travels with it. Leaving it
                    // behind would set reference_image_path to a file that no
                    // longer exists, which reads as "picked" and resolves to
                    // nothing.
                    if ($reference->character?->reference_image_path === $reference->image_path) {
                        $reference->character->forceFill(['reference_image_path' => $newPath])->save();
                    }

                    $reference->forceFill(['image_path' => $newPath])->save();
                }
            }

            if ((int) $reference->width !== (int) $probed[0] || (int) $reference->height !== (int) $probed[1]) {
                $resized++;

                if ($this->option('fix')) {
                    $reference->forceFill(['width' => $probed[0], 'height' => $probed[1]])->save();
                } else {
                    $this->warn(sprintf(
                        '  WRONG SIZE  %s  stored %dx%d, actually %dx%d',
                        $reference->character?->name ?? '?',
                        (int) $reference->width,
                        (int) $reference->height,
                        $probed[0],
                        $probed[1],
                    ));
                }
            }
        }

        $this->line('');
        $this->info(sprintf(
            '%d reference(s) checked. %d mislabelled, %d with wrong dimensions, %d missing or undecodable.',
            $checked,
            $relabelled,
            $resized,
            $missing,
        ));

        if (! $this->option('fix') && ($relabelled > 0 || $resized > 0)) {
            $this->line('  Re-run with --fix to repair. Free — it renames files and re-reads sizes.');
        }

        if ($missing > 0) {
            $this->line('  Missing images cannot be repaired here; they have to be regenerated, and');
            $this->line('  that costs money. Regenerate that character\'s sheet at Gate 2.');
        }

        return $missing > 0 ? self::FAILURE : self::SUCCESS;
    }
}
