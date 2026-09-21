<?php

namespace App\Console\Commands;

use App\Enums\StoryStatus;
use App\Models\Act;
use App\Models\Story;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Copy a story's outline into a fresh story, leaving the scripts behind.
 *
 * Built to make a model comparison honest, and the design follows from that.
 *
 * Comparing two act scripts written against two DIFFERENT outlines measures
 * nothing: the outline decides the grievance, the antagonist's justification,
 * what is withheld and where it is exposed, so a second outline is a second
 * story and any prose difference is mostly that. The only way to see what a
 * cheaper model does to the ACT is to hold the outline fixed and vary one
 * thing.
 *
 * So this copies the premise, the genre spine and every act's title, summary
 * and escalation beat — everything Gate 1 approved — and copies no script, no
 * cast and no scenes. The fork lands at `outlined`, which is exactly where
 * `story:write` picks up, and re-running the outline is neither needed nor
 * wanted: it would cost money to introduce the variable being controlled for.
 *
 * Useful beyond the comparison: it is also how you retry a story's acts without
 * destroying the version you have, which the pipeline otherwise has no way to
 * do — `story:write --acts-only` overwrites in place.
 */
class StoryFork extends Command
{
    protected $signature = 'story:fork
        {story : Story slug or id to copy the outline from.}
        {--title= : Working title for the fork. Defaults to the original plus a suffix.}
        {--suffix=fork : Slug suffix when no title is given.}';

    protected $description = 'Copy a story outline into a new story, without its scripts, for an A/B run.';

    public function handle(): int
    {
        $key = (string) $this->argument('story');

        $source = Story::query()
            ->where('slug', $key)
            ->orWhere('id', ctype_digit($key) ? (int) $key : 0)
            ->first();

        if ($source === null) {
            $this->error("No story matching '{$key}'.");

            return self::FAILURE;
        }

        $acts = $source->acts()->orderBy('sequence')->get();

        if ($acts->isEmpty()) {
            $this->error(
                "'{$source->slug}' has no acts to copy. A fork exists to hold an outline fixed while "
                .'something else varies; there is no outline here to hold.'
            );

            return self::FAILURE;
        }

        $fork = DB::transaction(function () use ($source, $acts): Story {
            $title = (string) ($this->option('title') ?: $source->title.' ('.$this->option('suffix').')');

            $fork = Story::create([
                'title' => $title,
                // Story::slugFor, which caps at the column's 64. The hand-built
                // version here limited the TITLE to 60 and then added "-xxxx",
                // so a title near 60 characters made a 65-character slug and
                // the fork died on a truncation error — found 2026-09-19 by a
                // random factory title, two runs in six.
                'slug' => Story::slugFor($title, Str::lower(Str::random(4))),
                'premise' => $source->premise,

                // The cast is part of the outline: the spine and the act
                // summaries name exactly these people.
                'outline_cast' => $source->outline_cast,

                // The genre spine travels with the outline. These seven fields
                // are what every act call is written against, so a fork that
                // dropped them would not be running the same experiment.
                'narrator_grievance' => $source->narrator_grievance,
                'antagonist_justification' => $source->antagonist_justification,
                'accomplice_motive' => $source->accomplice_motive,
                'accomplice_performance' => $source->accomplice_performance,
                'accomplice_fall' => $source->accomplice_fall,
                'running_thought' => $source->running_thought,
                'betrayal_scene' => $source->betrayal_scene,
                'withheld_information' => $source->withheld_information,
                'exposure_moment' => $source->exposure_moment,
                'narrator_at_exposure' => $source->narrator_at_exposure,
                'hook' => $source->hook,
                'departure' => $source->departure,
                'reversal_beats' => $source->reversal_beats,
                'refusal' => $source->refusal,
                'antagonist_regret' => $source->antagonist_regret,
                // The ending the source was outlined for. A fork that keeps
                // the outline must keep what it was written to; one that
                // re-outlines can change it at Gate 1 while it is a draft.
                'ending' => $source->ending,
                // And what the partner is by the END. The same argument: a
                // fork that keeps the outline must keep what it was written
                // to, and a fork that re-outlines can change it at Gate 1.
                'partner_end_state' => $source->partner_end_state,

                'format' => $source->format,
                'locale_profile' => $source->locale_profile,
                'voice_id' => $source->voice_id,
                'target_duration_min' => $source->target_duration_min,
                'target_duration_max' => $source->target_duration_max,
            ]);

            // Out of $fillable, so it cannot ride in the array above — and a
            // key `create()` silently drops is a copy that reads as made. The
            // picked premise's own spine answers travel with the outline for
            // the reason the spine columns do: a fork that re-outlines is
            // written from the same answers the source was, which is what this
            // command exists for. See the migration that added it.
            $fork->forceFill(['premise_spine' => $source->premise_spine])->save();

            foreach ($acts as $act) {
                Act::create([
                    'story_id' => $fork->id,
                    'sequence' => $act->sequence,
                    'phase' => $act->phase,
                    'timeframe' => $act->timeframe,
                    'title' => $act->title,
                    'summary' => $act->summary,
                    'escalation_beat' => $act->escalation_beat,
                    // Deliberately not copied: `script`, and with it
                    // `is_rehook_written` and the act's chapters. The script
                    // is the thing being regenerated, and the chapters are the
                    // writer's cut of it; copying either would produce a fork
                    // that looks finished and silently compares a model
                    // against itself.
                    'script' => null,
                    'is_rehook_written' => false,
                ]);
            }

            // Refreshed before the move, because `status` is deliberately not
            // mass-assignable — it is a state machine with four gates in it, not
            // an attribute — so the freshly created model carries no status at
            // all until the database default is read back.
            // The outline travels, so the fact about WHEN it was written
            // travels with it: a fork of a story outlined before the betrayal
            // scene was asked is that same unasked outline, and Gate 1 should
            // say so once rather than call the field missing. Not fillable,
            // hence forced.
            $fork->forceFill([
                'outlined_before_betrayal_scene' => (bool) $source->outlined_before_betrayal_scene,
                'outlined_before_cast' => (bool) $source->outlined_before_cast,
                'outlined_before_accomplice_and_thought' => (bool) $source->outlined_before_accomplice_and_thought,
                'outlined_before_antagonist_regret' => (bool) $source->outlined_before_antagonist_regret,
            ])->save();

            $fork->refresh();

            // Straight to `outlined` rather than through the Gate 1 approval:
            // the gate was already crossed on the source and a fork is not a
            // second editorial decision. It is the same decision, copied.
            $fork->transitionTo(StoryStatus::Outlined);

            return $fork;
        });

        $this->info("Forked '{$source->slug}' -> '{$fork->slug}'");
        $this->line('  acts copied     '.$acts->count().' (outline only, no scripts)');
        $this->line('  status          '.$fork->fresh()->status->value);
        $this->line('  cost so far     $0.0000 — copying is free; nothing was generated.');
        $this->line('');
        $this->line("Write its acts with:  php artisan story:write {$fork->slug}");

        return self::SUCCESS;
    }
}
