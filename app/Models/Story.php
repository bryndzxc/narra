<?php

namespace App\Models;

use App\Enums\CostCategory;
use App\Enums\Gate;
use App\Enums\StoryFormat;
use App\Enums\StoryStatus;
use App\Exceptions\GateViolationException;
use Database\Factories\StoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * One video, from premise to publish sheet.
 *
 * The gate logic below is the reason this model is not anaemic. The four human
 * gates are the product's central promise and the line below Gate 2 is a money
 * invariant, so neither can be a UI concern: a Livewire component that enforces
 * them is a rule a queued job or an Artisan command walks straight past. Every
 * caller goes through transitionTo() or approveGate(), and anything that spends
 * money calls assertPaidAssetsUnlocked() first.
 *
 * @property StoryStatus $status
 * @property StoryFormat $format
 */
class Story extends Model
{
    /** @use HasFactory<StoryFactory> */
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'premise',
        // Optional operator text naming the intended age range of the cast, read
        // by the character extraction prompt. It exists so the age range is
        // STATED where it varies — per story — rather than compensated for by
        // the one art-style line that every story shares. See the migration.
        'cast_age_profile',
        // The first thirty seconds. Five beats, the last of which promises the
        // DEPARTURE rather than revenge or exposure — checked at Gate 1 by
        // overlap, the way `refusal` is. Added last and for a measured reason:
        // four of its five beats already existed in both shipped stories and
        // every one of them landed two to seven minutes late, because the act 1
        // call had no instruction about where the opening starts and a writer
        // with none writes the chronological beginning. See the migration.
        'hook',
        // The genre spine. Written by the outline generator, edited by the
        // operator at Gate 1, and read by every act-generation call after it.
        // See the migration that added them for why each one is load-bearing.
        'narrator_grievance',
        'antagonist_justification',
        'withheld_information',
        'exposure_moment',
        // The reversal half of the spine. The first four say how the narrator
        // is wronged and where it comes out; these three say that they leave,
        // that they are searched for, and what they say when they are found.
        // Story 21 had none of them and paid its reversal off in one scene.
        'departure',
        'reversal_beats',
        'refusal',
        'format',
        'locale_profile',
        'voice_id',
        'target_duration_min',
        'target_duration_max',
        'target_publish_at',
    ];

    /**
     * `status` is deliberately absent from $fillable. It is not an attribute to
     * be assigned; it is a state machine with four operator gates in it, and
     * every move belongs in transitionTo() or approveGate().
     *
     * `total_cost_usd` too — it is maintained from cost_entries, not set.
     *
     * `sized_against_wpm` too, and for the reason `locale_profile` is frozen:
     * the act scripts are generated against it, so a value that could be
     * reassigned would describe a script that no longer exists. It is written
     * once by `ScriptSizing::freezeFor()` and never again — see that method,
     * and the migration that added the column.
     *
     * `locale_guidance_fingerprint` is the third of the same kind, and the
     * argument transfers exactly: the outline, the acts, the cast and the
     * scenes are all generated against the locale guidance, so a digest that
     * could be reassigned would describe prose that no longer exists. Written
     * once by `LocaleGuard::freezeFingerprintFor()` at the outline, which is
     * where the names and the setting are actually decided.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StoryStatus::class,
            'reopened_from' => StoryStatus::class,
            'format' => StoryFormat::class,
            'target_publish_at' => 'datetime',
            'is_fixture' => 'boolean',
            'total_cost_usd' => 'decimal:4',
            'target_duration_min' => 'integer',
            'target_duration_max' => 'integer',
            'sized_against_wpm' => 'integer',
            'locale_guidance_fingerprint' => 'string',
        ];
    }

    /**
     * The runtime window a story gets when nobody names one.
     *
     * READ FROM THE COLUMN, not written out here. `CreateStory` deliberately
     * omits these keys so the schema answers — "a second copy of those numbers
     * in PHP is exactly the drift CLAUDE.md warns about with the
     * words-per-minute constant" — and the new-story form needs the same answer
     * before a row exists to ask. Writing `[30, 40]` in a form estimate would be
     * that second copy, in the one place that has already been wrong twice
     * about a default it kept its own version of.
     *
     * Resolved once per request. It is a schema read, not a row read.
     *
     * @return array{min: int, max: int}
     */
    public static function defaultDurationWindow(): array
    {
        static $window = null;

        if ($window === null) {
            $defaults = [];

            foreach (Schema::getColumns('stories') as $column) {
                if (in_array($column['name'], ['target_duration_min', 'target_duration_max'], true)) {
                    $defaults[$column['name']] = (int) trim((string) $column['default'], "'");
                }
            }

            $window = [
                'min' => $defaults['target_duration_min'] ?? 30,
                'max' => $defaults['target_duration_max'] ?? 40,
            ];
        }

        return $window;
    }

    /**
     * A filesystem-safe workspace name for a title.
     *
     * Capped hard, and not only because the column is varchar(64). Render
     * output lives at `renders/<slug>/clips/scene-nnn.mp4`, Windows caps a path
     * at 260 characters unless long paths are enabled, and a title is arbitrary
     * operator text — em dashes, quotes, colons, and as long as they like.
     * Slugging once at creation is what keeps 200 scene files addressable.
     */
    public static function slugFor(string $title, ?string $suffix = null): string
    {
        $slug = Str::slug($title) ?: 'story';
        $suffix = $suffix === null ? '' : '-'.trim($suffix, '-');

        return Str::limit($slug, 63 - strlen($suffix), '').$suffix;
    }

    // -- Relations ----------------------------------------------------------

    /** @return HasMany<Act, $this> */
    public function acts(): HasMany
    {
        return $this->hasMany(Act::class)->orderBy('sequence');
    }

    /** @return HasMany<Scene, $this> */
    public function scenes(): HasMany
    {
        return $this->hasMany(Scene::class)->orderBy('sequence');
    }

    /** @return HasMany<Character, $this> */
    public function characters(): HasMany
    {
        return $this->hasMany(Character::class);
    }

    /** @return HasMany<AudioTrack, $this> */
    public function audioTracks(): HasMany
    {
        return $this->hasMany(AudioTrack::class);
    }

    /** @return HasManyThrough<SceneAudio, Scene, $this> */
    public function sceneAudio(): HasManyThrough
    {
        return $this->hasManyThrough(SceneAudio::class, Scene::class);
    }

    /** @return HasMany<RenderJob, $this> */
    public function renderJobs(): HasMany
    {
        return $this->hasMany(RenderJob::class);
    }

    /** @return HasMany<CostEntry, $this> */
    public function costEntries(): HasMany
    {
        return $this->hasMany(CostEntry::class);
    }

    /** @return HasOne<YoutubeMetadata, $this> */
    public function youtubeMetadata(): HasOne
    {
        return $this->hasOne(YoutubeMetadata::class);
    }

    // -- Gates and transitions ----------------------------------------------

    public function canTransitionTo(StoryStatus $status): bool
    {
        return $this->status->canTransitionTo($status);
    }

    /**
     * Move the story, for any move that is not a gate crossing.
     *
     * Refuses gate crossings outright rather than performing them quietly. A
     * gate is an operator decision; if a job could reach it through the same
     * method as every other transition, one day one will.
     *
     * @throws GateViolationException
     */
    public function transitionTo(StoryStatus $status): static
    {
        if (! $this->canTransitionTo($status)) {
            throw GateViolationException::illegalTransition($this->status, $status);
        }

        $gate = $this->status->gateFor($status);

        if ($gate !== null) {
            throw GateViolationException::gateNotApproved($this->status, $status, $gate);
        }

        $this->forceFill(['status' => $status])->save();

        return $this;
    }

    /**
     * Cross a gate. The one path through, and it is explicit by design.
     *
     * @throws GateViolationException
     */
    public function approveGate(Gate $gate): static
    {
        if ($this->status !== $gate->waitsAt()) {
            throw GateViolationException::notWaitingAtGate($gate, $this->status);
        }

        $this->forceFill(['status' => $gate->opensTo()])->save();

        return $this;
    }

    /**
     * Reopen Gate 2 so the operator can fix a scene.
     *
     * A move of its own rather than a transitionTo() call, because the caller
     * that asks for it does not know which statuses it is legal from, and the
     * page that offers it was previously deciding that for itself. That is the
     * bug this method closes: the button was shown whenever scenes were locked,
     * which is six statuses, and only one of them had the transition.
     *
     * It destroys nothing. Reopening is entered speculatively — "let me look at
     * scene 147" — and an operator who changes their mind should not have paid
     * for the look. What has actually gone stale is computed and applied when
     * the gate is approved again, where it can be shown before it happens. See
     * SceneChangeSet and ApproveScenesGate.
     *
     * @throws GateViolationException
     */
    public function reopenScenesGate(): static
    {
        if (! $this->status->canReopenScenesGate()) {
            throw GateViolationException::cannotReopenScenesGate($this->status);
        }

        $this->forceFill([
            // Kept so the re-approval can name what it is about to discard.
            // "This will discard the finished 37-minute render" is a different
            // sentence from "this will discard the images you generated", and
            // the operator deserves whichever one is true.
            'reopened_from' => $this->status,
            'status' => StoryStatus::ScenesDrafted,
        ])->save();

        // The one thing a reopen does touch, and it marks rather than deletes.
        // Chapters are derived from act timings, act timings come from the
        // render, and the render is now invalid — so every timestamp in a
        // drafted publish sheet is wrong. The sheet stays on screen and stays
        // copyable in the meantime, which is why leaving it unmarked is not an
        // option: Gate 4's consequences land on YouTube, outside this app.
        $this->youtubeMetadata?->markStale();

        return $this;
    }

    /**
     * A digest of the scene list as it stands: ordered, with sequence numbers.
     *
     * Answers only whole-video questions — whether the concatenated render
     * still matches the scene list, and whether the clips are still filed under
     * the right numbers. That second one is easy to miss: clips are named
     * `scene-%03d` from `sequence`, so swapping scenes 2 and 3 leaves each
     * clip's contents under the other's filename. Nothing here is ever a reason
     * to re-bill a provider.
     */
    public function sceneDigest(): string
    {
        $pairs = $this->scenes()
            ->reorder('sequence')
            ->pluck('sequence', 'id')
            ->map(fn (int $sequence, int $id): string => "{$id}:{$sequence}")
            ->values()
            ->implode(',');

        return hash('sha256', $pairs);
    }

    /**
     * A story kept to be measured against rather than published.
     *
     * ---------------------------------------------------------------------
     * WHY THIS EXISTS, AND WHY IT IS A COLUMN RATHER THAN A NAME CHECK
     * ---------------------------------------------------------------------
     *
     * Three of these sit on this machine and every operator surface was
     * treating them as outstanding work: the Phase 0 render fixture is parked
     * at `rendered`, which made it a permanent resident of "Waiting on you" —
     * the one section of the dashboard that is supposed to be the only
     * actionable thing on it — and the two style-preview casts sat forever in
     * "Not moving", a section whose entire meaning is "this should be moving
     * and is not".
     *
     * **A section that always contains something it should not teaches you to
     * skim it, and you skim it right past the day something real lands
     * there.** That is the same failure as an alarm that is always on: not a
     * wrong number, a true one that has stopped being read.
     *
     * A column rather than a slug prefix or a title match, because those are
     * guesses about intent that a rename silently breaks — and a fixture that
     * quietly became ordinary work again would reintroduce exactly the noise
     * this removes, with nothing to notice it by.
     *
     * It hides a story from the sections that mean "do something about this".
     * It does NOT hide the story: it still appears on the index, still has its
     * own page, still carries its costs into every total, and that page says
     * plainly what it is and why it never advances.
     */
    public function isFixture(): bool
    {
        return (bool) $this->is_fixture;
    }

    /**
     * Stories that represent outstanding work.
     *
     * @param  Builder<Story>  $query
     * @return Builder<Story>
     */
    public function scopeRealWork(Builder $query): Builder
    {
        return $query->where('is_fixture', false);
    }

    /** The gate currently waiting on the operator, if the story is parked at one. */
    public function awaitingGate(): ?Gate
    {
        return $this->status->awaitingGate();
    }

    public function hasPassedGate(Gate $gate): bool
    {
        return $this->status->rank() >= $gate->opensTo()->rank();
    }

    // -- The money line ------------------------------------------------------

    /**
     * Whether this story may generate paid assets yet.
     *
     * False until Gate 2 is passed. Nothing about images, TTS or transcription
     * may run before then.
     */
    public function canGeneratePaidAssets(): bool
    {
        return $this->status->allowsPaidAssets();
    }

    /**
     * Guard for anything that will bill.
     *
     * Every paid provider call in Phase 2 goes through this first, and so does
     * every cost_entries insert — see CostEntry, which refuses to record a cost
     * against a story that was not allowed to incur one. That makes "no paid
     * asset generation before scenes_approved" a property of the data rather
     * than a note in a job class.
     *
     * @throws GateViolationException
     */
    public function assertPaidAssetsUnlocked(?string $operation = null): void
    {
        if (! $this->canGeneratePaidAssets()) {
            throw GateViolationException::paidAssetsLocked($this->status, $operation);
        }
    }

    /**
     * Whether the cast's reference sheets may be generated yet.
     *
     * Unlocked at `scenes_drafted` — the operator is standing at Gate 2 — not
     * at `scenes_approved`. See StoryStatus::allowsReferenceSpend().
     */
    public function canGenerateReferences(): bool
    {
        return $this->status->allowsReferenceSpend();
    }

    /** @throws GateViolationException */
    public function assertReferenceSpendUnlocked(?string $operation = null): void
    {
        if (! $this->canGenerateReferences()) {
            throw GateViolationException::referenceSpendLocked($this->status, $operation);
        }
    }

    /**
     * Spend billed against this story that is not part of this video.
     *
     * The counterpart to `total_cost_usd` rather than a slice of it: evaluation
     * rows are deliberately kept out of that column, so without this they would
     * be on record and nowhere on screen — money spent, logged, and invisible,
     * which is the shape this project has repeatedly found reads as "fine".
     *
     * It is money that was really spent; it just belongs to the channel rather
     * than to the video whose cast a style preview or a bake-off borrowed.
     * Shown beside the total wherever the total is shown, and only when it is
     * non-zero, so an ordinary story says nothing extra.
     */
    public function evaluationSpend(): float
    {
        return (float) $this->costEntries()
            ->where('category', CostCategory::Evaluation)
            ->sum('usd_cost');
    }

    // -- Publishing ----------------------------------------------------------

    /**
     * The target publish time in US Eastern — the timezone the schedule is
     * actually reasoned about in, since peak viewing is 6-10 PM ET.
     */
    public function targetPublishAtEastern(): ?Carbon
    {
        return $this->target_publish_at?->copy()->setTimezone('America/New_York');
    }

    /**
     * The same instant in Manila, where the operator is.
     *
     * That US evening window lands in the early hours here, which is exactly
     * how a publish time gets fumbled — so both are shown, never just one.
     */
    public function targetPublishAtManila(): ?Carbon
    {
        return $this->target_publish_at?->copy()->setTimezone('Asia/Manila');
    }
}
