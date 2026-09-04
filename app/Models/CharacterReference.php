<?php

namespace App\Models;

use App\Enums\AssetStatus;
use Database\Factories\CharacterReferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One candidate face, kept whether or not it won.
 *
 * The rejects stay because they were billed, because re-picking one later must
 * be free, and because "nothing is regenerated silently" is only checkable if
 * the previous round is still there to compare against.
 *
 * @property AssetStatus $status
 */
class CharacterReference extends Model
{
    /** @use HasFactory<CharacterReferenceFactory> */
    use HasFactory;

    protected $fillable = [
        'character_id',
        'batch',
        'sequence',
        'prompt',
        'style_fingerprint',
        'seed',
        'provider',
        'model',
        'provider_reference',
        'image_path',
        'width',
        'height',
        'status',
        'usd_cost',
        'error',
        'selected_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'batch' => 'integer',
            'sequence' => 'integer',
            'seed' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'status' => AssetStatus::class,
            'usd_cost' => 'decimal:4',
            'selected_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Character, $this> */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function isSelected(): bool
    {
        return $this->selected_at !== null;
    }

    /** Whether there is actually an image behind this row. */
    public function isUsable(): bool
    {
        return $this->status === AssetStatus::Ready
            && $this->image_path !== null
            && $this->exists();
    }

    /**
     * Whether the bytes are still on disk.
     *
     * Asked rather than assumed. A row saying `ready` whose file has been
     * deleted is the shape of failure that would otherwise be discovered at
     * scene 90, halfway through the expensive stage, rather than at the gate.
     */
    public function exists(): bool
    {
        return $this->image_path !== null
            && Storage::disk($this->disk())->exists($this->image_path);
    }

    public function bytes(): string
    {
        return Storage::disk($this->disk())->get((string) $this->image_path);
    }

    /**
     * Derived from the stored filename rather than assumed.
     *
     * It returned 'image/png' unconditionally, which was fine while the only
     * producer was a fake writing PNGs. A real provider answering a PNG request
     * with a JPEG made it wrong in two places at once: the browser was served
     * the wrong Content-Type, and — worse — the bytes went back out to the
     * image API inside a `data:image/png;base64,` URI that misdescribed them.
     */
    public function mimeType(): string
    {
        return match (strtolower(pathinfo((string) $this->image_path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'image/png',
        };
    }

    private function disk(): string
    {
        return (string) config('characters.disk', 'characters');
    }
}
