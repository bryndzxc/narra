<?php

namespace App\Actions;

use App\Enums\PartnerEndState;
use App\Enums\StoryEnding;
use App\Enums\StoryFormat;
use App\Models\Story;
use App\Support\NarratorVoice;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A premise, and the row it becomes.
 *
 * Extracted from `story:write`, which held the only copy of this for the whole
 * of Phase 2 — so the only way to start a video was a terminal, and the app
 * that exists to keep an operator out of one had no front door.
 *
 * Nothing here is a default with an opinion, and `voice_id` is the one that has
 * now been wrong in both directions.
 *
 * It was `narrator-us-01`, hard-coded in this path for a phase — a string the
 * FAKE synthesizer invented to have something to record, on no vendor, carried
 * by every story written before it was removed. It was then null, on the
 * argument that a narrator should be a deliberate pick.
 *
 * Null is not neutral either, and story 23 is what showed it: the story reached
 * a paid asset dispatch with no narrator, and the missing column turned into 257
 * identical per-scene refusals after 256 stills had already been bought. A
 * channel keeps ONE narrator across every video — that is the spec's sentence —
 * so "no narrator" is not a resting state, it is a hole every new story falls
 * into once.
 *
 * So it still comes from config and this Action still has no opinion; what
 * changed is that the config now holds the channel's actual narrator. Null
 * remains legal and still refuses in `GenerateSceneNarration`, and is now also
 * refused at the dispatch by `PreflightAssetDispatch`, before anything queues.
 */
class CreateStory
{
    public function handle(
        string $premise,
        ?string $title = null,
        StoryFormat $format = StoryFormat::Single,
        ?int $targetMin = null,
        ?int $targetMax = null,
        ?string $castAgeProfile = null,
        ?string $localeProfile = null,
        // 'male' or 'female'. Resolves the voice from the channel's table
        // (NarratorVoice): one voice per narrator gender, chosen by who the
        // narrator is rather than by voice. Null keeps the default voice, for
        // callers that name no narrator; the form and story:write --premise
        // both require one.
        ?string $narrator = null,
        // Which ending the last chapter is. Chosen here, before the outline,
        // because the outline writes the fields it needs; the form and
        // story:write --premise require it on a single narrative. Null for an
        // anthology, and for callers that name none — whose outline is then
        // refused until one is chosen. See App\Enums\StoryEnding.
        ?StoryEnding $ending = null,
        // What the narrator and the future partner are to each other by the
        // end. Optional everywhere and read only where it has a subject: a
        // story whose cast names a future partner and whose ending is the
        // narrator's new life. Null is "not chosen", never a default — see
        // App\Enums\PartnerEndState and App\Support\PartnerEnding.
        ?PartnerEndState $partnerEndState = null,
    ): Story {
        $premise = trim($premise);

        if ($premise === '') {
            throw new InvalidArgumentException(
                'A story needs a premise. It is the operator\'s editorial input and there is no default '
                .'for it — the app does not invent what the video is about.'
            );
        }

        $title = trim((string) $title) ?: Str::limit($premise, 60, '');

        // The runtime band is NOT defaulted here. `stories.target_duration_min`
        // and `_max` carry column defaults of 30 and 40, and a second copy of
        // those numbers in PHP is exactly the drift CLAUDE.md warns about with
        // the words-per-minute constant — one target, one place. Omitting the
        // keys lets the column answer, and `refresh()` reads back what it said.
        $band = array_filter(
            ['target_duration_min' => $targetMin, 'target_duration_max' => $targetMax],
            fn (?int $v): bool => $v !== null,
        );

        if ($targetMin !== null && $targetMax !== null && $targetMin > $targetMax) {
            throw new InvalidArgumentException(sprintf(
                'The target runtime floor (%d min) is above the ceiling (%d min).',
                $targetMin,
                $targetMax,
            ));
        }

        // Refreshed, not just created. `status` and `total_cost_usd` are
        // deliberately absent from $fillable — they are a state machine and a
        // derived total, not attributes to assign — so they come from the
        // column defaults and are not on the in-memory model until it is read
        // back.
        $story = Story::create([
            'title' => $title,
            'slug' => Story::slugFor($title, (string) random_int(1000, 9999)),
            'premise' => $premise,
            // Null when the operator did not state one, and null means exactly
            // nothing downstream: the extractor reads ages out of the script as
            // it always has. There is no default age range, because inventing
            // one would be the app making a casting decision.
            'cast_age_profile' => trim((string) $castAgeProfile) ?: null,
            'format' => $format,
            'ending' => $format === StoryFormat::Anthology ? null : $ending,
            'partner_end_state' => $format === StoryFormat::Anthology ? null : $partnerEndState,
            // The setting, chosen once. Everything after this is generated
            // against it — the outline this method's caller dispatches
            // immediately, then the acts, then the cast — so there is no
            // point in the pipeline where changing it leaves the story
            // consistent. Null falls back to the configured default rather
            // than failing, because a caller that does not care should get
            // the house setting.
            'locale_profile' => $this->localeProfile($localeProfile),
            'voice_id' => $narrator === null || trim($narrator) === ''
                ? config('providers.default_voice_id')
                : NarratorVoice::voiceFor(trim($narrator)),
        ] + $band);

        return $story->refresh();
    }

    /**
     * Validated against config rather than trusted.
     *
     * LocaleGuard throws on an unknown profile, but it throws at
     * generation time — after the story exists and the outline call has
     * been queued. Refusing here costs nothing and refuses the row.
     */
    private function localeProfile(?string $requested): string
    {
        $requested = trim((string) $requested);

        if ($requested === '') {
            return (string) config('locale.default');
        }

        if (! array_key_exists($requested, (array) config('locale.profiles', []))) {
            throw new InvalidArgumentException(sprintf(
                'Unknown locale profile "%s". Profiles are data, in config/locale.php; the ones '
                    .'that exist are: %s.',
                $requested,
                implode(', ', array_keys((array) config('locale.profiles', []))),
            ));
        }

        return $requested;
    }
}
