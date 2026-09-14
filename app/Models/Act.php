<?php

namespace App\Models;

use App\Enums\ActPhase;
use App\Enums\ActTimeframe;
use App\Support\TextBounds;
use App\Support\YoutubeTimestamp;
use Database\Factories\ActFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One act: a unit of script generation and a YouTube chapter, at once.
 *
 * `summary` is not decoration. Acts are generated sequentially, each call fed
 * the outline plus the summaries of the acts before it — that is the mechanism
 * that keeps 7,000 words from drifting, repeating or contradicting themselves.
 *
 * `phase` is what the act generator writes AGAINST. Without it the prompt could
 * only ask "is this the last act", and answered every other act with "end worse
 * off than it started" — correct for an escalation act, and the exact opposite
 * of what a search act needs.
 *
 * @property ?ActPhase $phase
 * @property ?ActTimeframe $timeframe
 */
class Act extends Model
{
    /** @use HasFactory<ActFactory> */
    use HasFactory;

    /**
     * How long each text field on an act may be, in characters — ONE answer,
     * read by everything that has an opinion about it.
     *
     * Three things write these fields and one thing validates them: the
     * outline call, the act-script call (which REPLACES `summary` with the
     * writer's own account of what it wrote — GenerateActScripts), the operator
     * at Gate 1, and Gate 1's save() rule. Until this existed the rule carried
     * a literal 2,000 for the summary, sized — by feel, not by measurement —
     * for the outline writer's "3-5 sentences", and nobody had asked what the
     * act writer's "3-5 sentences" came back as. Measured on 61 acts:
     *
     *   act-writer summaries   p50 1,459   p90 1,815   p99 1,968   max 2,026
     *   outline summaries      p50   970   p90 1,057   max 1,179
     *
     * So 2,000 sat inside the act writer's tail, and story 28's act 4 came back
     * at 2,026 in exactly five sentences — the writer did what it was asked —
     * and Gate 1 could not be approved. Two other stories had shipped at 1,968
     * and 1,933: within 2% of the same wall.
     *
     * 3,000 is derived, not picked to fit: ~1.5x the observed maximum and
     * ~1.6x the p99, so the writer's natural distribution never approaches it,
     * and under two thirds of the SHORTEST act script on record (4,517 chars),
     * so a summary cannot quietly become a second script. A bound the writer
     * naturally clears is the point — this is the running context that keeps
     * 7,000 words coherent, and a summary cut short to fit a form costs
     * coherence to save a textarea. If a writer ever exceeds it, the act call
     * refuses loudly (GenerateActScripts) and that refusal is new information
     * about the writer, not a reason to move this number.
     *
     * NOT enforced at the JSON schema. Structured outputs do not honour
     * `maxLength` (the SDKs strip it and validate client-side), and the schema
     * already declines `minItems` for the same reason — see the comment beside
     * `acts` in ClaudeScriptWriter::outlineSchema(). The prompt states the
     * bound and the Action enforces it against the decoded response, after
     * the cost row is written.
     *
     * The title bound is YouTube's: an act title doubles as a chapter title.
     */
    public const TITLE_MAX_CHARS = 100;

    public const SUMMARY_MAX_CHARS = 3000;

    public const ESCALATION_BEAT_MAX_CHARS = 1000;

    /**
     * The three bounds keyed by column, for anything that walks the fields.
     *
     * @return array<string, int>
     */
    public static function textBounds(): array
    {
        return [
            'title' => self::TITLE_MAX_CHARS,
            'summary' => self::SUMMARY_MAX_CHARS,
            'escalation_beat' => self::ESCALATION_BEAT_MAX_CHARS,
        ];
    }

    /**
     * Which of the given fields are over their bound, as sentences.
     *
     * Multibyte-aware, the way the form rule is (`max:` on a string counts
     * characters, not bytes), so the two cannot disagree on a summary full of
     * curly quotes and em dashes.
     *
     * @param  array<string, string>  $fields  column => text
     * @return array<string, string>  column => problem, empty when all fit
     */
    public static function textOverflows(array $fields): array
    {
        return TextBounds::overflows(self::textBounds(), $fields);
    }

    protected $fillable = [
        'story_id',
        'sequence',
        // Which of the four phases this act belongs to. Null on an anthology,
        // where each act runs the whole arc internally. See ActPhase.
        'phase',
        // Whether this act is set in the story's present or before it. A
        // declaration by the outline writer, refused at Gate 1 when it says
        // `prior` on an escalation act: story 28 spent two of three escalation
        // acts staging 2015 and 2017, and the present-day betrayal landed at
        // 20:18. Null is UNKNOWN — every act outlined before the question was
        // asked. See ActTimeframe.
        'timeframe',
        'title',
        'summary',
        // What this act makes worse. Each act compounds; none resolves before
        // the exposure.
        'escalation_beat',
        'script',
        'is_rehook_written',
        'start_ms',
        'duration_ms',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'phase' => ActPhase::class,
            'timeframe' => ActTimeframe::class,
            'is_rehook_written' => 'boolean',
            'start_ms' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    /** @return BelongsTo<Story, $this> */
    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    /** @return HasMany<Scene, $this> */
    public function scenes(): HasMany
    {
        return $this->hasMany(Scene::class)->orderBy('sequence');
    }

    /**
     * The chapters this act was returned as, in order.
     *
     * Empty on an act written before chapters existed. The act is the unit
     * the script is written in; the chapter is the unit the video is watched
     * in — see config/chapters.php for the measurement that split them.
     *
     * @return HasMany<Chapter, $this>
     */
    public function chapters(): HasMany
    {
        return $this->hasMany(Chapter::class)->orderBy('sequence');
    }

    /**
     * This act's chapter start as a YouTube timestamp.
     *
     * Null until the render has filled in start_ms — chapters cannot exist
     * before the video does, which is why metadata generation runs after the
     * render rather than alongside the script.
     *
     * Still used for a story outlined before chapters existed, whose acts
     * ARE its chapters. A story with chapter rows derives its list from them.
     */
    public function chapterTimestamp(): ?string
    {
        return $this->start_ms === null ? null : YoutubeTimestamp::format((int) $this->start_ms);
    }
}
