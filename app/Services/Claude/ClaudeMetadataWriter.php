<?php

namespace App\Services\Claude;

use Anthropic\Client;
use App\Contracts\MetadataWriter;
use App\Enums\StoryEnding;
use App\Models\Act;
use App\Models\Story;
use App\Support\LocaleGuard;
use App\Support\OutlineCast;
use App\Support\Providers\MetadataCopyDraft;
use App\Support\Providers\ScriptWriterException;
use App\Support\Providers\TagDraft;
use App\Support\Providers\TitleDraft;

/**
 * The publish sheet, on Claude.
 *
 * Three calls on three models. Which model is config, not code — see
 * `providers.anthropic.operations` for the reasoning per operation — but the
 * shape of the split is here, and it is deliberate:
 *
 *   generate_titles      Five titles plus the description's opening hook. The
 *                        judgement call, and the one place in this stage where
 *                        a better model shows up in the numbers: the title is
 *                        what decides whether thirty-eight minutes of finished
 *                        video is watched at all.
 *
 *   generate_copy        Thumbnail overlay phrases and the pinned comment.
 *
 *   generate_tags        A keyword list inside a hard character budget.
 *
 * Everything the model is given is text this app already holds: the spine
 * written at Gate 1, the act titles and summaries, the real runtime. It is
 * never handed the 6,300-word script — a title does not need it, and paying
 * Opus input rates for 9,000 tokens of narration to write a 70-character
 * sentence is spending in the wrong place.
 *
 * **The narration is not quoted, and the acts are.** The one thing the title
 * genuinely needs is the ending, because in this niche the title states it —
 * so `exposure_moment` is passed verbatim and marked as the payoff. Withholding
 * it would produce a title that withholds, which is the wrong genre.
 */
class ClaudeMetadataWriter implements MetadataWriter
{
    use TalksToClaude;

    public function __construct(
        private readonly Client $client,
        private readonly LocaleGuard $locale,
    ) {}

    public function providerName(): string
    {
        return 'anthropic';
    }

    public function isSimulated(): bool
    {
        return false;
    }

    /**
     * Three models, so there is no single answer.
     *
     * Null rather than a guess. Each cost row carries the model that actually
     * served it — see TalksToClaude::priceUsage() — which is the answer that
     * matters, and inventing one here would put a name on screen that only
     * happened to be right for one of the three calls.
     */
    public function modelName(): ?string
    {
        return null;
    }

    public function titles(Story $story, int $variants): TitleDraft
    {
        [$content, $usage] = $this->call(
            system: $this->titleSystemPrompt($story),
            userMessage: $this->titlePrompt($story, $variants),
            operation: 'generate_titles',
            schema: $this->titleSchema(),
        );

        $decoded = $this->decodeJson($content, 'titles', $usage);

        $titles = [];

        // Short first, and they come back in their own field for a reason. The
        // spec wants a target of 70 characters and the model does not want to
        // write one: asked for a spread in prose, twice, with the reason
        // attached, it returned five variants of 81-94 characters both times.
        // A `maxLength` on a separate array is the same request made
        // structurally, where it is a constraint on the response rather than an
        // instruction the response may decline.
        foreach ([...($decoded['short_titles'] ?? []), ...($decoded['titles'] ?? [])] as $entry) {
            $title = trim((string) $entry);

            if ($title !== '' && ! in_array($title, $titles, true)) {
                $titles[] = $title;
            }
        }

        if ($titles === []) {
            $this->refuseOutput('The title call returned no titles.', 'empty_output', $usage);
        }

        $opening = trim((string) ($decoded['description_opening'] ?? ''));

        if ($opening === '') {
            // Those two or three sentences are what shows in search and above
            // the fold; a description without them is a chapter list with a
            // footer.
            $this->refuseOutput('The title call returned no description opening.', 'empty_output', $usage);
        }

        return new TitleDraft($titles, $opening, $usage);
    }

    public function copy(Story $story, array $titleOptions): MetadataCopyDraft
    {
        [$content, $usage] = $this->call(
            system: $this->copySystemPrompt($story),
            userMessage: $this->copyPrompt($story, $titleOptions),
            operation: 'generate_copy',
            schema: $this->copySchema(),
        );

        $decoded = $this->decodeJson($content, 'thumbnail text and pinned comment', $usage);

        $phrases = [];

        foreach (($decoded['thumbnail_text'] ?? []) as $entry) {
            $phrase = trim((string) $entry);

            if ($phrase !== '') {
                $phrases[] = $phrase;
            }
        }

        return new MetadataCopyDraft(
            $phrases,
            trim((string) ($decoded['pinned_comment'] ?? '')),
            $usage,
        );
    }

    public function tags(Story $story, array $titleOptions, int $charBudget): TagDraft
    {
        [$content, $usage] = $this->call(
            system: $this->tagSystemPrompt($story),
            userMessage: $this->tagPrompt($story, $titleOptions, $charBudget),
            operation: 'generate_tags',
            schema: $this->tagSchema(),
        );

        $decoded = $this->decodeJson($content, 'tags', $usage);

        $tags = [];

        foreach (($decoded['tags'] ?? []) as $entry) {
            // Lowercased and de-duplicated here rather than asked for in the
            // prompt. Both are exact operations and a prompt is not the place
            // to ask for an exact operation — "Family Betrayal" and "family
            // betrayal" are one tag on YouTube and two against the budget.
            $tag = mb_strtolower(trim((string) $entry));

            if ($tag !== '' && ! in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }
        }

        if ($tags === []) {
            $this->refuseOutput('The tag call returned no tags.', 'empty_output', $usage);
        }

        return new TagDraft($tags, $usage);
    }

    // -- Prompts -------------------------------------------------------------

    /**
     * The story, as much of it as writing a title actually needs.
     *
     * The spine and the act titles, not the script. Every act title was written
     * to double as a YouTube chapter heading, so the list of them is already a
     * beat sheet.
     */
    private function storyBrief(Story $story): string
    {
        $lines = [
            'PREMISE',
            trim((string) $story->premise) !== '' ? (string) $story->premise : '(none recorded)',
            '',
            'THE SPINE',
            '- The narrator was wronged by: '.$this->orNone($story->narrator_grievance),
            "- The antagonist's own justification: ".$this->orNone($story->antagonist_justification),
            '- What the narrator knows and the antagonist does not: '.$this->orNone($story->withheld_information),
            '- How it is exposed, in front of witnesses: '.$this->orNone($story->exposure_moment),
            // The reversal, which is what the title is usually built on in this
            // niche. The reference channel frames its videos on the gap rather
            // than the grievance — "never expecting to see me and our son 5
            // years later" is a departure and a refusal, not an exposure — so a
            // brief that stopped at the exposure could only ever produce half
            // the available hooks.
            '- How and when the narrator leaves: '.$this->orNone($story->departure),
            '- What it costs the antagonist to find them: '.$this->orNone($story->reversal_beats),
            '- What the narrator says when found, and what it answers: '.$this->orNone($story->refusal),
            ...$this->endingLines($story),
            '',
            'ACTS, in order. These are the YouTube chapters.',
        ];

        foreach ($story->acts as $act) {
            /** @var Act $act */
            $lines[] = sprintf(
                '%d. %s — %s',
                $act->sequence,
                $act->title,
                trim((string) $act->summary) !== '' ? $act->summary : '(no summary)',
            );
        }

        $lines[] = '';
        $lines[] = sprintf(
            'RUNTIME: %s. This is a long-form narrated video over still illustrations.',
            $this->runtime($story),
        );

        return implode("\n", $lines);
    }

    /**
     * How the video ends, for the packaging. A title states the ending — rule
     * 1 below — so it must be told WHICH ending this video has. An idea that
     * says "married her best friend" on a story whose ending is the
     * antagonist's chapter would otherwise become a title promising a payoff
     * the video never shows. See App\Enums\StoryEnding.
     *
     * Nothing on a story with no ending: it was outlined before the choice
     * existed, and its acts, listed below, are what the brief has.
     *
     * @return array<int, string>
     */
    private function endingLines(Story $story): array
    {
        if ($story->ending === null) {
            return [];
        }

        $partner = OutlineCast::futurePartner($story->outline_cast);

        $noPartnerPromise = 'Do not promise a wedding, a remarriage or a new love: the video does not show one.';

        return [match ($story->ending) {
            // WITH A PARTNER, THE PROMISE IS WHAT THE LAST ACT'S SUMMARY SAYS,
            // and no more. The rule used to forbid a wedding only when there
            // was no partner, so a story with one could be titled "I married
            // her best friend" whatever the last chapter showed. Story 38's
            // shows a boss at a staff dinner. The summary is the act writer's
            // own record of where things stand at the end (its sentence four),
            // so it is true of a story written before the partner was asked to
            // be a couple and of one written after.
            StoryEnding::NewLife => $partner !== null
                ? sprintf(
                    '- How the video ends: the narrator\'s new life, a year on, with %s (%s) on screen. What '
                    .'the two of them are to each other at the end is what the LAST ACT\'S SUMMARY below says, '
                    .'and a title or description promises that and nothing more: a couple only if it says '
                    .'they are together, and a wedding, a marriage or a proposal only if it says one happened.%s',
                    $partner->name,
                    $partner->relationship !== '' ? $partner->relationship : 'the person they end up with',
                    // THE SUMMARY IS STILL THE BOUND, and the chosen state is
                    // said beside it rather than instead of it. The column is
                    // an INTENT recorded before the outline; the summary is
                    // what the acts actually came back with, and the two can
                    // disagree — Gate 1 warns when they do. Promising from the
                    // intent would be the record standing in for the artifact,
                    // which is the shape `thumbnail_selected` was fixed out of.
                    $story->partner_end_state !== null
                        ? sprintf(
                            ' This video was written to end with them %s; if the summary does not say so, the '
                            .'summary is what the video has and the summary is what you may promise.',
                            mb_strtolower($story->partner_end_state->label()),
                        )
                        : '',
                )
                : '- How the video ends: the narrator\'s new life, a year on, alone and fine. There is no new '
                    .'partner in this video. '.$noPartnerPromise,
            StoryEnding::AntagonistVoice => '- How the video ends: the antagonist\'s own chapter, a year on, in '
                .'their own voice — the chance they threw away and what the year looks like. The narrator\'s '
                .'new life is NOT shown'.($partner !== null ? ', and '.$partner->name.' is not on screen at the end' : '')
                .'. '.$noPartnerPromise,
        }];
    }

    private function orNone(?string $value): string
    {
        return trim((string) $value) !== '' ? trim((string) $value) : '(not recorded)';
    }

    private function runtime(Story $story): string
    {
        $last = $story->acts->last();

        if ($last === null || $last->start_ms === null || $last->duration_ms === null) {
            return 'not yet rendered';
        }

        $seconds = intdiv($last->start_ms + $last->duration_ms, 1000);

        return sprintf('%d minutes %02d seconds', intdiv($seconds, 60), $seconds % 60);
    }

    /**
     * The genre contract, restated for the people writing the packaging.
     *
     * Shortened from the script writer's version on purpose. The full six rules
     * are about how to WRITE the story; only two of them govern how to SELL it,
     * and handing a title model four rules it cannot act on is four rules of
     * noise in a prompt whose whole job is compression.
     */
    private function packagingRules(Story $story): string
    {
        return implode("\n", [
            'This is an aggrieved-narrator melodrama for a United States audience, and the packaging',
            'has two rules that are not stylistic:',
            '',
            '1. THE TITLE STATES THE ENDING. This niche does not withhold. "You will not believe what',
            '   happened next" is the wrong genre entirely — the title is the hook precisely BECAUSE it',
            '   promises the payoff. Name the betrayal and name that it lands. PROMISE ONLY THE ENDING',
            '   THIS VIDEO HAS: the brief says how it ends, and a title promising a payoff the video',
            '   never shows is a promise the viewer finds broken at minute thirty-eight.',
            '',
            '2. THE HOOK GOES ON THE LEFT. Search results, mobile and the sidebar all truncate from the',
            '   right. Whatever the emotional payload is, it is in the first forty characters or it is',
            '   not there at all.',
            '',
            $this->locale->guidanceFor((string) $story->locale_profile),
        ]);
    }

    private function titleSystemPrompt(Story $story): string
    {
        $limits = (array) config('youtube.limits');

        return implode("\n", [
            'You write the packaging for a long-form YouTube storytelling channel: the title and the',
            'first lines of the description. Nothing else about the video is in your control, and these',
            'two things decide whether any of the rest is watched.',
            '',
            $this->packagingRules($story),
            '',
            'TITLES',
            sprintf('- Hard limit %d characters. Target %d.', $limits['title_hard'], $limits['title_target']),
            sprintf(
                '- TWO FIELDS. `short_titles` takes two, hard-capped at %d characters by the schema; '
                .'`titles` takes three more, up to %d. Past %d the tail stops being visible on mobile '
                .'and in search, so the short pair is not a compromise — it is the bet that stakes '
                .'everything on the first clause landing, and the operator needs one of each to test.',
                $limits['title_target'],
                $limits['title_hard'],
                $limits['title_target'],
            ),
            '- Every variant must be a DIFFERENT ANGLE, not a rephrasing. Vary which of these carries',
            '  the hook: the sum of money, the relationship, the specific line the antagonist said, the',
            '  moment of exposure, the length of time it went on. Five rewordings of one idea is one',
            '  title with four wasted slots, and the four that are not picked exist to become data.',
            '- Write them as a real person recounting a real thing. No clickbait punctuation, no',
            '  ALL CAPS, no emoji, no "SHOCKING", no numbered-list framing.',
            '',
            'DESCRIPTION OPENING',
            '- Two or three sentences. This is the search snippet and the text above the fold.',
            '- A HOOK, NOT A SUMMARY. It restates the promise the title made and adds the one detail',
            '  the title had no room for. It does not describe the video ("In this story we follow...")',
            '  and it does not spoil the mechanism of the exposure.',
            '- First person, present tense where it reads naturally, same voice as the narration.',
        ]);
    }

    private function titlePrompt(Story $story, int $variants): string
    {
        return implode("\n", [
            $this->storyBrief($story),
            '',
            sprintf(
                'Write %d title variants in total — two in `short_titles`, %d in `titles` — and one '
                .'description opening for this video.',
                $variants,
                max(1, $variants - 2),
            ),
        ]);
    }

    private function copySystemPrompt(Story $story): string
    {
        $maxWords = (int) config('youtube.limits.thumbnail_text_words');

        return implode("\n", [
            'You write the two pieces of short copy around a long-form YouTube story video: the text',
            'that goes on the thumbnail, and the comment pinned to the top of the comments.',
            '',
            $this->packagingRules($story),
            '',
            'THUMBNAIL TEXT',
            sprintf('- Three to five options. Each one three to %d words. No more.', $maxWords),
            '- It is read at about two hundred pixels wide, on a phone, in a scrolling feed. Length is',
            '  the constraint that decides whether it is read at all.',
            '- It must promise the SAME video the titles promise. A thumbnail that contradicts its own',
            '  title loses a click the title had already won.',
            '- Fragments, not sentences. No end punctuation.',
            '',
            'PINNED COMMENT',
            '- Two or three sentences, from the channel, in the narrator\'s voice.',
            '- It asks the viewer something specific about THIS story that they can answer from having',
            '  watched it. A generic "let me know what you think" is the version that gets no replies.',
            '- No links, no calls to subscribe, no hashtags.',
        ]);
    }

    /**
     * @param  array<int, string>  $titleOptions
     */
    private function copyPrompt(Story $story, array $titleOptions): string
    {
        return implode("\n", [
            $this->storyBrief($story),
            '',
            'THE TITLES ALREADY WRITTEN FOR THIS VIDEO. The operator will pick one of them; your',
            'overlay text has to work with any of them.',
            ...array_map(fn (string $t): string => '- '.$t, $titleOptions),
            '',
            'Write the thumbnail overlay options and the pinned comment.',
        ]);
    }

    private function tagSystemPrompt(Story $story): string
    {
        return implode("\n", [
            'You write the YouTube tag list for a long-form story video.',
            '',
            'Tags carry far less weight than the title, the thumbnail and the first lines of the',
            'description. Write them competently and do not strain: this is a keyword list, not copy.',
            '',
            '- Lowercase. No hashes, no quotes, no commas inside a tag.',
            '- Most specific first. The budget is enforced by dropping from the END of your list, so',
            '  the order you return them in is the order they survive in.',
            '- Cover: the story situation, the relationship at the centre of it, the genre and format',
            '  ("family drama story", "reddit style story", "long form story"), and the emotional beat.',
            '- No competitor channel names and nothing about a real person.',
            '',
            $this->locale->guidanceFor((string) $story->locale_profile),
        ]);
    }

    /**
     * @param  array<int, string>  $titleOptions
     */
    private function tagPrompt(Story $story, array $titleOptions, int $charBudget): string
    {
        return implode("\n", [
            $this->storyBrief($story),
            '',
            'THE TITLES WRITTEN FOR THIS VIDEO:',
            ...array_map(fn (string $t): string => '- '.$t, $titleOptions),
            '',
            sprintf(
                'Write the tag list. YouTube allows %d characters TOTAL across every tag, counting the '
                .'separator between them, so keep the whole list inside that. Roughly fifteen to '
                .'twenty-five tags fits.',
                $charBudget,
            ),
        ]);
    }

    // -- Schemas -------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function titleSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                // Two arrays, not one, and the split is the whole point.
                //
                // Both limits are arithmetic, so both are schema constraints
                // rather than requests. The hard limit keeps a title out of a
                // varchar(100) column; the target keeps at least some of the
                // set short enough to survive truncation on mobile and in
                // search. Asking for the second in prose does not work — it was
                // tried, with the reason attached, and came back 81-94
                // characters across all five variants twice.
                //
                // Structured outputs cannot express the COUNT of either array
                // (minItems must be 0 or 1), so the counts are verified after
                // the call where the cost row already exists. The LENGTHS are
                // unreachable rather than checked, which is the stronger claim.
                'short_titles' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                        'maxLength' => (int) config('youtube.limits.title_target'),
                    ],
                ],
                'titles' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                        'maxLength' => (int) config('youtube.limits.title_hard'),
                    ],
                ],
                'description_opening' => ['type' => 'string'],
            ],
            'required' => ['short_titles', 'titles', 'description_opening'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function copySchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'thumbnail_text' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'pinned_comment' => ['type' => 'string'],
            ],
            'required' => ['thumbnail_text', 'pinned_comment'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tagSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'tags' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => ['tags'],
            'additionalProperties' => false,
        ];
    }
}
