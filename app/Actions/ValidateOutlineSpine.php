<?php

namespace App\Actions;

use App\Enums\StoryFormat;
use App\Models\Act;
use App\Models\Story;

/**
 * The genre check, run at Gate 1.
 *
 * An aggrieved-narrator melodrama fails in ways that look fine in the database:
 * every act has a title, a summary and a word count, and the video is still
 * unwatchable because the antagonist is a cartoon or nothing gets worse. Those
 * failures are structural, so they can be checked structurally — and they have
 * to be checked here, before 5,500-8,000 words are generated against the
 * outline and long before 150-250 stills are paid for.
 *
 * What this can and cannot do is worth being honest about. It cannot judge
 * whether writing is good. It CAN tell that a required field is missing, that
 * an antagonist's justification reads as a confession rather than an excuse,
 * that an exposure has no witnesses in it, or that two acts claim the same
 * escalation. Those are the four ways this genre actually gets written wrong,
 * and all four are visible in the text.
 *
 * Nothing here blocks. Gate 1 is an operator decision and these are the notes
 * they should have in front of them when they make it — an outline the
 * operator judges to work despite a warning is theirs to approve.
 */
class ValidateOutlineSpine
{
    /** Below this a spine field is a label rather than an answer. */
    private const MIN_SPINE_CHARS = 60;

    private const MIN_BEAT_CHARS = 25;

    /**
     * Phrases that mean the antagonist knows they are the villain.
     *
     * The single most common way this genre is written wrong. An antagonist who
     * admits fault, or whose "justification" is really an admission, gives the
     * audience nothing to be angry at for thirty-five minutes — the format runs
     * on a person who believes they were owed it.
     */
    private const CONFESSION_MARKERS = [
        'knew it was wrong', 'knew she was wrong', 'knew he was wrong',
        'admitted', 'confessed', 'did not care', "didn't care", 'not care',
        'out of spite', 'to hurt', 'wanted to hurt', 'enjoyed', 'laughed at',
        'jealous', 'jealousy', 'greedy', 'evil', 'cruel', 'malicious',
        'no reason', 'just because', 'always hated', 'wanted to see',
    ];

    /** An exposure with nobody watching is a private conversation. */
    private const WITNESS_MARKERS = [
        'front of', 'everyone', 'guests', 'family', 'room', 'table', 'party',
        'wedding', 'funeral', 'reunion', 'dinner', 'church', 'reception',
        'crowd', 'relatives', 'friends', 'colleagues', 'congregation',
        'toast', 'speech', 'audience', 'gathered', 'witnesses', 'public',
    ];

    /**
     * @return array{
     *     problems: array<int, string>,
     *     warnings: array<int, string>,
     *     spine: array<string, array{label: string, value: string, state: string}>
     * }
     */
    public function handle(Story $story): array
    {
        $problems = [];
        $warnings = [];
        $spine = [];

        foreach ($this->fields() as $key => $meta) {
            $value = trim((string) $story->{$key});
            $state = 'ok';

            if ($value === '') {
                $problems[] = "{$meta['label']} is missing. {$meta['why']}";
                $state = 'missing';
            } elseif (mb_strlen($value) < self::MIN_SPINE_CHARS) {
                $warnings[] = sprintf(
                    '%s is only %d characters — that is a label, not an answer. %s',
                    $meta['label'],
                    mb_strlen($value),
                    $meta['why']
                );
                $state = 'thin';
            }

            $spine[$key] = ['label' => $meta['label'], 'value' => $value, 'state' => $state];
        }

        $this->checkJustification($story, $warnings, $spine);
        $this->checkExposure($story, $warnings, $spine);
        $this->checkEscalation($story, $problems, $warnings);
        $this->checkFormat($story, $warnings);

        return ['problems' => $problems, 'warnings' => $warnings, 'spine' => $spine];
    }

    /**
     * The antagonist has to believe their own excuse.
     *
     * @param  array<int, string>  $warnings
     * @param  array<string, array{label: string, value: string, state: string}>  $spine
     */
    private function checkJustification(Story $story, array &$warnings, array &$spine): void
    {
        $text = mb_strtolower((string) $story->antagonist_justification);

        if (trim($text) === '') {
            return;
        }

        $hits = array_values(array_filter(
            self::CONFESSION_MARKERS,
            fn (string $marker): bool => str_contains($text, $marker)
        ));

        if ($hits === []) {
            return;
        }

        $warnings[] = sprintf(
            'The antagonist reads as a cartoon: their justification contains %s. The infuriating '
            .'part of this format is the EXCUSE, not the villainy — an antagonist who knows they '
            .'are being cruel gives the audience nothing to stay angry at. Rewrite it as something '
            .'they would say out loud and believe.',
            '"'.implode('", "', array_slice($hits, 0, 3)).'"'
        );

        $spine['antagonist_justification']['state'] = 'weak';
    }

    /**
     * The payoff is exposure in front of people.
     *
     * @param  array<int, string>  $warnings
     * @param  array<string, array{label: string, value: string, state: string}>  $spine
     */
    private function checkExposure(Story $story, array &$warnings, array &$spine): void
    {
        $text = mb_strtolower((string) $story->exposure_moment);

        if (trim($text) === '') {
            return;
        }

        foreach (self::WITNESS_MARKERS as $marker) {
            if (str_contains($text, $marker)) {
                return;
            }
        }

        $warnings[] = 'The exposure moment does not name anyone who is there to see it. Witnesses are '
            .'the payoff of this format — the same reveal in private is a different and much worse '
            .'video. Name the occasion and who is in the room.';

        $spine['exposure_moment']['state'] = 'weak';
    }

    /**
     * Every act costs more than the last, and none of them resolves anything.
     *
     * @param  array<int, string>  $problems
     * @param  array<int, string>  $warnings
     */
    private function checkEscalation(Story $story, array &$problems, array &$warnings): void
    {
        $acts = $story->acts()->orderBy('sequence')->get();

        if ($acts->isEmpty()) {
            return;
        }

        $missing = $acts->filter(fn (Act $act): bool => trim((string) $act->escalation_beat) === '');

        if ($missing->isNotEmpty()) {
            $problems[] = sprintf(
                'Act(s) %s have no escalation beat. Each act has to name what it costs the narrator '
                .'that the last one did not; an act that costs nothing is where the retention graph '
                .'falls off.',
                $missing->pluck('sequence')->implode(', ')
            );
        }

        $thin = $acts->filter(fn (Act $act): bool => trim((string) $act->escalation_beat) !== ''
            && mb_strlen(trim((string) $act->escalation_beat)) < self::MIN_BEAT_CHARS);

        if ($thin->isNotEmpty()) {
            $warnings[] = sprintf(
                'Act(s) %s have a one-phrase escalation beat. Name the specific thing it costs.',
                $thin->pluck('sequence')->implode(', ')
            );
        }

        // Two acts claiming the same escalation means the middle of the video
        // is flat, which is exactly where this format loses people.
        $seen = [];

        foreach ($acts as $act) {
            $beat = mb_strtolower(trim((string) $act->escalation_beat));

            if ($beat === '') {
                continue;
            }

            // Digits are KEPT. Stripping them was the first version and it was
            // wrong in a way specific to this genre: escalation is usually
            // expressed as a number going up, so "costs me four thousand" and
            // "costs me twelve thousand" are the same shape but not the same
            // beat, and "cost 4000" vs "cost 8000" would have collapsed into
            // one. Only punctuation and repeated whitespace are normalised.
            $fingerprint = trim((string) preg_replace(
                '/\s+/',
                ' ',
                (string) preg_replace('/[^a-z0-9 ]/', '', $beat)
            ));

            if (isset($seen[$fingerprint])) {
                $warnings[] = sprintf(
                    'Acts %d and %d escalate identically. The middle of the video is flat there — '
                    .'each act has to cost more than the one before it, not the same again.',
                    $seen[$fingerprint],
                    $act->sequence
                );
            }

            $seen[$fingerprint] = $act->sequence;
        }
    }

    /**
     * @param  array<int, string>  $warnings
     */
    private function checkFormat(Story $story, array &$warnings): void
    {
        if ($story->format !== StoryFormat::Anthology) {
            return;
        }

        $warnings[] = 'This story is an anthology. Escalating humiliation compounds across one '
            .'continuous narrative and cannot compound across separate ones — five self-contained '
            .'acts each have a fifth of the runtime to build and pay off their own escalation. '
            .'Single-narrative is the shape this genre needs.';
    }

    /**
     * @return array<string, array{label: string, why: string}>
     */
    private function fields(): array
    {
        return [
            'narrator_grievance' => [
                'label' => 'Narrator grievance',
                'why' => 'The narrator has to be the person who was wronged, not someone watching it '
                    .'happen to a third party. Without this the video has no first person to be angry for.',
            ],
            'antagonist_justification' => [
                'label' => "Antagonist's justification",
                'why' => 'This is the engine of the format. The audience stays for thirty-five minutes '
                    .'because someone is being unreasonable and believes they are being fair.',
            ],
            'withheld_information' => [
                'label' => 'Withheld information',
                'why' => 'What the narrator knows and the antagonist does not. It is what makes '
                    .'escalating humiliation watchable rather than merely unpleasant — the viewer is '
                    .'waiting for a specific thing to land.',
            ],
            'exposure_moment' => [
                'label' => 'Exposure moment',
                'why' => 'The payoff. Exposure in front of witnesses, not revenge — and it is the '
                    .'thing the title promises, so it cannot be decided later.',
            ],
        ];
    }
}
