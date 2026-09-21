<?php

namespace App\Services\Claude;

use Anthropic\Client;
use App\Contracts\ScriptWriter;
use App\Enums\ActPhase;
use App\Enums\ActTimeframe;
use App\Enums\CastRole;
use App\Enums\MotionPreset;
use App\Enums\StoryEnding;
use App\Enums\StoryFormat;
use App\Models\Act;
use App\Models\Chapter;
use App\Models\Scene;
use App\Models\Story;
use App\Support\AntagonistPointOfView;
use App\Support\ChapterAnnouncement;
use App\Support\CharacterTextGuard;
use App\Support\LocaleGuard;
use App\Support\OutlineCast;
use App\Support\PartnerEnding;
use App\Support\Providers\ActOutline;
use App\Support\Providers\ActScriptDraft;
use App\Support\Providers\CastMember;
use App\Support\Providers\ChapterDraft;
use App\Support\Providers\CharacterCast;
use App\Support\Providers\CharacterProfile;
use App\Support\NarratorVoice;
use App\Support\Providers\OutlineDraft;
use App\Support\Providers\PremiseCandidate;
use App\Support\Providers\PremiseDraftSet;
use App\Support\Providers\ProviderUsage;
use App\Support\Providers\SceneDraft;
use App\Support\Providers\SceneDraftSet;
use App\Support\Providers\ScriptWriterException;
use App\Support\ScriptSizing;
use App\Support\SpineQuestions;
use RuntimeException;

/**
 * The script writer, on Claude.
 *
 * Chunked, and sequential where it has to be. A 30-40 minute video is
 * 5,500-8,000 words of narration and no single call holds that coherently, so
 * the shape is premise -> outline -> per-act, each act given the full outline
 * plus a running summary of the acts already written. `actScript()` is called
 * in order by GenerateActScripts and cannot be fanned out.
 *
 * Three things this class owns that the callers must not:
 *
 *  1. **Its own rate card.** Usage comes back on every response and is priced
 *     here, from config. A call site that priced a response itself would drift
 *     the moment a rate changed.
 *  2. **The locale instruction.** Injected into every call from the story's
 *     locale_profile, as data rather than prose baked into a prompt string.
 *     The denylist check that backs it up runs in the Action, after the call —
 *     a provider that validated its own output would have to decide what to do
 *     about a failure, and that decision is the pipeline's.
 *  3. **Structured output.** The outline comes back as JSON against a schema,
 *     not as prose to be parsed. Parsing "Act 1: ..." out of a paragraph is the
 *     kind of thing that works for a month.
 *
 * Streaming on every call. An act on a thinking model runs long enough to
 * outrun a non-streaming HTTP timeout, and on this platform there is no
 * `queue:work --timeout` to catch it — pcntl does not exist in Windows PHP.
 */
class ClaudeScriptWriter implements ScriptWriter
{
    use TalksToClaude;

    public function __construct(
        private readonly Client $client,
        private readonly LocaleGuard $locale,
        // Held so the retry prompt can render the guard's OWN rules rather
        // than a hand-written copy of them. The copy is what failed: the
        // guard banned `weathered` and the copy did not name it, so a
        // rejected extraction was corrected with a note that never
        // mentioned the word it was rejected for.
        private readonly CharacterTextGuard $text,
    ) {}

    public function outline(Story $story, int $actCount, bool $keepCast = true): OutlineDraft
    {
        if ($actCount < 3) {
            throw new RuntimeException(
                "An outline of {$actCount} acts cannot work: acts become YouTube chapters, and YouTube "
                .'ignores a chapter list shorter than three.'
            );
        }

        [$content, $usage] = $this->call(
            system: $this->outlineSystemPrompt($story),
            userMessage: $this->outlinePrompt($story, $actCount, $keepCast),
            operation: 'generate_outline',
            schema: $this->outlineSchema($story->format, $story->ending),
        );

        $decoded = $this->decodeJson($content, 'outline', $usage);

        return $this->outlineDraftFrom($story, $actCount, $decoded, $usage);
    }

    /**
     * The decoded outline response, as a draft.
     *
     * Separate from the call so it can be exercised without one. Every spine
     * field until 2026-09-17 was mapped here with no test reading the mapping:
     * the suite runs on the fake writer, which builds its own draft, so a
     * field dropped on THIS path — the one that ships — left every test green.
     * A drill that removed `antagonist_regret` from the list below proved it.
     * That is the escalation_beat finding at the real writer's decode.
     *
     * @param  array<string, mixed>  $decoded
     */
    private function outlineDraftFrom(Story $story, int $actCount, array $decoded, ProviderUsage $usage): OutlineDraft
    {
        // The phase plan is OURS, not the model's. It is not in the schema and
        // it is not asked for: the prompt states which slot is which phase and
        // the model writes to that, while the column that the act generator
        // later branches on is assigned here from the same arithmetic the
        // prompt was built from. A phase the model returned could disagree with
        // the slot it was given, and there would be no way to tell which of the
        // two the summary was actually written for.
        $plan = $story->format === StoryFormat::Anthology
            ? []
            : ActPhase::planFor($actCount);

        $acts = [];

        foreach (($decoded['acts'] ?? []) as $index => $act) {
            $acts[] = new ActOutline(
                sequence: $index + 1,
                title: trim((string) ($act['title'] ?? '')),
                summary: trim((string) ($act['summary'] ?? '')),
                escalationBeat: trim((string) ($act['escalation_beat'] ?? '')),
                phase: $plan[$index + 1] ?? null,
                // The model's declaration, unlike the phase above, which is
                // ours. A value outside the enum decodes to null rather than
                // to present: null is "not said", and Gate 1 treats it as such.
                timeframe: ActTimeframe::tryFrom(trim((string) ($act['timeframe'] ?? ''))),
            );
        }

        if ($acts === []) {
            $this->refuseOutput('The outline call returned no acts.', 'act_count', $usage);
        }

        // The act count is NOT checked here, deliberately. Structured outputs
        // reject any minItems other than 0 or 1, so an exact length cannot be
        // a schema constraint and has to be verified against the response —
        // but verifying it inside the provider means throwing after the call
        // has already been billed and before its usage has been handed back,
        // which loses the cost row for a call that cost money. GenerateOutline
        // checks it, after recording the cost. See requestedActCount below.

        return new OutlineDraft(
            title: trim((string) ($decoded['title'] ?? $story->title)),
            acts: $acts,
            usage: $usage,
            hook: trim((string) ($decoded['hook'] ?? '')),
            narratorGrievance: trim((string) ($decoded['narrator_grievance'] ?? '')),
            antagonistJustification: trim((string) ($decoded['antagonist_justification'] ?? '')),
            withheldInformation: trim((string) ($decoded['withheld_information'] ?? '')),
            exposureMoment: trim((string) ($decoded['exposure_moment'] ?? '')),
            narratorAtExposure: trim((string) ($decoded['narrator_at_exposure'] ?? '')),
            departure: trim((string) ($decoded['departure'] ?? '')),
            reversalBeats: trim((string) ($decoded['reversal_beats'] ?? '')),
            refusal: trim((string) ($decoded['refusal'] ?? '')),
            betrayalScene: trim((string) ($decoded['betrayal_scene'] ?? '')),
            requestedActCount: $actCount,
            // Decoded as returned. The count is not bounded here and the
            // structure is not judged here: GenerateOutline does both after
            // the cost row, for the reason the act count is checked there.
            cast: $this->castFrom($decoded),
            accompliceMotive: trim((string) ($decoded['accomplice_motive'] ?? '')),
            accomplicePerformance: trim((string) ($decoded['accomplice_performance'] ?? '')),
            accompliceFall: trim((string) ($decoded['accomplice_fall'] ?? '')),
            runningThought: trim((string) ($decoded['running_thought'] ?? '')),
            antagonistRegret: trim((string) ($decoded['antagonist_regret'] ?? '')),
        );
    }

    /**
     * The `narrator` property as the first cast row, then the `cast` array.
     *
     * Everything downstream — Gate 1, the act writer, the extractor,
     * `story:fork` — reads `stories.outline_cast` as one list with the
     * narrator in it, as it did before the property existed. Only the SCHEMA
     * changed shape, so only this seam knows about the split.
     *
     * A narrator property with an empty name still becomes a row, so the
     * refusal reads "has no name" rather than "0 narrators", which is the more
     * exact repair. A missing property adds nothing and the structural check
     * refuses the cast for having no narrator: the schema makes that
     * unreachable from the API, and the check is what holds if it ever is not.
     *
     * @param  array<string, mixed>  $decoded
     * @return array<int, CastMember>
     */
    private function castFrom(array $decoded): array
    {
        $rows = array_values(array_filter((array) ($decoded['cast'] ?? []), 'is_array'));

        if (is_array($decoded['narrator'] ?? null)) {
            array_unshift($rows, ['role' => CastRole::Narrator->value] + $decoded['narrator']);
        }

        return array_map(static fn (array $row): CastMember => CastMember::fromRow($row), $rows);
    }

    public function premises(Story $story, string $idea, int $count): PremiseDraftSet
    {
        [$content, $usage] = $this->call(
            system: $this->premiseSystemPrompt($story),
            userMessage: $this->premisePrompt($story, $idea, $count),
            operation: 'generate_premises',
            schema: $this->premiseSchema(),
        );

        return $this->premiseDraftFrom($this->decodeJson($content, 'premises', $usage), $usage, $count);
    }

    /**
     * The decoded premise response, as a draft. Separate from the call so the
     * decode is exercised without one — the outline decode's lesson.
     *
     * @param  array<string, mixed>  $decoded
     */
    private function premiseDraftFrom(array $decoded, ProviderUsage $usage, int $requested): PremiseDraftSet
    {
        $candidates = [];

        foreach (array_values(array_filter((array) ($decoded['candidates'] ?? []), 'is_array')) as $row) {
            // The narrator property becomes the first cast row, as castFrom()
            // does for the outline, so the checks read the same shape.
            $row['cast'] = array_map(
                static fn (CastMember $m): array => $m->toRow(),
                $this->castFrom($row),
            );
            unset($row['narrator']);

            $candidates[] = PremiseCandidate::fromRow($row);
        }

        return new PremiseDraftSet(
            candidates: $candidates,
            ideaWasRevengeShaped: (bool) ($decoded['idea_was_revenge_shaped'] ?? false),
            translation: trim((string) ($decoded['translation'] ?? '')),
            requested: $requested,
            usage: $usage,
        );
    }

    /**
     * The outline's own system prompt, less the runtime guidance a premise has
     * no use for. The same genre contract, format and locale text, called, so
     * a rule changed there is changed here.
     */
    private function premiseSystemPrompt(Story $story): string
    {
        return implode("\n\n", [
            'You write premises for long-form narrated stories on a YouTube channel in a single, '
            .'specific genre. A premise is what the outline is later written from. Everything below '
            .'is the format, not a suggestion.',
            $this->genreGuidance($story),
            $this->formatGuidance($story),
            $this->locale->guidanceFor((string) $story->locale_profile),
        ]);
    }

    /**
     * The premise request.
     *
     * ONLY THREE THINGS HERE ARE NEW, and each says so: the premise's own
     * shape (no enumerated history, friends as witnesses, how many it names),
     * what the three candidates differ in, and the revenge translation.
     * Everything else is called: the cast question from castInstruction(), the
     * spine questions from SpineQuestions, the genre from the system prompt.
     * That is the refactor's point — without it this method would be the
     * fourth copy of the genre's rules.
     */
    private function premisePrompt(Story $story, string $idea, int $count): string
    {
        $gender = NarratorVoice::genderOf($story->voice_id);

        $narrator = match ($gender) {
            'male' => "THE NARRATOR IS A MAN. The story is read by the channel's male narrator, so the first "
                ."person in every premise is a man.\n\n",
            'female' => "THE NARRATOR IS A WOMAN. The story is read by the channel's female narrator, so the "
                ."first person in every premise is a woman.\n\n",
            default => '',
        };

        return sprintf(
            "Write %d premises for one video, from the operator's idea below.\n\nIDEA:\n%s\n\n"
            .'%s'
            .'A PREMISE is what the outline is written from, and the outline writer is handed the '
            .'premise and nothing else. So everything that matters has to be IN its sentences, not '
            ."only in the fields beside it. Each premise:\n"
            .'- is SIX OR SEVEN SENTENCES, in the narrator\'s first person, past tense. The narrator '
            ."is \"I\" and is never named in it.\n"
            .'- OPENS IN THE ROOM WHERE THE BETRAYAL IS DONE, in the story\'s present: the '
            .'betrayal_scene below, as the premise\'s first sentences. The witnesses there are '
            .'FRIENDS — the couple\'s friends, colleagues, the guests at a wedding or a reunion — '
            .'not the narrator\'s family or hers. The reference\'s betrayal is done at a reunion of '
            .'friends; a family table is where the older stories on this channel staged theirs, in '
            ."private.\n"
            .'- tells ONE INCIDENT, NOW. No enumerated history: not "the first time... the second '
            .'time...", not a list of years. A premise that lists earlier incidents gets them staged '
            .'as whole acts; two stories on this channel spent thirteen minutes that way. If '
            ."something earlier matters, it is one clause.\n"
            .'- PUTS BOTH LINES OF THE BETRAYAL SCENE IN THE PROSE, QUOTED: the accomplice\'s line '
            .'from accomplice_performance, said TO THE NARRATOR\'S FACE rather than to the table or '
            .'the room, and her antagonist_justification, said aloud to the narrator in the words you '
            ."wrote for it.\n"
            .'- NAMES EVERY PERSON IN YOUR CAST, each exactly as written there, and nobody who is not '
            .'in it. Your cast IS the people this premise names — at most %d besides the narrator — '
            .'so a cast row the prose never names is a person the outline is never told about. If '
            .'someone has no job in these sentences, they are not in the cast.'."\n"
            .'- PLACES THE FUTURE PARTNER BEFORE THE DEPARTURE, if your cast has one. The premise '
            .'ends on the departure, so a partner who only arrives afterwards is never in it. Name '
            .'them where they already are — among the friends in the room for the betrayal, or '
            .'already in the narrator\'s life — in a clause that says who they are to the narrator '
            .'and to her. What happens between them later is the video\'s, not the premise\'s. If '
            .'the idea says who the narrator ends up with, your cast has this row and the prose '
            ."names them.\n"
            .'- ENDS ON THE DEPARTURE: the narrator leaving without telling anyone where. That is '
            ."the promise the video pays off.\n\n"
            .'THE %d PREMISES DIFFER IN TWO THINGS AND NOTHING ELSE: the occasion where the betrayal '
            .'is done — a different gathering and room in each — and what only the narrator can '
            .'produce in person at the exposure — a different thing in each. The idea, the people\'s '
            ."roles, the tone and the length are the same kind of thing in all of them.\n\n"
            .'REVENGE. This genre does not do revenge: the payoff is a departure, a search that '
            .'costs her, and a refusal. Operators often write ideas as revenge — "so I made them pay", '
            .'"I got even", "I ruined them". Set idea_was_revenge_shaped to true if this idea is one, '
            .'and write translation as ONE sentence: what the idea asked for, and the refusal it '
            .'became in these premises. If it was not revenge-shaped, set false and leave '
            ."translation empty.\n\n"
            ."For each premise, before writing it, fill in its cast and the spine answers it is built "
            ."on. They are checked against the premise before the operator sees it.\n\n"
            ."%s\n\n"
            .'%s',
            $count,
            trim($idea),
            $narrator,
            (int) config('cast.premise_named', 6),
            $count,
            $this->castInstruction($story, forPremise: true),
            SpineQuestions::bullets(PremiseCandidate::FIELDS, $story),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function premiseSchema(): array
    {
        $candidate = [
            'type' => 'object',
            'properties' => [
                // The cast first, then the spine answers, then the prose that
                // carries them: property order is generation order, and a
                // premise written after its answers is written TO them.
                'narrator' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'relationship' => ['type' => 'string'],
                    ],
                    'required' => ['name', 'relationship'],
                    'additionalProperties' => false,
                ],
                'cast' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'role' => ['type' => 'string', 'enum' => array_column(OutlineCast::castArrayRoles(StoryFormat::Single), 'value')],
                            'relationship' => ['type' => 'string'],
                        ],
                        'required' => ['name', 'role', 'relationship'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'additionalProperties' => false,
        ];

        foreach (PremiseCandidate::FIELDS as $field) {
            $candidate['properties'][$field] = ['type' => 'string'];
        }

        $candidate['properties']['premise'] = ['type' => 'string'];
        $candidate['required'] = array_keys($candidate['properties']);

        return [
            'type' => 'object',
            'properties' => [
                // Judged before any candidate is written, so every candidate
                // is written knowing what the idea was turned into.
                'idea_was_revenge_shaped' => ['type' => 'boolean'],
                'translation' => ['type' => 'string'],
                // One array of identical items and NO labelled slots. The
                // title schema has a `short_titles` field and returns a short
                // title every time, because a required field gets filled; a
                // slot labelled "bold" would get a bold premise whether or not
                // the idea has one. What varies is stated in the prompt.
                'candidates' => ['type' => 'array', 'items' => $candidate],
            ],
            'required' => ['idea_was_revenge_shaped', 'translation', 'candidates'],
            'additionalProperties' => false,
        ];
    }

    public function actScript(
        Story $story,
        ActOutline $act,
        array $fullOutline,
        array $priorSummaries,
        int $targetWords,
    ): ActScriptDraft {
        [$content, $usage] = $this->call(
            system: $this->actSystemPrompt($story),
            userMessage: $this->actPrompt($story, $act, $fullOutline, $priorSummaries, $targetWords),
            operation: 'generate_act_script',
            schema: $this->actSchema(),
        );

        $decoded = $this->decodeJson($content, "act {$act->sequence}", $usage);

        return $this->actDraftFrom($act, $decoded, $usage);
    }

    /**
     * The decoded act response, as a draft. Separate from the call for the
     * reason outlineDraftFrom() is: the path that ships is testable without a
     * stream, so a chapter field dropped here fails a test.
     *
     * @param  array<string, mixed>  $decoded
     */
    private function actDraftFrom(ActOutline $act, array $decoded, ProviderUsage $usage): ActScriptDraft
    {
        // The act comes back AS chapters, and the script is their texts
        // joined. The count and the per-chapter bounds are NOT checked here,
        // for the reason the outline's act count is not: throwing inside the
        // provider loses the cost row for a call that was billed.
        // GenerateActScripts checks them after recording the spend.
        $chapters = [];

        foreach ((array) ($decoded['chapters'] ?? []) as $entry) {
            $text = trim((string) ($entry['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            $chapters[] = new ChapterDraft(
                title: trim((string) ($entry['title'] ?? '')),
                rehookLine: trim((string) ($entry['rehook_line'] ?? '')),
                text: $text,
                pointOfView: trim((string) ($entry['point_of_view'] ?? '')),
            );
        }

        $script = implode("\n\n", array_map(fn (ChapterDraft $c): string => $c->text, $chapters));

        if ($script === '') {
            $this->refuseOutput("Act {$act->sequence} came back with an empty script.", 'empty_output', $usage, ['act' => $act->sequence]);
        }

        return new ActScriptDraft(
            sequence: $act->sequence,
            script: $script,
            summary: trim((string) ($decoded['summary'] ?? '')),
            // The act's opening line is its first chapter's.
            rehookLine: $chapters[0]->rehookLine ?? '',
            usage: $usage,
            chapters: $chapters,
        );
    }

    public function characters(Story $story, array $scripts, array $rejectionNotes = []): CharacterCast
    {
        [$content, $usage] = $this->call(
            system: $this->characterSystemPrompt($story),
            userMessage: $this->characterPrompt($story, $scripts, $rejectionNotes),
            operation: 'extract_characters',
            schema: $this->characterSchema(),
        );

        $decoded = $this->decodeJson($content, 'character extraction', $usage);

        $profiles = [];

        foreach (($decoded['characters'] ?? []) as $entry) {
            $name = trim((string) ($entry['name'] ?? ''));
            $description = trim((string) ($entry['description'] ?? ''));

            if ($name === '' || $description === '') {
                continue;
            }

            $profiles[] = new CharacterProfile(
                name: $name,
                description: $description,
                styleNotes: trim((string) ($entry['style_notes'] ?? '')),
                importance: trim((string) ($entry['importance'] ?? 'supporting')),
            );
        }

        if ($profiles === []) {
            // Every scene prompt is built from this cast, so drafting without
            // it would mean 150-250 stills each inventing their own
            // description of the same people.
            $this->refuseOutput('Character extraction returned nobody.', 'empty_output', $usage);
        }

        return new CharacterCast($profiles, $usage);
    }

    public function scenes(
        Story $story,
        Act $act,
        array $sentences,
        array $cast,
        int $targetScenes,
    ): SceneDraftSet {
        if ($sentences === []) {
            // Before the call, so nothing was billed: a precondition, not a
            // refused output, and deliberately not a ScriptWriterException.
            throw new RuntimeException("Act {$act->sequence} has no script to split into scenes.");
        }

        $system = $this->sceneSystemPrompt($story, $cast);
        $prompt = $this->scenePrompt($story, $act, $sentences, $cast, $targetScenes);

        [$content, $usage] = $this->call(
            system: $system,
            userMessage: $prompt,
            operation: 'draft_scenes',
            schema: $this->sceneSchema(),
        );

        $scenes = $this->decodeScenes($content, $act, $usage);

        // The quality gate, and it is deliberately a structural one rather than
        // a judgement.
        //
        // "Fall back if quality drops" needs a signal a machine can read, and
        // for this call there is an exact one: the scenes must tile the act's
        // sentences from 1 to N with no gap and no overlap. A gap drops
        // narration out of the finished video; an overlap says a line twice.
        // Both are invisible in the output and both are arithmetic here.
        //
        // DraftScenes asserts the same property and throws. That is not
        // duplication — this retries, that refuses. A cheap model that miscounts
        // is worth one more call; a story that still miscounts after the
        // fallback must not reach Gate 2.
        $discarded = [];
        $fallback = (string) (config('providers.anthropic.operations.draft_scenes.fallback') ?? '');

        $reason = $this->unusableReason($scenes, $sentences);

        if ($reason !== null && $fallback !== '') {
            // The first attempt was billed. It is kept and handed back so it
            // writes its own cost row: a fallback that quietly swallowed the
            // wasted call would report a saving it did not make.
            $discarded[] = $usage;

            try {
                [$content, $usage] = $this->call(
                    system: $system,
                    userMessage: $prompt,
                    operation: 'draft_scenes',
                    schema: $this->sceneSchema(),
                    override: [
                        'model' => $fallback,
                        'effort' => config('providers.anthropic.operations.draft_scenes.fallback_effort'),
                    ],
                );

                $scenes = $this->decodeScenes($content, $act, $usage);
            } catch (\Throwable $e) {
                // THE DISCARDED ATTEMPT WAS BILLED AND ITS ROW DIES WITH THIS
                // EXCEPTION UNLESS IT IS WRITTEN NOW. Every usage above is
                // handed back in the SceneDraftSet for DraftScenes to record,
                // and a throw means there is no set to hand back. Story 28 act
                // 2: Haiku billed ~$0.035, Sonnet truncated, and only the
                // Sonnet row reached the ledger.
                //
                // The decode is inside the try for the same reason — a
                // response that arrives and will not parse is one more way to
                // leave the first call unrecorded.
                $this->recordSpendWithNoAction($discarded[0], 'draft_scenes');

                throw $e;
            }
        }

        if ($scenes === []) {
            $this->refuseOutput("Scene drafting for act {$act->sequence} returned no scenes.", 'empty_output', $usage, ['act' => $act->sequence], ...$discarded);
        }

        return new SceneDraftSet($scenes, $usage, $discarded, $reason);
    }

    /**
     * @return array<int, SceneDraft>
     */
    private function decodeScenes(string $content, Act $act, ProviderUsage $usage): array
    {
        $decoded = $this->decodeJson($content, "scenes for act {$act->sequence}", $usage);

        $scenes = [];

        foreach (($decoded['scenes'] ?? []) as $entry) {
            $first = (int) ($entry['first_sentence'] ?? 0);
            $last = (int) ($entry['last_sentence'] ?? 0);
            $frame = trim((string) ($entry['frame'] ?? ''));

            if ($first < 1 || $last < $first || $frame === '') {
                continue;
            }

            $scenes[] = new SceneDraft(
                firstSentence: $first,
                lastSentence: $last,
                frame: $frame,
                charactersPresent: array_values(array_filter(array_map(
                    fn ($name): string => trim((string) $name),
                    (array) ($entry['characters_present'] ?? [])
                ))),
                motionPreset: trim((string) ($entry['motion_preset'] ?? '')) ?: null,
                isThumbnailCandidate: (bool) ($entry['thumbnail_candidate'] ?? false),
                expression: trim((string) ($entry['expression'] ?? '')),
            );
        }

        return $scenes;
    }

    /**
     * Whether this draft is good enough to keep, or worth paying again for.
     *
     * Two checks, and the second one exists because the first one was not
     * enough. Both are structural — there is no model judging another model
     * here, only arithmetic on what came back.
     *
     *  1. **The ranges tile.** Scenes must cover sentences 1..N once each, in
     *     order. A gap drops narration out of the finished video; an overlap
     *     says a line twice. Both are invisible downstream.
     *
     *  2. **The scenes are watchable lengths.** Measured on a real story: the
     *     expensive model left 1% of scenes under the minimum, the cheap one
     *     left 12%. A four-word scene is ~3 seconds on screen, the Ken Burns
     *     move never completes, and the cut reads as a flicker.
     *
     * Check 1 alone shipped first and never fired once across six acts —
     * because counting sentences is the part a cheap model gets RIGHT. The
     * failure was in how it chopped them up. A gate that only tests the strong
     * axis is a gate that always opens.
     *
     * @param  array<int, SceneDraft>  $scenes
     * @param  array<int, string>  $sentences
     */
    private function unusableReason(array $scenes, array $sentences): ?string
    {
        if (! $this->rangesTile($scenes, count($sentences))) {
            return sprintf(
                'sentence ranges did not tile the act (%d scenes over %d sentences)',
                count($scenes),
                count($sentences),
            );
        }

        $minWords = (int) config('scenes.min_words', 8);
        $threshold = (float) config('scenes.fallback_short_share', 0.05);

        $short = 0;

        foreach ($scenes as $scene) {
            $words = 0;

            // Counted from the sentences the range points at, because a
            // SceneDraft carries a range rather than its narration — the text
            // is joined later, by the Action.
            for ($i = $scene->firstSentence; $i <= $scene->lastSentence; $i++) {
                $words += str_word_count((string) ($sentences[$i - 1] ?? ''));
            }

            $short += $words < $minWords ? 1 : 0;
        }

        $shortShare = $short / max(count($scenes), 1);

        if ($shortShare > $threshold) {
            return sprintf(
                '%.1f%% of scenes under %d words, ceiling %.0f%% (%d of %d)',
                100 * $shortShare,
                $minWords,
                100 * $threshold,
                $short,
                count($scenes),
            );
        }

        return $this->motionReason($scenes);
    }

    /**
     * Why the camera is too monotonous across this act, or null if it is fine.
     *
     * Returns a MEASUREMENT, not a verdict. A gate that fires on seven acts
     * out of seven and records only "fell back" costs the same diagnosis
     * every time: story 21 spent $0.16 on discarded attempts and the log
     * could not say which of the three axes had failed, so it had to be
     * inferred by measuring the finished draft against all three.
     *
     * The third axis, added for the same reason as the second: the gate only
     * tested what it already knew to test. Length was checked, motion was not,
     * and a re-draft came back with 30% of the video holding still — a share
     * the expensive model never went near (11%) — while passing every existing
     * check.
     *
     * Two ceilings, because the presets are not equivalent. `static` is the
     * absence of a camera move rather than one of the moves, so a run of it
     * reads as a broken slideshow far sooner than a run of push-ins does; it
     * gets 15% against 55% for everything else.
     *
     * Judged per act, which is what a per-act call can see. A story can still
     * drift across acts without any single act failing — that is what the Gate
     * 2 warning is for, and it reads the same two numbers.
     *
     * @param  array<int, SceneDraft>  $scenes
     */
    private function motionReason(array $scenes): ?string
    {
        $total = count($scenes);

        // Too small a sample to call monotonous. Three scenes sharing a preset
        // is a coincidence, not a pattern, and failing on it would re-bill an
        // act for nothing.
        if ($total < 8) {
            return null;
        }

        $counts = [];

        foreach ($scenes as $scene) {
            $preset = $scene->motionPreset ?? '';

            if ($preset !== '') {
                $counts[$preset] = ($counts[$preset] ?? 0) + 1;
            }
        }

        $static = $counts[MotionPreset::Static->value] ?? 0;

        $staticCeiling = (float) config('scenes.static_share_threshold', 0.15);

        if ($static / $total > $staticCeiling) {
            return sprintf(
                '%.1f%% static, ceiling %.0f%% (%d of %d scenes hold still)',
                100 * $static / $total,
                100 * $staticCeiling,
                $static,
                $total,
            );
        }

        $monotony = (float) config('scenes.motion_monotony_threshold', 0.55);

        foreach ($counts as $preset => $count) {
            if ($preset !== MotionPreset::Static->value && $count / $total > $monotony) {
                return sprintf(
                    '%.1f%% of scenes are "%s", ceiling %.0f%% (%d of %d)',
                    100 * $count / $total,
                    $preset,
                    100 * $monotony,
                    $count,
                    $total,
                );
            }
        }

        return null;
    }

    /**
     * Whether these scenes cover sentences 1..N exactly once, in order.
     *
     * @param  array<int, SceneDraft>  $scenes
     */
    private function rangesTile(array $scenes, int $sentenceCount): bool
    {
        if ($scenes === [] || $sentenceCount < 1) {
            return false;
        }

        $expected = 1;

        foreach ($scenes as $scene) {
            if ($scene->firstSentence !== $expected || $scene->lastSentence < $scene->firstSentence) {
                return false;
            }

            $expected = $scene->lastSentence + 1;
        }

        return $expected === $sentenceCount + 1;
    }

    // -- Prompts -------------------------------------------------------------

    /*
     * The genre is an aggrieved-narrator melodrama, and it has a structure.
     *
     * The first version of these prompts asked for "long-form narrated stories"
     * and got competent literary fiction back: well-observed, well-written, and
     * no reason to keep watching. That is a genre failure, not a quality one,
     * and no amount of asking for "more engaging" prose fixes it — the missing
     * thing is structural.
     *
     * Six rules, and they are load-bearing rather than stylistic:
     *
     *  1. FIRST PERSON, AND WRONGED. The narrator is the injured party, not an
     *     observer of someone else's injury. A narrator watching a wrong happen
     *     to a third party is the literary reflex and it kills the format.
     *
     *  2. THE ANTAGONIST IS SELF-JUSTIFIED. The infuriating part is the EXCUSE,
     *     not the villainy. An antagonist who knows they are being cruel is a
     *     cartoon and the viewer disengages; one who genuinely believes they
     *     were owed it is what holds thirty-five minutes.
     *
     *  3. ESCALATION, NEVER RESOLUTION. Each act makes it worse. An act that
     *     resolves anything has spent the tension the rest of the video runs on.
     *
     *  4. INFORMATION ASYMMETRY. The narrator knows something the antagonist
     *     does not. This is what makes escalating humiliation watchable rather
     *     than merely unpleasant — the viewer is waiting for a specific thing.
     *
     *  5. EXPOSURE, NOT REVENGE, AND IN FRONT OF WITNESSES. The payoff is the
     *     truth landing publicly. Witnesses are load-bearing; the same reveal in
     *     private is a different and much worse video.
     *
     *  6. THE TITLE STATES THE ENDING. This niche does not withhold. The title
     *     is the hook precisely because it promises the payoff.
     */

    /** The genre contract, injected into every call for a story. */
    private function genreGuidance(Story $story): string
    {
        return <<<'TEXT'
        GENRE: first-person aggrieved-narrator melodrama. This is a specific
        format with a specific structure, not "a story told in first person".

        THE NARRATOR
        - First person, past tense. "I", never "she". ONE EXCEPTION, and only
          where the final act's instructions name it: the last chapter of the
          video can be told by the antagonist. It opens by announcing whose it
          is, and inside it "I" is the antagonist. Everywhere else, "I" is the
          narrator.
        - The narrator is the person who was wronged. Not a witness to someone
          else's wrong, not a bystander who pieces something together, not a
          relative watching a family fall apart. It happened to them.
        - THEY ARE PLAIN-SPOKEN AND FUNNY ABOUT IT. Dry, blunt, and specific,
          with a joke where a dignified narrator would go quiet. This was
          measured against a working video in this niche that holds an
          audience for 35 minutes; ours were dignified and mostly silent, said
          "Yes, Mother" nine times in one story, and lost the audience inside
          three minutes. The humour is the narrator's own, aimed at the
          situation and at themselves as often as at the antagonist. They
          still do not rant: a wisecrack is one sentence, not a paragraph.
        - THE HEAD IS FUNNY; THE MOUTH IS CONTROLLED. What the narrator THINKS
          is where most of the jokes live: petty, specific, deadpan, and about
          whatever is in front of them at that moment. A PICTURE, NOT A
          CONCEPT — a joke a viewer has to hold an idea in their head before
          it is funny lands late or not at all, and a joke they could have
          made themselves lands on contact. What the narrator SAYS out loud is
          short, cool and exact. Keep the two apart, because the gap between
          them is what makes a narrator fun to be inside without making them
          a ranter. And a thought costs nothing: it can win every exchange
          without the narrator winning a round. In the reference, when she
          says "it won't affect our wedding", the narrator THINKS, to the
          listener: "Our wedding? Marry you my ass." — and then SAYS, out
          loud, to her, a few seconds later: "I get it. You've brought him
          here and shown him off to all of us. So are you staying for a
          breakup dinner, or going out on a date with your new boy toy?" The
          first is narration and nobody in the room hears it. The second is
          dialogue: cool, exact, and funnier for being controlled. THE THOUGHT
          DOES NOT HAVE TO BE CRUDE, AND MOSTLY SHOULD NOT BE. The funniest
          video measured in this niche runs thirty-two minutes on this gap
          with no profanity at all — its narrator says "You're absolutely
          right, sir" and thinks, in the same breath, that the man's stock is
          down 40% and his hourly rate just doubled for looking at that tie.
          ITS OTHER THOUGHTS ARE THE SHAPE TO COPY: "You bought me a corpse
          suit." "My spine feels like rusted scaffolding." "I'm going to die
          in a silk bathrobe." "I am going to pour it directly into the
          nearest potted plant." One clause each, nothing explained first, and
          every one of them a thing you can see in the room they are standing
          in. The joke is precision, not swearing. Never put the thought in
          the narrator's mouth, and never let the spoken line go crude.
        - EVERY THOUGHT IS TAGGED AS A THOUGHT. One voice reads this whole
          script aloud, so a thought with no tag is heard as something the
          narrator said to the room. Mark every one where it happens — "I
          thought", "in my head", "I didn't say it" — in the same sentence or
          the one right before it. That video tags twenty-eight of its thirty
          thoughts. A thought is about what is happening, never
          about how to read the story: the narrating ban below covers
          thoughts too.
        - THOUGHTS DO TWO DIFFERENT JOBS AND ONE DOES NOT ANSWER THE OTHER.
          THE TEXTURE, which is where the narrator is actually funny: AT LEAST
          THREE ONE-OFF THOUGHTS IN EVERY ACT. A new joke each time, about
          what is in that scene, thought in the moment and never mentioned
          again — the corpse suit, the rusted scaffolding, the potted plant.
          They are thrown away on purpose. The reference runs about fifteen an
          act at our act length and repeats none of them.
          THE RUNNING THOUGHT, which is one line of an act and not its supply
          of jokes: the spine gives the narrator ONE private joke that
          travels. It is planted in chapter one — after the five beats of the
          opening, never inside them — comes back in their head in later acts,
          and pays off in the refusal, where it is said out loud once.
          REPEATING IT DOES NOT COUNT TOWARD THE THREE. An act whose only
          thoughts are that joke again has done the second job four times and
          the first job not at all, which is how a whole video ends up with
          one joke in it.
        - MILD LANGUAGE ONLY, and none in the first thirty seconds. "My ass",
          "hell", "damn" are fine. No f-word, no s-word, nothing stronger:
          strong or frequent profanity limits the ads on a format that exists
          for mid-roll ads, and the first thirty seconds are what YouTube
          reads hardest.
        - When they speak in a scene, they say something. A short, funny,
          exact line — not a speech, and not "I said all right." A narrator
          who answers back is not a narrator who wins the round; what the
          line costs the antagonist, if anything, is decided by the phase.
        - NEVER NARRATE THE NARRATION, AND THIS IS A BAN ON A MOVE RATHER THAN
          ON A LIST OF PHRASES. The move is any sentence whose subject is the
          telling instead of the events: addressing the listener about what
          they should understand, know, notice, remember or hold on to;
          announcing what you are about to say, or being honest, exact, clear
          or fair about it; promising to come back to something. "I want to be
          honest about this." "I want you to understand that nobody asked me."
          "I need you to know what that number meant." "Let me be clear."
          "I'll get to that." Four stories carried sixteen of the first form.
          Those four phrasings were then banned by name and the next story
          carried five of "I want you to understand" instead — the same move
          in a coat the list did not cover. THERE IS NO LIST. If a sentence is
          about how to read the story rather than about what happened in it,
          cut it and say the thing. The narrator never tells the listener what
          to feel or what to notice; they say what was done and let it land.
        - They are not a saint and not a victim in their own telling. They tried
          to be fair. That is what makes the antagonist's excuse land.

        THE ANTAGONIST
        - Their behavior is self-justified. They have a reason, they say it out
          loud, and they believe it. "You don't need it as much as we do." "You
          were always the strong one." "Family helps family."
        - The infuriating part is the EXCUSE, not the cruelty. Never write an
          antagonist who knows they are the villain, enjoys it, or announces it.
          A cartoon switches the viewer off in ninety seconds.
        - They are supported by other people who find the excuse reasonable.
          Isolation of the narrator is the mechanism.

        THE ACCOMPLICE (when the cast has one)
        - The person it is done with or for has a STAKE OF THEIR OWN, and it
          is not hers: money, a position, shares, a house. She does not know
          it. It is why he is in the room, and it is what the story finally
          exposes about him.
        - He puts on a harmless act for her — the old friend who only wants to
          help, the considerate one who offers to apologize, the one who would
          never come between them — and HE TALKS, to her and to the narrator,
          in that voice. He uses the act to make her defend him, and she does.
          The narrator sees through it, and says so in their head, not aloud.
        - Unlike her, he knows what he is doing. He never announces it and
          never enjoys it where she can see; the act is the mask, and only the
          narrator and the audience can see behind it.
        - Before the departure he wins every round with her. From the
          departure on he loses, again and again, in public, mostly in scenes
          he shares with the narrator: his position, his standing with her,
          the people his act fooled, and finally the thing he wanted. His
          motive comes out in front of her. He ends worse off than she does.
          Those losses are her side losing, not the narrator winning early:
          the narrator does not engineer his fall, they are in the room for
          it and answer in a line.
        - BUILD THE ACT ON A ROLE. Never on sexual orientation, gender
          expression, or a manner mocked as unmanly. The device is a harmless
          mask the narrator sees through, and it needs none of that.

        THE SHAPE — FIVE MOVEMENTS, IN THIS ORDER
        This is the part most often got wrong, and getting it wrong produces a
        video that is competent and that nobody finishes. The arc is NOT
        escalation -> exposure -> end.

        1. ESCALATION, AND IT OPENS ON THE BETRAYAL AS A SCENE, NOT A
           DISCOVERY. The first chapter after the hook is the betrayal being
           DONE in front of people, in the story's present — not found in a
           message, a booking or somebody's photos. The reference: she arrives
           late to a dinner of nine people holding another man's hand; a
           friend asks "Who's this? Your younger brother?"; she says "He's my
           boyfriend" one word at a time; then she justifies it to the
           narrator's face — "I want to see a different view before I get
           married... it won't affect our wedding" — and says it again to the
           friend after the man has left. The person it is done with is IN THE
           ROOM. That reference's man turns out to be a schoolmate she asked to
           pretend, with nothing of his own at stake, which is the only reason
           he has nothing to say; an accomplice with a stake speaks here, in
           his act, and she defends him. The antagonist's justification is first said HERE, aloud,
           to the narrator, with an audience — not saved for a banquet ten
           minutes in. The narrator answers back, and loses the round.
           Every act after it costs the narrator more than the last: money,
           standing, a relationship, dignity, in front of more people each
           time. Nothing is recovered: no cost comes back, no apology sticks,
           no ally fixes anything.
           THE NARRATOR TRIES, IN EVERY ONE OF THOSE ACTS, AND IS OVERRULED.
           This is the difference between a man things happen to and a man
           losing a fight, and it is the single most common way this format
           goes wrong. Each escalation act carries one specific, reasonable
           move the narrator makes to stop what is happening — they ask for
           the loan schedule, correct the figure in front of the room, call
           the office, put the receipt on the table, refuse to sign the letter
           — and it FAILS, on screen, because the room, the family or the
           institution sides with the antagonist. Not because the narrator
           thought better of it, not off the page, and not in their head. The
           attempt is what makes the cost a defeat instead of a donation: a
           narrator who never reaches for anything reads as a doormat however
           good his lines are, and three viewers said exactly that about
           stories whose lines were good. Losing a round is not winning it —
           the ledger still runs against the narrator until the departure, and
           an attempt that succeeds is the thing to avoid, not an attempt.
           BUT THE NARRATOR ANSWERS IN THE ROOM. In
           every scene where the antagonist is present, the narrator says
           something — one short, exact, funny line — and it lands. It changes
           nothing about the cost; the ledger still runs against the narrator
           until the departure. The exchange is won and the round is lost,
           which is the sawtooth this niche actually runs: inside the betrayal
           scene itself the reference narrator asks whether she is staying
           for a breakup dinner or going on a date with her new boy toy, and
           she still turns her own best friend's anger back onto him. A
           narrator who says "all right" and "Yes, Mother" for twenty minutes
           was measured against that and lost the audience inside three. The
           narrator holds information the antagonist does not have. THE
           AUDIENCE IS TOLD WHAT IT IS AND WHAT IT CAN DO, IN ACT 1, IN PLAIN
           WORDS; THE ANTAGONIST IS NOT TOLD UNTIL THE FINAL ACT. Those are
           different rules and only the second is about secrecy. Withholding
           it from the viewer as well makes the middle unreadable — they are
           watching a man absorb things while holding an envelope nobody has
           explained — and it spends the one advantage this genre has over a
           mystery, which is that the audience knows the score and the
           antagonist does not. Establish it early, price it, and do not use
           it.
           THE ESCALATION ACTS ARE SET IN THE STORY'S PRESENT. A prior incident
           — the first time this happened, the year the house was bought, what
           was said at a banquet years ago — is CITED in one sentence, with its
           date, inside a present-day act. It is never given a scene and never
           given an act. The line the antagonist said years ago is quoted there
           in one sentence and STAGED at its most recent saying, now, in a room
           the present-day story is in. A history-shaped premise ("the first
           time... the second time... the third time") is the one most likely
           to lose this: the third time is the story, and the first two are a
           sentence each.

        2. THE DEPARTURE. The narrator goes. Not a final speech — they leave,
           and WHERE THEY WENT IS NOT ANNOUNCED. The break itself may be said
           out loud, plainly and once: the reference narrator cancels the
           wedding through her parents and tells her to her face "we broke
           up", and that is not the departure. The departure is the leaving,
           and it is unannounced — no note, no farewell, no address, and
           everyone who knows is asked not to tell her. The antagonist finds
           out later, from somebody else, that they are simply gone.

        3. THE SEARCH, AND THE MEETINGS. The antagonist looks for them AND
           REACHES THEM. This is the part the first version of this contract
           got wrong, and it was measured: a working video in this niche puts
           the antagonist and the narrator in the same scene five times after
           the betrayal — at 9:53, 16:43, 18:21, 27:00 and 28:27 of 34:46 —
           and every one is worse for her. Ours kept them apart for eleven to
           twenty-three minutes and lost the audience. So in this phase she
           runs into them, follows them, sits down across from them, turns up
           where they are — on her initiative or by chance, in front of more
           people each time — and each meeting costs HER more than the last:
           money, standing, the people who backed her excuse, and finally her
           face in public. The narrator answers in a line and leaves. They do
           not go back, do not explain, do not search for her, and do not send
           a message. The search may SUCCEED: she may find out where they are.
           What it must never do is hand her the scene on her terms — when she
           finds them, the finding is the cost (the reference: she finds him
           on a street in another city with someone else, and kneels in
           public), and the narrator decides where and how long they talk.
           THE ACCOMPLICE IS LOSING TOO, in this phase and the next: his act
           stops working, in front of people, in scenes the narrator is in.

        4. THE REFUSALS. The narrator is in the room for the exposure, by
           their own choice or because she came to where they are, and they
           produce the withheld information in person. Then she asks them to
           come back, and the narrator says no. Each refusal answers ONE
           specific earlier humiliation, in the words it was done in. This is
           the private payoff, and it is the thing the audience has been
           waiting the whole video for. The strongest form is a loss she can
           no longer repair: in the reference, the moment he walked away her
           innocence became unprovable, and "how can you prove that" is the
           line that ends her.

        5. THE END. The last refusal lands and the narrator walks away. Then
           ONE ENDING, about a year on — the one chosen for this story, which
           the final act's instructions name. Never both: each carries a year
           the other does not.
           - THE NARRATOR'S NEW LIFE: one scene, with people in it, told as it
             happens — never a list of numbers about anybody's year. The
             reference's narrator side turns on one sentence: a year on, at
             the narrator's wedding, "Among all our mutual friends Sophia was
             the only one who didn't show up."
           - THE ANTAGONIST'S CHAPTER, in the antagonist's own voice: the
             chance they were offered and threw away, which the narrator
             never saw, and what the year looks like from inside their life.
             "The wedding dress I had once ordered still sat on the top shelf
             of my closet."
           Neither is a moral, an apology or a reunion, and neither is "I
           still think about it sometimes".

        The reversal — movements 2, 3 and 4 — is a PHASE, and it is THE LAST
        THREE ACTS: one to leave in, one to be searched for in, one to refuse
        in. It is not a scene at the end. A story that escalates for thirty
        minutes and gives the narrator power in the final ninety seconds has
        written the wrong video.

        THE TWO PAYOFFS
        - PUBLIC: exposure, in front of witnesses. The truth comes out at a
          moment the antagonist chose and controlled. The antagonist's own
          excuse is what convicts them — the best version is the antagonist
          repeating their justification in front of people who now know it is
          false. THE NARRATOR IS IN THE ROOM FOR IT and produces the withheld
          information themselves. A document, a lawyer or a friend can carry
          the fact; the narrator is what makes it a scene rather than a
          report, and a payoff the narrator hears about later, from somebody
          who was there, is hearsay in the one place the video cannot afford
          it. THE BETRAYAL SCENE IS PUBLIC TOO, AND IT IS NOT THIS PAYOFF: in
          the betrayal scene she says her justification in front of people
          and wins the room; at the exposure she says it again in front of
          people who now know it is false and loses it. Witnesses in chapter
          one do not spend the exposure — they are what makes it land. Nor do
          later scenes have to be bigger than the betrayal scene; they have to
          cost more.
        - PRIVATE: the refusal. Said to the antagonist, usually with nobody
          else there, and it answers something specific she said or did
          earlier. The public one is what the title promises. The private one
          is what the viewer stayed forty minutes for. A video with only one of
          them is half a video.
        - NEITHER is revenge. The narrator does not sabotage, retaliate, or
          destroy anything. They produce the truth, and then they decline, and
          both are allowed to do their own work.
        - No violence, no crime by the narrator, no supernatural element.

        WHAT THIS IS NOT
        - Not literary fiction. No ambiguity, no unresolved endings, no
          "deciding whether to" closings, no lyrical drift.
        - Not a mystery. The audience is never confused about who is wrong.
        - Not a thriller. Nobody is in physical danger.
        TEXT;
    }

    private function outlineSystemPrompt(Story $story): string
    {
        return implode("\n\n", [
            'You structure long-form narrated stories for a YouTube channel in a single, '
            .'specific genre. Everything below is the format, not a suggestion.',
            $this->genreGuidance($story),
            $this->formatGuidance($story),
            $this->locale->guidanceFor((string) $story->locale_profile),
            $this->lengthGuidance($story),
        ]);
    }

    private function actSystemPrompt(Story $story): string
    {
        return implode("\n\n", [
            <<<'TEXT'
            You write first-person narration for long-form YouTube story videos. The text
            you produce is read aloud by a single narrator over still illustrations. Nobody
            reads it on a page.

            That means:
            - Write for the ear. Short sentences carry; subordinate clauses do not.
            - No headings, no scene labels, no stage directions, no bracketed notes.
            - No "Act One" or "Chapter" markers in the prose itself, except the
              spoken chapter number the chapter instruction below asks for.
            - Dialogue is quoted plainly. "she said" and nothing fancier. EVERY
              line anybody speaks in this act goes inside quotation marks, every
              time. Direct speech written without them — He said, take these
              back, we are not people who keep things — is read aloud as
              narration, because one voice reads both and nothing tells it where
              the speech starts.
            - THE BIGGEST THING THAT HAPPENS IN THIS ACT IS A SCENE, NOT A
              REPORT. Whatever the act's worst moment is — the demotion, the
              vote, the refusal to sign, the announcement in front of the family
              — it happens on the page: the room, who is in it, and the words
              they actually said, quoted. "He said the company was growing, and
              that from November the manager line would carry another name" is
              the biggest beat of an act delivered as a summary of itself, and a
              viewer hears it as the narrator talking ABOUT a scene instead of
              watching one. Reported speech is for what happened off screen and
              for years gone by. What happens in this act is quoted.
            - Concrete detail over interiority. Name the amounts, the dates, the rooms,
              the exact words people used — of what is happening NOW. What happened
              years ago gets one sentence and its date, never a scene. Specifics are
              what make it feel true.
            - The narrator is telling this to someone, after the fact, in order. They
              already know how it ends and they are not hiding it.
            TEXT,
            $this->genreGuidance($story),
            $this->formatGuidance($story),
            $this->locale->guidanceFor((string) $story->locale_profile),
            $this->lengthGuidance($story),
        ]);
    }

    private function formatGuidance(Story $story): string
    {
        return $story->format === StoryFormat::Anthology
            ? <<<'TEXT'
                FORMAT: ANTHOLOGY. Each act is a self-contained story with its own narrator.

                Be aware this fights the genre: escalating humiliation compounds across a
                single continuous narrative and cannot compound across five separate ones,
                so each act has to build and pay off its own escalation in a fifth of the
                runtime. Prefer the single-narrative format for this genre unless the
                operator has explicitly chosen otherwise.
                TEXT
            : <<<'TEXT'
                FORMAT: SINGLE NARRATIVE. One narrator, one grievance, one antagonist,
                across every act.

                This is the right shape for this genre. The humiliation compounds from act
                to act — the same people, the same relationship, each time worse — and the
                information the narrator is holding stays unused until the final act. Act
                titles double as YouTube chapter titles, so they mark stages of the
                escalation without giving away the exposure.
                TEXT;
    }

    private function lengthGuidance(Story $story): string
    {
        // The SIZING rate, so the word range this asks the model for is the
        // same one `targetWordsPerAct()` will hold the result to. Read from the
        // raw constant, these two agreed only for as long as nothing corrected
        // one of them.
        $wpm = ScriptSizing::wpmFor($story);

        return sprintf(
            'TARGET RUNTIME: %d-%d minutes of narration, roughly %s-%s words in total at the '
            .'pace this is read. This is a watch-time format: the length is the product. Do '
            .'not rush to the exposure, and do not stretch a thin situation to reach a count. '
            .'The escalation is what fills the time.',
            $story->target_duration_min,
            $story->target_duration_max,
            number_format($story->target_duration_min * $wpm),
            number_format($story->target_duration_max * $wpm),
        );
    }

    private function outlinePrompt(Story $story, int $actCount, bool $keepCast = true): string
    {
        // The act count is enumerated rather than stated. "Produce exactly 5
        // acts" was asked twice and came back with 4 both times: a bare count is
        // an instruction a model can satisfy approximately, whereas a numbered
        // list of slots is a shape it has to fill. The exact count matters
        // because each act becomes one YouTube chapter, and it cannot be a
        // schema constraint - structured outputs reject any minItems other
        // than 0 or 1.
        // Each slot names its PHASE, not just its number. A bare numbered list
        // produced escalation all the way down and a reversal crushed into the
        // last act, which is the exact shape story 21 shipped as. The phases
        // are assigned here rather than asked for, from the same arithmetic
        // `acts.phase` is written from, so the plan the model writes to and the
        // plan the act generator later reads cannot disagree.
        $plan = $story->format === StoryFormat::Anthology
            ? []
            : ActPhase::planFor($actCount);

        // The hook's betrayal deadline (in words, at the story's own sizing
        // rate) is now computed inside SpineQuestions::hook(), from the same
        // one place that owns the conversion. See ScriptSizing.
        $slots = implode("\n", array_map(
            fn (int $n): string => isset($plan[$n])
                ? sprintf('  %d. <act %d> — %s. %s', $n, $n, strtoupper($plan[$n]->value), $plan[$n]->guidance())
                : "  {$n}. <act {$n}>",
            range(1, $actCount)
        ));

        return sprintf(
            "Build the outline for this story.\n\nPREMISE:\n%s\n\n"
            ."%s\n\n"
            .'Then establish the spine. Everything else is built on it, so be specific — '
            .'amounts, dates, relationships, and the names from your cast. Vague answers here '
            ."produce a vague video.\n\n"
            // The questions come from SpineQuestions, the only copy: the
            // premise generator asks six of them. An argument, not a splice
            // into this format string, so a "%" in a question is text.
            ."%s\n"
            .'Then fill in every one of these %d slots. Return exactly %d act objects, in '
            ."this order:\n\n%s\n\n"
            .'Do not merge slots, do not leave one out, and do not add another. Each slot '
            .'becomes one YouTube chapter, so the count is fixed before any of it is written. '
            .'The phase on each slot is fixed too: an act written in the wrong phase is worse '
            .'than a missing one, because the escalation has to stop when the narrator leaves '
            ."and start running against the antagonist instead.\n\n"
            ."For each act give:\n"
            .'- title: works as a YouTube chapter title. 2-6 words. Marks a stage of the '
            ."escalation. Does not give away the exposure. No numbering, no 'Act One'.\n"
            .'- summary: 3-5 sentences, at most '.number_format(Act::SUMMARY_MAX_CHARS).' characters. '
            .'What actually happens, concretely. The act script is '
            ."written from this and nothing else, so anything vague here gets invented later.\n"
            .'- escalation_beat: in the ESCALATION and DEPARTURE phases, WHAT THE NARRATOR TRIED '
            .'AND WHAT IT COST THEM THAT IT FAILED. Both halves, in one or two sentences. The '
            .'attempt is a specific, reasonable move a person in that position would actually '
            .'make — ask for the schedule, correct the figure in the meeting, call the bureau, '
            .'put the receipt on the table, say no to the letter — and it FAILS, because the '
            .'room, the family or the institution overrides it, not because the narrator gave up '
            .'on it. Each act costs more than the one before it and nothing resolves: no round '
            .'won, no apology that sticks. A beat that names only a loss ("the narrator loses his '
            .'office and his standing") is the one to avoid — it describes a man things happen '
            .'to, and it is what fifty-three beats in this database did. In the SEARCH and '
            .'REFUSAL phases the same field names what the ANTAGONIST attempted and what it cost '
            .'HER, escalating the same way. The direction changes at the departure and never '
            ."changes back.\n"
            .'- timeframe: "present" or "prior". Every act is PRESENT: it takes place in the '
            .'story\'s now, and anything that happened years earlier is cited inside it in one '
            .'sentence with its date. An act whose summary is a year-old banquet, the first '
            .'betrayal told in full, or four years of night shifts is "prior", and Gate 1 refuses '
            .'the outline for it — so if you find yourself writing one, fold it into a sentence '
            ."of a present-day act instead and mark that act present.\n\n"
            .'Finally, the title of the whole video. Under 70 characters. This genre does '
            .'NOT withhold: the title states the ending, because the promise of the payoff '
            .'is the hook. Front-load the grievance, then name what happens. Something in '
            .'the shape of "My Sister Took X - So At Her Y, I Showed Everyone Z".',
            trim((string) $story->premise),
            $this->castInstruction($story, keepCast: $keepCast),
            SpineQuestions::bullets(SpineQuestions::outlineOrderFor($story->ending), $story),
            $actCount,
            $actCount,
            $slots,
        );
    }

    /**
     * The cast question, asked before the spine.
     *
     * THE WITNESS INSTRUCTIONS HAD TO MOVE IN THE SAME CHANGE. The spine
     * bullets below said "name who is watching" and "name the occasion and the
     * witnesses", and the act prompt said "put the witnesses in the room and
     * name them" twice. A cast bounded here and four sentences asking for more
     * names further down is the act-1 collision again — two instructions that
     * cannot both hold, and the later, more specific one wins. Every one of
     * them now says who is watching by relationship, and names only the cast.
     *
     * The unavailable names are listed in the prompt AND refused in the
     * Action: the prompt is the request, the refusal is the invariant.
     */
    /**
     * `forPremise` changes the two things the premise writer was being told
     * that were written for the OUTLINE writer, and nothing else.
     *
     * Story 38's first roll (2026-09-19): every candidate declared six people
     * and named four in its prose, leaving out the board chairman and the
     * future partner the idea itself asked for. The premise prompt asked for
     * a COUNT, and this instruction then told the same writer "the premise may
     * name the people it needs" — a sentence about the premise as the
     * outline's INPUT, which read by the premise writer is permission to name
     * some — and allowed a cast of max_named (8) against a premise target of
     * premise_named (6). For a premise the cast IS the people the prose names.
     */
    private function castInstruction(Story $story, bool $forPremise = false, bool $keepCast = true): string
    {
        $roles = implode("\n", array_map(
            static fn (CastRole $role): string => sprintf('    %s — %s.', $role->value, $role->guidance()),
            OutlineCast::castArrayRoles($story->format),
        ));

        $taken = array_column(OutlineCast::recentNames($story), 'name');

        // THE NARRATOR IS ASKED FOR BY NAME, AHEAD OF THE CAST, BECAUSE THE
        // CAST'S OWN HEADING EXCLUDED THEM. "Every person this story NAMES"
        // does not describe a first-person narrator: the premise says "I" and
        // so does every spine field. Story 37's outline filled all eight rows
        // with everyone else and was refused. The schema property is the
        // mechanism; this is the wording that asks for it, and says why a
        // person nobody names still needs a name.
        return $this->castQuestion($story, $forPremise, $roles, $taken)
            .($forPremise
                ? ''
                : $this->partnerOutlineInstruction($story)
                    .$this->chosenCastInstruction($story, $keepCast)
                    .$this->chosenSpineInstruction($story));
    }

    /**
     * The spine answers the picked premise was built on, handed over.
     *
     * THE THIRD THING THAT DIED AT "USE THIS PREMISE". The chosen cast and the
     * narrator's name were the first two (3f, story 38); the seven answers
     * beside the prose were the third. Gate 1 ran fourteen checks over them,
     * the operator picked on what those checks said, and the outline — which
     * is handed `stories.premise` and nothing else — answered all seven again
     * from the prose. Story 39's candidate withheld "the only signer on the
     * license renewal for the warehouse lease"; the outline wrote an 8.4
     * million yuan Hamburg account.
     *
     * NOT AN INVARIANT, unlike the chosen cast, and the difference is why
     * `GenerateOutline` refuses a dropped cast member and refuses nothing here.
     * A name is checkable for identity. A spine answer is prose that has to be
     * EXPANDED — the betrayal scene into a chapter, the departure into an act,
     * the withheld information into an exposure the outline also has to invent
     * — so "the outline changed it" is not by itself a defect, and a refusal
     * would turn an ordinary rewrite into a billed failure. It is handed over
     * as the answers to build on; the operator reads the result at Gate 1.
     *
     * Scoped like the chosen cast (`OutlineCast::chosenBeforeOutline`), and it
     * moved with it: while no act carries a SCRIPT. It used to stop at the
     * first outline, on the reasoning that the outline has now answered and a
     * re-outline must be free to answer differently — which reads well and is
     * the wrong default, for the reason recorded on that method. These are the
     * answers Gate 1 checked and the operator picked the premise ON. A
     * re-outline that re-answers them from the prose discards a decision
     * somebody made, silently, and story 39 is the measurement: the candidate
     * withheld a warehouse licence only the narrator could sign, and the
     * re-outline that never saw the answers invented an 8.4M yuan Hamburg
     * account instead. Handed over, the same three specifics came back.
     *
     * NOT tied to the confirm's keep-the-cast checkbox, deliberately. That
     * checkbox says the CAST is what is wrong; an operator repairing a cast has
     * not said anything about the withheld information. Two questions, and
     * folding them into one flag would answer the second without asking it.
     */
    private function chosenSpineInstruction(Story $story): string
    {
        $spine = $story->premise_spine;

        if (! is_array($spine) || $story->hasWrittenActs()) {
            return '';
        }

        $lines = [];

        foreach (PremiseCandidate::FIELDS as $field) {
            $value = trim((string) ($spine[$field] ?? ''));

            if ($value !== '') {
                $lines[] = '- '.$field.': '.$value;
            }
        }

        if ($lines === []) {
            return '';
        }

        return "\n\nTHE PREMISE CAME WITH THESE ANSWERS, AND THE OPERATOR PICKED IT ON THEM. They were "
            .'written for this premise and checked against it before it was chosen, so the specifics in '
            .'them — the occasion, the document, the debt, the thing only the narrator can produce — are '
            .'the operator\'s choice and not a first guess. Build the fields below on these rather than '
            .'answering from the premise prose alone: keep the specifics, and expand them into the '
            ."scene, the act and the exposure the rest of the outline needs.\n"
            .implode("\n", $lines);
    }

    /**
     * What the future partner is to the narrator BY THE END, asked of the
     * outline and never of the premise.
     *
     * Story 38 (CLAUDE.md 3f, second reading): every instruction about the
     * partner was about the ARRIVAL, the act writer got a name and a
     * relationship line, and the last chapter asked for "something they are
     * doing together". It came back as a boss at a staff dinner. Nothing asked
     * for a couple, so nothing produced one. The premise keeps "what happens
     * between them later is the video's": the operator's decision, 2026-09-19,
     * and the reason this lives downstream of it.
     *
     * The act SUMMARIES carry it because the act writer is written from them;
     * a relationship that is only in the cast line reaches the act writer as a
     * job title. Not checked as prose, deliberately: whether two people are
     * written as a couple is a reading, and the operator reads it at Gate 1.
     *
     * THE END STATE IS NAMED, AND THAT IS THE HALF STORY 39 DID NOT HAVE. The
     * whole vocabulary this method offered was "a couple, together, a year on",
     * so "Nancy introduces me to a room as her partner" obeyed it exactly while
     * the operator's idea said "married her older sister". When the operator
     * chose a state it is stated here in its own words; when nobody chose one,
     * the four are NAMED as a list with the weak ones marked as the default a
     * writer falls into — the words widen either way, and only the column
     * decides. See App\Enums\PartnerEndState.
     */
    private function partnerOutlineInstruction(Story $story): string
    {
        if ($story->format === StoryFormat::Anthology) {
            return '';
        }

        // The cast does not exist yet — this outline is what writes it — so
        // the column is read directly rather than through PartnerEnding, which
        // needs a cast to have a subject. The ending half of that scope IS
        // applied: on the antagonist's chapter the narrator's year is not shown
        // at all, so naming an end state there would contradict the ending's
        // own `doesNotCarry()` two blocks down.
        $state = $story->ending === StoryEnding::NewLife ? $story->partner_end_state : null;

        $byTheEnd = $state !== null
            ? sprintf(
                'what they are to the narrator by the end, WHICH IS CHOSEN FOR THIS STORY AND IS NOT '
                .'YOURS TO SOFTEN: %s',
                $state->instruction(),
            )
            : 'what they are to the narrator by the end, SAID IN THE WORD THAT IS TRUE — married, '
                .'engaged, living together, or together and nothing further. "A couple", "together" and '
                .'"my partner" are true of all four and say the least of any of them, and they are what '
                .'a writer reaches for with nothing else to go on. Pick the one the story earns and use '
                .'its own words.';

        return "\n\nIF YOUR CAST HAS A FUTURE PARTNER, their relationship line says who they arrive "
            .'through AND '.$byTheEnd.' The '
            .'acts get there in order, and the act summaries have to say so, because the act writer is '
            .'written from them and learns nothing else about the two of them. Before the narrator '
            .'leaves, the partner is only what they arrive as — a colleague, a friend, a friend\'s '
            .'sister — and those summaries carry nothing romantic. THREE MOMENTS GET THEM THERE AND THEY '
            .'ESCALATE BY WHO CAN SEE THEM: two in the search act, one in the refusal act, each a scene '
            .'rather than a line about the year. The first is private or nearly so. The second is in '
            .'front of people and the antagonist is one of them — that is the escalation, and it is what '
            .'costs her something. The third, in the refusal act, NOBODY SEES: after the room has '
            .'emptied, with nothing to prove to anyone. The search act\'s summary names both of its moments '
            .'and who was watching. The last act\'s summary '
            .'says '.($state !== null ? 'they are '.$state->label().', in those words, in the same sentence as the partner\'s name.' : 'which of the four they are, in its own words, in the same sentence as the partner\'s name.');
    }

    /**
     * The cast chosen before the outline, handed over as fixed.
     *
     * Empty when nothing was chosen — a typed premise, or a re-outline of a
     * story that already has acts — and that prompt is byte-identical to what
     * it was. The chosen cast is the one "Use this premise" kept (story 38
     * lost its partner at exactly that click); OutlineCast::chosenBeforeOutline()
     * says which, for this prompt and for GenerateOutline, which refuses an
     * outline that drops one of these people or gives them another role.
     */
    private function chosenCastInstruction(Story $story, bool $keepCast = true): string
    {
        $members = OutlineCast::chosenBeforeOutline($story, $keepCast);

        if ($members === []) {
            return '';
        }

        return "\n\nTHE CAST IS ALREADY CHOSEN. It came with the premise, and the operator picked it. "
            .'Use every person below, with the name exactly as written and the role exactly as given; '
            .'the narrator is the one marked Narrator, and that is the name in your narrator field. '
            .'You may write a fuller relationship line for anyone. Add another person only under the '
            ."rule above.\n"
            .implode("\n", array_map(
                static fn (CastMember $m): string => sprintf(
                    '- %s (%s)%s',
                    $m->name,
                    $m->role?->label() ?? 'no role',
                    $m->relationship === '' ? '' : ': '.$m->relationship,
                ),
                $members,
            ));
    }

    /**
     * @param  array<int, string>  $taken
     */
    private function castQuestion(Story $story, bool $forPremise, string $roles, array $taken): string
    {
        return sprintf(
            'FIRST, THE NARRATOR: the first person telling this story. The premise is written in their '
            .'voice and almost never says their name, because nobody says their own name while telling a '
            .'story — but they still need one. Their face is drawn in more pictures than anyone else\'s, '
            .'and every picture finds a person by name. If the premise does not name the narrator, give '
            .'them a name under the same naming rules as everyone else, and a relationship that says who '
            .'they are: their work, and who they are to the antagonist. The narrator does not count toward '
            .'the limit below.%s'
            ."\n\n"
            .'THEN THE CAST: every OTHER person this story NAMES, and nobody else. At most %d people '
            .'besides the narrator. For each give a name, a role and a relationship. The roles:'
            ."\n%s\n\n"
            .'Name a person only if they are the narrator or in this list. Everyone else — witnesses, the cousin who '
            .'asks the question, a waiter, a client, a colleague who speaks once — stays UNNAMED in '
            .'every field below and in every act: "his cousin", "a client of the studio", "her '
            .'mother\'s friend". A named person is a face drawn and paid for in every picture they '
            .'are in; an unnamed witness is part of the room. %s%s',
            $story->format === StoryFormat::Anthology
                ? ' On an anthology this is the narrator of act 1\'s story; each other act\'s narrator '
                    .'goes in the cast below with the role narrator.'
                : '',
            $forPremise ? max(1, (int) config('cast.premise_named', 6)) : OutlineCast::maxNamed(),
            $roles,
            $forPremise
                ? 'Everyone in this cast is named in the premise you write, and the premise names '
                    .'nobody else.'
                : 'The premise may name the people it needs; use those names exactly, and give anyone '
                    .'else the premise implies a name only if the story cannot be told without them on '
                    .'screen more than once.',
            $taken === []
                ? ''
                : "\n\nThese full names were used by recent videos on this channel and are NOT "
                    .'available — a viewer who hears the same name in two videos hears the same '
                    .'person. Do not use any of them, and do not reach for the example names in '
                    ."your setting guidance either:\n    ".implode(', ', $taken),
        );
    }

    /**
     * How an act is told to put witnesses in the room.
     *
     * A story outlined with a cast is told to name only the cast; a story
     * outlined before the cast existed keeps the sentence it was written to.
     */
    /**
     * The cast, as the act writer is handed it: the people who exist.
     *
     * Before this, the act writer received no cast at all and added 14 of
     * the 72 characters measured across seven stories. It is handed the list
     * with each role, and told that anyone else is referred to by who they
     * are. What it does with the future partner is deliberately NOT
     * instructed here: whether a named row alone carries them into the acts is
     * the measurement item 5 is waiting on, and an instruction would make
     * that measurement unreadable.
     */
    private function outlineCastBlock(Story $story): string
    {
        $members = OutlineCast::members($story->outline_cast);

        if ($members === []) {
            return '';
        }

        return "THE PEOPLE IN THIS STORY. These are the only people with names:\n"
            .implode("\n", array_map(
                static fn (CastMember $m): string => sprintf(
                    '- %s (%s)%s',
                    $m->name,
                    $m->role?->label() ?? 'no role',
                    $m->relationship === '' ? '' : ': '.$m->relationship,
                ),
                $members,
            ))
            ."\n\nDo not name anyone else. Everyone else who appears is who they are to somebody — "
            .'"his cousin", "a client", "the waiter" — however many times they appear. Use each name '
            .'in exactly the form written here.';
    }

    /**
     * What the future partner is to the narrator in THIS act, by phase.
     *
     * The act writer was handed the partner's name, the label and a
     * relationship line, and nothing else — on purpose, so item 5 could
     * measure whether the row alone carries them (CLAUDE.md 3f). Story 38
     * answered: it carries a person and not a relationship. So each phase says
     * what they are to each other there, and the last says it plainly.
     *
     * No romance before the narrator leaves: the partner arrives as a
     * colleague or a friend's sister, and that is right (the operator,
     * 2026-09-19). Empty with no partner in the cast, and on an anthology,
     * whose acts have no phase.
     */
    private function partnerArcFor(Story $story, ActOutline $act): string
    {
        $partner = OutlineCast::futurePartner($story->outline_cast);

        if ($partner === null || $act->phase === null) {
            return '';
        }

        $state = PartnerEnding::stateFor($story);

        return sprintf('THE PERSON THE NARRATOR ENDS UP WITH: %s. ', $partner->name).match ($act->phase) {
            ActPhase::Escalation, ActPhase::Departure => sprintf(
                'In this act %s is only what they arrive as — %s — if they are in it at all. Nothing '
                .'romantic between them here: no attraction narrated, no look held, no hint of what comes.',
                $partner->name,
                $partner->relationship !== '' ? $partner->relationship : 'someone already in the narrator\'s life',
            ),
            ActPhase::Search => sprintf(
                'In this act it becomes more than %s, in TWO MOMENTS — each one a scene, told as it '
                .'happens, where the narrator and %s do or say something only two people becoming a '
                .'couple would. THEY ESCALATE BY WHO CAN SEE THEM: the first is private or nearly so; '
                .'the second is in front of people and the antagonist is one of them. Two moments — not '
                .'a paragraph about the year, and not a mood over the act; in every other scene she is '
                .'in, she is what she arrived as. The narrator stages neither of them for her. '
                .'SENTENCE FOUR OF YOUR SUMMARY NAMES BOTH AND SAYS WHO WAS WATCHING: the next act is '
                .'written from this summary and learns nothing else about the two of them.',
                $partner->relationship !== '' ? 'what they arrived as' : 'friends',
                $partner->name,
            ),
            // THE ACT AND THE LAST CHAPTER ARE A YEAR APART, and only the
            // second one carries the end state: the refusal happens now and
            // the new life is a year on, so "married by this act" would be
            // asking the wrong scene for it. Sentence four of the summary is
            // where Gate 1 reads it back, because the act writer REPLACES the
            // outline's summary with its own — which is also why choosing the
            // state after the outline still reaches the artifact.
            ActPhase::Refusal => sprintf(
                'By this act the narrator and %s are together, and wherever the act touches the '
                .'narrator\'s own life it says so plainly. ONE MORE MOMENT BETWEEN THEM HAPPENS IN '
                .'THIS ACT, AND NOBODY SEES THIS ONE: after the room has emptied, with nothing to prove '
                .'to anyone and no one left to see it. It is the third of the three and the only one '
                .'that is not for an audience. In the last chapter, a year on, %s '
                .'Sentence four of the summary says so, in those words, in the same sentence as %s\'s name.',
                $partner->name,
                $state !== null
                    ? 'they are '.$state->label().': '.$state->instruction()
                    : 'say which of the four they are — married, engaged, living together, or together '
                        .'and nothing further — in its own words.',
                $partner->name,
            ),
        };
    }

    private function witnessInstruction(Story $story): string
    {
        return $story->outline_cast === null || $story->outline_cast === []
            ? 'Put the witnesses in the room and name them.'
            : 'Put the witnesses in the room. Name only people in the cast; everyone else is who they '
                .'are to somebody — his cousin, a client, the waiter.';
    }

    /**
     * @param  array<int, ActOutline>  $fullOutline
     * @param  array<int, string>  $priorSummaries
     */
    private function actPrompt(
        Story $story,
        ActOutline $act,
        array $fullOutline,
        array $priorSummaries,
        int $targetWords,
    ): string {
        $outlineBlock = implode("\n", array_map(
            fn (ActOutline $entry): string => sprintf(
                // "COSTS:" was right while the beat was only a cost. Since
                // 2026-09-20 an escalation beat carries the narrator's failed
                // attempt as well, so the label names the field rather than
                // half of it — a context block that says COSTS in front of
                // "he asked for the schedule and never got it" teaches the
                // writer that the attempt is not part of the answer.
                "%d. [%s%s] %s\n   %s\n   BEAT: %s%s",
                $entry->sequence,
                // The phase is in the context block, not only on the act being
                // written. An act 6 that cannot see act 5 was the departure
                // has no way to know the narrator is already gone.
                strtoupper($entry->phase?->value ?? 'act'),
                $entry->timeframe === null ? '' : ' · '.strtoupper($entry->timeframe->value),
                $entry->title,
                $entry->summary,
                $entry->escalationBeat,
                $entry->sequence === $act->sequence ? "\n   <-- WRITE THIS ONE" : ''
            ),
            $fullOutline
        ));

        // The running summary is what makes chunked generation coherent. Act 4
        // without it repeats act 2 and contradicts act 3.
        $priorBlock = $priorSummaries === []
            ? 'This is the first act. Nothing has been written yet.'
            : implode("\n\n", array_map(
                fn (string $summary, int $index): string => sprintf('Act %d: %s', $index + 1, $summary),
                $priorSummaries,
                array_keys($priorSummaries)
            ));

        $isLast = $act->sequence === count($fullOutline);

        $rehook = $act->sequence === 1
            ? $this->hookInstruction($story)
            : sprintf(
                'This act opens at roughly minute %d, where viewers leave. Its first two sentences '
                .'are a re-hook: give someone about to close the tab a reason not to. Open on the '
                .'next indignity already in progress. Do not open by recapping act %d.',
                // The same rate the word target was derived from, necessarily:
                // this converts that target back into minutes, and a different
                // divisor would place the re-hook at a minute the script never
                // reaches.
                (int) round(($act->sequence - 1) * $targetWords / ScriptSizing::wpmFor($story)),
                $act->sequence - 1,
            );

        $ending = $this->endingFor($story, $act, $isLast);

        return implode("\n\n", array_filter([
            // First, because every block below names people. Empty on a story
            // outlined before the cast existed, which gets exactly the prompt
            // it had.
            $this->outlineCastBlock($story),
            'THE SPINE OF THIS STORY:',
            sprintf(
                "Grievance: %s\n\nThe antagonist's justification: %s%s%s\n\nWhat the narrator knows and "
                ."they do not: %s\n\nWhere it comes out: %s%s%s",
                $story->narrator_grievance,
                $story->antagonist_justification,
                // His stake and his act, to every act: an escalation act has
                // to plant the stake and put the act on screen, and a search
                // act has to know what is coming out. His FALL is not here —
                // it goes only to the acts it happens in, through endingFor(),
                // because an escalation act told how he ends spends it early.
                trim((string) $story->accomplice_performance) === ''
                    ? ''
                    : "\n\nThe accomplice — what he wants for himself, which she does not know and which "
                        .'does not come out before the last three acts: '.$story->accomplice_motive
                        ."\n\nThe act he puts on for her, and speaks in: ".$story->accomplice_performance,
                // To every act, not only act 1 that stages it. An act 3 that
                // does not know the justification was already said aloud in
                // front of nine people re-stages its "first" saying at a
                // banquet — which is what stories 29-32 did — and a refusal
                // act that does not know it cannot hand that sentence back.
                trim((string) $story->betrayal_scene) === ''
                    ? ''
                    : "\n\nWhere she first said it aloud, to the narrator's face, in chapter one: "
                        .$story->betrayal_scene,
                $story->withheld_information,
                $story->exposure_moment,
                // The fifth spine line, to every act and not only the last:
                // an escalation act that knows the narrator will produce the
                // bag in person at the banquet plants the bag, and a search
                // act that knows it does not write the antagonist finding it.
                trim((string) $story->narrator_at_exposure) === ''
                    ? ''
                    : "\n\nHow the narrator is in the room for it: ".$story->narrator_at_exposure,
                // To every act, because the refusal act is written from five-
                // sentence summaries and would never otherwise learn what act 1
                // planted. This column is the carrier; see the migration.
                trim((string) $story->running_thought) === ''
                    ? ''
                    : "\n\nThe narrator's running thought — a private joke, always tagged as a thought, "
                        .'said aloud only once, in the refusal: '.$story->running_thought,
            ),
            'FULL OUTLINE (for context — write only the marked act):',
            $outlineBlock,
            'WHAT HAS ALREADY BEEN WRITTEN:',
            $priorBlock,
            sprintf('NOW WRITE ACT %d: %s', $act->sequence, $act->title),
            $act->summary,
            sprintf('%s: %s', $act->phase?->beatLabel() ?? 'What this act must cost the narrator', $act->escalationBeat),
            $this->timeframeInstruction($act),
            $ending,
            $this->partnerArcFor($story, $act),
            $this->chapterInstruction($story, $act),
            // THE OPENING INSTRUCTION GOES LAST, on every act and not only on
            // act 1. Story 30 lost two of act 1's five beats and both of its
            // special-case chapter announcements to the two blocks above this
            // one, which arrived after it and said "every scene" and "every
            // chapter". Recency is not the mechanism — the blocks above now
            // name their own exception, and this block outranks them in words
            // — but an instruction about the first thirty seconds should not
            // be the furthest thing from the request that follows it.
            $rehook,
            sprintf(
                "Target %s words of narration across the whole act. Return:\n"
                ."- chapters: the act, in order, as the chapters described above. Each has:\n"
                ."    - title: 2-6 words, at most %d characters. A YouTube chapter title: it marks a "
                ."stage without giving away what comes. No numbering.\n"
                ."    - rehook_line: the chapter's RE-HOOK, quoted back exactly — the first "
                ."sentence of the chapter proper. Never the spoken chapter number: that is the "
                ."announcement, not the re-hook, and a chapter whose recorded opening line is "
                ."\"Chapter four.\" has no re-hook on record at all.\n"
                ."    - text: that chapter's narration, first person, continuous prose. Every "
                ."chapter's text ends on a complete sentence.\n"
                ."    - point_of_view: an empty string, unless the final act's instructions name a "
                ."chapter told by someone other than the narrator; then that one chapter carries "
                ."their name exactly as given.\n"
                ."- summary: FIVE SENTENCES, ONE EACH, in this order and nothing else:\n"
                ."    (1) what happened in this act;\n"
                ."    (2) what was said that matters, with the one line quoted;\n"
                ."    (3) what it cost, and to whom;\n"
                ."    (4) where things stand at the end;\n"
                ."    (5) the one thing the next act must not contradict.\n"
                ."  FIVE SENTENCES, NOT FIVE PARAGRAPHS. This is the only thing the next call "
                .'will know about this act, so every sentence carries facts — names, amounts, '
                .'dates, the quoted line — and none of them re-tells the act. At most %s '
                .'characters. Three of the last twelve acts broke that bound by writing '
                .'paragraphs where sentences were asked for; a summary over it is refused, the '
                .'call is billed in full, and the act is written again from nothing.',
                number_format($targetWords),
                Chapter::TITLE_MAX_CHARS,
                // The bound the form and the Action enforce, stated to the
                // writer rather than assumed. Not a schema constraint:
                // structured outputs do not honour maxLength. See
                // Act::SUMMARY_MAX_CHARS.
                number_format(Act::SUMMARY_MAX_CHARS),
            ),
        ]));
    }

    /**
     * What an act is told about the chapters it comes back as.
     *
     * -----------------------------------------------------------------------
     * THE MEASUREMENT
     * -----------------------------------------------------------------------
     *
     * A working video in this niche runs fourteen chapters in 34:46, about
     * 2:29 each, with a re-hook at every one. Ours ran six acts of 5:54 to
     * 9:44, so the first re-hook after the opening landed at 6:45 to 7:47 —
     * on a format whose one measured failure is people leaving inside the
     * first three minutes.
     *
     * The act stays the unit this call writes, because the writer returns
     * ~1,100 words of it whatever it is asked and fourteen acts of that is a
     * 75-minute video. The chapter goes UNDER it: two or three per act, so
     * the act's natural length is the thing that fits rather than the thing
     * being fought. See config/chapters.php.
     *
     * The budgets go through the story's own frozen sizing rate, the same
     * one the act's word target came from. Two rates in one prompt is two
     * beliefs about one narration inside a single string.
     *
     * -----------------------------------------------------------------------
     * THE COUNT IS DERIVED BY THE WRITER, NOT STATED TO IT, AND THAT REVERSES
     * THE FIRST VERSION
     * -----------------------------------------------------------------------
     *
     * This used to say "at this act's length that is N chapters", with N
     * computed from the act's word TARGET. Story 30 came back as two chapters
     * per act every time — including the act that ran to 1,195 words, where
     * the honest answer was three — because the writer obeyed the stated
     * number rather than the length it had just written.
     *
     * That is the word-target finding with the sign flipped, and the pair is
     * what makes it worth reading as a rule. A stated FIGURE steers weakly:
     * the act word target moves the writer by about 0.30 words per word
     * asked, so a hundred more words buys thirty. A stated COUNT steers
     * absolutely: it was obeyed 6 times out of 6, at every act length from
     * 1,052 to 1,195 words. **The difference is not that one number is more
     * important. It is that a count is discrete and a writer can satisfy it
     * exactly, so it stops being advice and becomes an instruction.**
     *
     * So the prompt states the DIVISOR and the bounds and asks for the
     * division to be done afterwards, against the text that exists. The
     * worked example is anchored on `naturalActWords()` — what the writer is
     * measured to produce — rather than on the target, because an example
     * built from the target would be the stated count again wearing a
     * different hat.
     *
     * The divisor alone would not have moved anything, and that is recorded
     * in config/chapters.php rather than here: at the old 150-second chapter
     * budget a 1,123-word act divides into 2.25 and rounds to two, so an
     * honest derivation returns exactly the number the stated one did. The
     * budget moved to the transcript's measured 133 seconds in the same
     * change, and only the two together change the cadence.
     */
    private function chapterInstruction(Story $story, ActOutline $act): string
    {
        $chapterWords = ScriptSizing::chapterTargetWords($story);
        $min = (int) config('chapters.min_per_act', 2);
        $max = (int) config('chapters.max_per_act', 4);
        $minWords = (int) config('chapters.min_words', 150);

        // The worked example is anchored to what the writer is MEASURED to
        // produce, not to what it was asked for. That is the whole point of
        // the change: see the docblock.
        $natural = ScriptSizing::naturalActWords();
        $naturalChapters = ScriptSizing::chaptersPerAct($story, $natural);

        // The reference speaks its chapters as a bare number — "chapter 1",
        // "chapter 2" — with no title, and the cold open comes BEFORE
        // "chapter 1" (0:00-1:02, then the announcement). So the number is
        // narration and the title is metadata.
        $first = $this->firstChapterNumberFor($story, $act);

        $announce = ChapterAnnouncement::enabled()
            ? sprintf(
                ' EVERY CHAPTER OPENS BY SPEAKING ITS NUMBER, as narration, as its own sentence — '
                .'"%s" — the number in words, no title — and THEN that chapter\'s re-hook '
                .'sentence. Chapters are numbered across the whole video, not within the act: '
                .'this act\'s first chapter is chapter %d and they count upward from there, one '
                .'number per chapter you write. The title is never spoken; it goes to the chapter '
                .'list.%s',
                ChapterAnnouncement::sentenceFor($first),
                $first,
                // Act 1 does NOT get the rule restated here. It gets a pointer
                // to the one block that owns its opening order, because act 1
                // is where three instructions collide and the fix was to give
                // them a single owner rather than a copy each. See
                // hookInstruction().
                $act->sequence === 1
                    ? ' THIS ACT IS THE EXCEPTION and the OPENING block below sets its order: the '
                        .'hook is the cold open and is spoken before any chapter number, so '
                        .'"Chapter one." comes after it. Every later chapter in this act opens '
                        .'with its number as normal.'
                    : '',
            )
            : ' The title is not spoken: it goes to the chapter list, not the narration.';

        // The refusal act's last chapter is hers, and it is the second place
        // in the script where a rule saying EVERY collides with another block:
        // "every chapter opens by speaking its number" against a chapter with
        // no number. Named here as well as in the ending that owns it, the way
        // act 1's opening exception is — a writer reading top to bottom meets
        // this sentence first.
        if (AntagonistPointOfView::endsAct($story, $act->phase)) {
            $announce .= ' THE LAST CHAPTER OF THIS ACT IS THE EXCEPTION, and the final block below sets '
                .'it: it is told by '.AntagonistPointOfView::nameFor($story).', it speaks no number, '
                .'and it is not counted in the chapter count above.';
        }

        return sprintf(
            'WRITE THIS ACT AS CHAPTERS. A chapter is about %d seconds of narration — roughly %s '
            .'words — and it is the unit the viewer experiences: a title in the progress bar and, '
            ."more importantly, a fresh re-hook.\n\n"
            .'HOW MANY CHAPTERS IS DECIDED BY THE LENGTH YOU ACTUALLY WRITE, not by the word '
            .'target: divide the words you wrote by %s and round. Acts come back longer than they '
            .'are asked for — the measured length is about %s words, and %s words is %d chapters, '
            .'not %d. Count yours the same way, after you have written it. Never fewer than %d and '
            .'never more than %d, and no chapter under %d words. Break where the ground shifts — a '
            ."new room, a new indignity, a new person — never mid-scene.\n\n"
            .'EVERY CHAPTER OPENS WITH ITS OWN RE-HOOK: the first sentence of the chapter proper '
            .'gives someone about to close the tab a reason not to. The next indignity already in '
            .'progress, a line somebody said, a number. Not a recap of the chapter before it.%s',
            (int) config('chapters.target_seconds', 133),
            number_format($chapterWords),
            number_format($chapterWords),
            number_format($natural),
            number_format($natural),
            $naturalChapters,
            max($min, $naturalChapters - 1),
            $min,
            $max,
            $minWords,
            $announce,
        );
    }

    /**
     * The story-wide number of this act's first chapter: one more than the
     * chapters already stored on the acts before it.
     *
     * Read from the rows rather than projected, so a rewritten act 4 numbers
     * from what acts 1-3 actually returned. The known limit: rewriting an
     * early act to a different chapter count shifts the spoken numbers of
     * every act after it, which `story:write --acts-only` does not re-write.
     * A story rewritten act by act should be rewritten from the changed act
     * onward, and `story:write` says so in its report.
     */
    private function firstChapterNumberFor(Story $story, ActOutline $act): int
    {
        $earlierActIds = $story->acts()->where('sequence', '<', $act->sequence)->pluck('id');

        return 1 + Chapter::query()->whereIn('act_id', $earlierActIds)->count();
    }

    /**
     * What an act is told about WHEN it is set.
     *
     * The measurement behind it is in ActTimeframe: story 28's present-day
     * betrayal lands at 20:18 because two of its three escalation acts stage
     * 2015 and 2017 in full, and story 23's at 15:01 for the same reason. The
     * outline declares each act present or prior now and Gate 1 refuses a
     * prior escalation act, so what reaches this call is an act the outline
     * says is present — and this is the instruction that keeps it there,
     * because the act system prompt's "name the dates, the rooms, the exact
     * words" is exactly the instruction that stages a year-old banquet given
     * the chance.
     *
     * Story 25 is the model and is quoted rather than described: the
     * antagonist's line is quoted in the hook and STAGED at a present-day
     * dinner, so the line still lands twice without an act going to history.
     *
     * Silent for a null timeframe. Every act outlined before the field
     * existed is null, those outlines are not regenerated, and an act being
     * re-written one at a time on one of them should get the shape its
     * outline was built to — the same reason `endingFor()` keeps its no-phase
     * branch.
     */
    private function timeframeInstruction(ActOutline $act): string
    {
        return match ($act->timeframe) {
            ActTimeframe::Present => 'THIS ACT IS SET IN THE STORY\'S PRESENT. Anything that happened '
                .'years earlier — the first time, the year the apartment was bought, what was said at a '
                .'banquet back then — is CITED in one sentence with its date, and not staged: no '
                .'scene from that day, no dialogue from it, no room described. If the antagonist '
                .'said the line years ago, quote it in one sentence and stage its most recent '
                .'saying, now, in a room this act is in. Story 25 quotes "a wife who earns more" '
                .'in its first thirty seconds and stages it at a present-day dinner in act 2; that '
                .'is the shape. Two acts spent staging 2015 and 2017 is what put story 28\'s '
                .'present-day betrayal at minute twenty.',
            ActTimeframe::Prior => 'This act is marked as set BEFORE the story\'s present. That is '
                .'refused at Gate 1 and should not have reached you; write it as a present-day act '
                .'that cites the earlier incident in one sentence.',
            null => '',
        };
    }

    /**
     * What act 1 is told about its own first thirty seconds.
     *
     * -----------------------------------------------------------------------
     * THE MEASUREMENT THIS REPLACES
     * -----------------------------------------------------------------------
     *
     * The old instruction was four sentences: open in the middle of the
     * grievance, state what was taken, do not open with scene-setting. It is
     * not wrong and it did not work. Both shipped stories open on context —
     * story 12 on a pot boiled black on a stove in March 2020, story 21 on the
     * square meterage of an apartment — and neither states its betrayal inside
     * forty scenes.
     *
     * **What the measurement actually found is that the beats are all there
     * and all late.** Story 21 has the best cold action in the database, *I
     * said, "Have a good trip. I'll take you to the airport."*, at 3:09. Story
     * 12 opens a spreadsheet and names it MOM EXPENSES 2020 at 3:24. Neither
     * writer failed at anything; neither was told where the opening starts, and
     * the chronological beginning is what a writer produces by default, because
     * context is what comes first in time.
     *
     * So this does not ask for better writing. It names five beats and the
     * order they go in, and it hands over the hook the outline already wrote,
     * so act 1 is executing a decision made at Gate 1 rather than making one.
     *
     * -----------------------------------------------------------------------
     * COPYING, NOT MOVING
     * -----------------------------------------------------------------------
     *
     * Beat 3 draws on the antagonist's justification and DOES NOT CONSUME IT.
     * In this genre the same line lands twice — once here as one quoted
     * sentence of bait, once in the betrayal scene straight after the hook,
     * said aloud in the room — and the second landing is stronger for the
     * first. A generator told to "use it in the hook" spends it and leaves the
     * act paraphrasing itself, so the instruction says so in both directions.
     *
     * -----------------------------------------------------------------------
     * CHAPTER ONE IS THE BETRAYAL SCENE
     * -----------------------------------------------------------------------
     *
     * This block used to send the second landing to "act 2 or 3", and stories
     * 29-32 obeyed exactly: one line in the hook, the first public saying at a
     * banquet at 9-11 minutes, the betrayal itself at a kitchen table. The
     * reference stages it at 1:31, directly after its 62-second cold open. So
     * `stories.betrayal_scene` is handed to act 1 here, inside the block that
     * owns act 1's opening order, rather than as a fifth block that would be
     * one more instruction for the other three to collide with. It follows
     * the chapter announcement and the re-hook, and the answer-back is back in
     * force inside it — which is what beat 4 now says from the other side:
     * a round the narrator loses is not the reckoning.
     *
     * -----------------------------------------------------------------------
     * THE WORD BUDGET
     * -----------------------------------------------------------------------
     *
     * `ScriptSizing::hookBetrayalWords()` converts the twenty seconds at THIS
     * STORY'S frozen sizing rate — the same rate `$targetWords` came from. A
     * second rate in this prompt would be two beliefs about one narration in
     * one string.
     *
     * A story outlined before `stories.hook` existed has none, and gets the
     * beats without the outline's own answer to them rather than a blank where
     * one should be. Four stories are in that position and it is the same
     * legacy case Gate 1 reports once.
     */
    private function hookInstruction(Story $story): string
    {
        $hook = trim((string) $story->hook);
        $words = ScriptSizing::hookBetrayalWords($story);

        // ------------------------------------------------------------------
        // THE SUSPENSION LIST AND THE RESTORATION LIST HAVE TO NAME THE SAME
        // RULES, AND FOR A PHASE THEY DID NOT
        // ------------------------------------------------------------------
        //
        // This block suspends four rules for the length of the five beats and
        // then restores them. The restoring sentence used to read "every other
        // rule in this prompt is back in force, THE ANSWER-BACK INCLUDED" — it
        // named one of the four. The chapter bullet said "NOT THIS ONE", where
        // "one" can be read as the chapter or as the act.
        //
        // Measured on the four stories written since that clause went in
        // (2026-09-14): stories 36 and 37 announce "Chapter one." and then
        // "Chapter four.", with act 1's chapters 2 and 3 stored, titled, cut
        // and silent; stories 33 and 38 announce all sixteen. Two of four, on
        // a coin-flip reading of one word, in published video.
        //
        // `chapterInstruction()` already delegated act 1's opening order here
        // — "the OPENING block below sets its order" — so the delegation was
        // pointing at a block that answered for chapter one and nothing after
        // it. Both lists name every rule now, and the chapter bullet says the
        // exception is one chapter long rather than one act long.
        //
        // This is the third defect the act-1 collision has produced and the
        // first in the RESTORATION half; the other two were in the suspension
        // half. The standing question stays the one already written down for
        // "every" and "always" — and gains a second half: when a block
        // suspends a rule, the sentence that gives it back names it.
        $beats = sprintf(
            'THE OPENING. THIS ACT OPENS THE VIDEO, and its opening is the highest-leverage text '
            .'in the whole script. Other instructions in this prompt touch it and THIS BLOCK '
            .'OUTRANKS ALL OF THEM for as long as the five beats last — and for exactly that '
            ."long, after which each of them is back:\n\n"
            .'- The accomplice talks in his act, and the narrator\'s running thought is planted. NOT '
            .'HERE, where the spine has either. Both start in chapter one, after the chapter number.'
            ."\n"
            .'- The narrator answers back in every scene the antagonist is in. NOT HERE. Beat 4 is '
            .'the narrator\'s move in the opening and it is a cold action, not a line — the '
            .'answer-back begins at the first scene AFTER the beats.'
            ."\n"
            .'- Every chapter opens by speaking its number. NOT THIS CHAPTER, AND ONLY THIS '
            .'CHAPTER. The hook is the cold open and comes first; "Chapter one." is spoken after '
            .'beat 5, where the story proper begins. EVERY LATER CHAPTER IN THIS ACT SPEAKS ITS '
            .'NUMBER NORMALLY, at its own opening — chapter two, chapter three, and any after '
            .'them. The exception is one chapter long, not one act long.'
            ."\n"
            .'- Every chapter opens with a re-hook. The five beats ARE this chapter\'s opening; its '
            ."re-hook line is the first sentence after the chapter number.\n\n"
            ."Five beats, in this order, before anything else happens:\n\n"
            ."1. ONE sentence of setup. One. Do not write a second, and never write a sentence "
            ."about the video itself.\n"
            .'2. The betrayal itself, inside the first %s words. Not its aftermath, not a summary '
            .'of how it turned out, not a line about what the family said afterwards — the thing '
            ."that was done, being done, in the room it happened in.\n"
            .'3. Evidence in EXACT WORDS: one line of dialogue, a message or a document, quoted. '
            .'The strongest version is the antagonist\'s own justification, said in her words. '
            .'THE BETRAYAL SCENE KEEPS IT — this genre plays that line twice, once here as bait in '
            .'a single sentence and again straight after the hook, in chapter one, said aloud in '
            .'the room with everyone watching, and the second landing is stronger for the first. '
            ."Quote it here; do not exhaust it here.\n"
            .'4. ONE small, cold action by the narrator. Not a confrontation, not a speech, not a '
            .'threat: something quiet and exact that the audience understands and the antagonist '
            .'does not. The RECKONING is the final act and spending it here spends the video. A '
            .'round the narrator loses is not the reckoning — chapter one is one, and the narrator '
            ."answers back in it.\n"
            .'5. A closing line that promises the DEPARTURE — that the narrator will be gone and '
            .'somebody will have to look for them. NOT revenge, NOT the courtroom, NOT the '
            ."exposure. Those are the payoff and this is the promise, and they are not the same.\n\n"
            .'Do not open with weather, a childhood memory, a house, a room, a date, or the '
            .'chronological beginning of events. The beginning in time is almost never the '
            ."beginning of the video.\n\n"
            .'THEN, AND ONLY THEN, THE VIDEO STARTS: %s as its own sentence, then this chapter\'s '
            .'re-hook line, then the act. FROM THAT SENTENCE ONWARD EVERY RULE SUSPENDED ABOVE IS '
            .'BACK IN FORCE, ALL OF THEM: the answer-back, the accomplice\'s act, the running '
            .'thought, AND THE SPOKEN CHAPTER NUMBER — so this act\'s second chapter opens '
            .'"Chapter two.", its third opens "Chapter three.", and so on to the end of the act.',
            number_format($words),
            ChapterAnnouncement::enabled()
                ? '"'.ChapterAnnouncement::sentenceFor(1).'"'
                : 'the act proper',
        );

        // Chapter one, when the outline wrote one. Between the beats and the
        // stored hook, so the hook keeps the closing position story 30 showed
        // it needs. Absent on every story outlined before the field existed,
        // which gets exactly the block it had.
        $betrayal = trim((string) $story->betrayal_scene);

        if ($betrayal !== '') {
            $beats .= "\n\n".'CHAPTER ONE IS THE BETRAYAL SCENE. Straight after the chapter number and '
                .'its re-hook, write this scene in full, in the room, as it happens. It is where the '
                .'antagonist\'s justification is FIRST SAID ALOUD, to the narrator\'s face, in front of '
                ."everyone in it:\n\n".$betrayal."\n\n"
                // "They do not need a line" was here, and it became a motif:
                // story 33's summaries carried "has not spoken a single quoted
                // word" from act to act as a fact. The first reference's man
                // was silent because he had no stake. CLAUDE.md 3g.
                .(trim((string) $story->accomplice_performance) === ''
                    ? 'The person it is done with is in the room for all of it. '
                    : 'The person it is done with is in the room for all of it, AND HE TALKS — in the act '
                        .'he puts on for her, from the spine: reasonable, apologetic, offering to take the '
                        .'blame, so that she defends him in front of everyone, and she does. The narrator '
                        .'sees through it in their head, tagged as a thought, and does not say so aloud. ')
                .'Put the witnesses in it — named only if they are in the cast — and give one of them '
                .'the question that makes her say it. She '
                .'says the justification in her own words from the spine, not a paraphrase of it. '
                .'The answer-back is back in force here: what the narrator SAYS is short, controlled '
                .'and exact, and the funny version stays in their head, tagged as a thought. '
                .(trim((string) $story->running_thought) === ''
                    ? ''
                    : 'PLANT THE RUNNING THOUGHT IN THIS CHAPTER, in the narrator\'s head and tagged as a '
                        .'thought — after the five beats, never inside them: '.trim((string) $story->running_thought).' ')
                .'The narrator '
                .'still loses the round. Do not cut to a later day and do not summarise what was '
                .'said: stage it in full, through her saying it and the narrator losing the round. '
                // "The scene is the chapter" closed this block on story 33's
                // first act-1 call, and the writer returned ONE chapter of 479
                // words that stopped before the antagonist entered the room —
                // 1,449 output tokens against 3,400-7,700 on every act of 31
                // and 32 — with a summary calling the act "the chapter". One
                // observation, and the sentence plainly allowed that reading:
                // it said the act was one chapter, beside a block saying 2-4.
                .'THE SCENE IS CHAPTER ONE, NOT THE WHOLE ACT. When it ends, the act goes on into its '
                .'further chapters, at its full length, as the chapter instructions above say.';
        }

        // -------------------------------------------------------------------
        // THE STORED HOOK GOES LAST, AND IT IS AN ORDER RATHER THAN A NOTE
        // -------------------------------------------------------------------
        //
        // `stories.hook` is the paragraph the outline wrote to answer the five
        // beats, and story 29's act 1 opened on it verbatim. Story 30's did
        // not: it opened mid-scene on the betrayal and invented its own third
        // and fourth beats. The text was in the prompt both times, in the same
        // place — trailing the beats as "the hook this outline asks for",
        // which reads as a reference rather than as the thing to write.
        //
        // So it closes the block, in the position the answer-back and the
        // chapter announcement used to occupy, and it says what to do with it.
        return $hook === ''
            ? $beats
            : $beats."\n\nTHIS IS THE OPENING THE OUTLINE WROTE FOR THIS STORY. WRITE IT — expand "
                ."it into the five beats above, keeping its facts, its quoted line and its closing "
                ."promise. Do not replace it with an opening of your own:\n\n".$hook;
    }
    /**
     * What this act has to do with its ending, decided by its PHASE.
     *
     * This branched on "is this the last act" for two phases, and that is the
     * defect the reversal was added to fix. Every non-final act was told to end
     * worse off than it started, which is right for an escalation act and the
     * precise opposite of what a search act needs — there the narrator is
     * already gone and the ground is being lost by the ANTAGONIST. A sequence
     * number cannot express that, so the phase is carried on the act.
     *
     * `$isLast` survives as the fallback for an outline with no phases: an
     * anthology, where each act is a self-contained story running the whole arc
     * itself, and the stories outlined before the reversal existed, whose acts
     * are still re-writable one at a time. Neither should be handed a phase
     * instruction that its outline was never built for.
     */
    private function endingFor(Story $story, ActOutline $act, bool $isLast): string
    {
        return match ($act->phase) {
            ActPhase::Escalation => 'This act is in the ESCALATION phase. '
                .$this->withheldInformationHere()
                .' The narrator does not leave here. '
                .'THE NARRATOR TRIES SOMETHING IN THIS ACT AND IT FAILS: the beat above says what. '
                .'Stage the attempt — they ask for the document, correct the number in front of '
                .'the room, make the call, put the receipt on the table, decline the thing they '
                .'are being told to sign — and then stage it failing, because the room, the family '
                .'or the institution overrides it. NOT because they thought better of it and not '
                .'off the page: an attempt the audience does not watch fail is a narrator who did '
                .'nothing. Nothing is recovered: no cost comes back, no real apology arrives, no '
                .'ally fixes anything, and the act ends worse off for the narrator than it started '
                .'— worse off BECAUSE the attempt failed. AND THE NARRATOR ANSWERS BACK. In '
                .'every scene the antagonist is in, the narrator says one short, exact, funny line '
                .'that lands — not a speech, not "all right", not "Yes, Mother". The line changes '
                .'nothing about what the act costs; that is the point. The exchange is won and the '
                .'round is lost.'
                .$this->accompliceWinsHere($story)
                // The one place this rule does not reach, said here as well as
                // in the block that owns it. Story 30's act 1 spent beat 4 —
                // the cold action — on an answer-back, because this sentence
                // said "every scene" and arrived after the beats did.
                .($act->sequence === 1
                    ? ' THE EXCEPTION IS THE OPENING OF THIS ACT: inside the five beats of the hook '
                        .'the narrator does not answer back, because beat 4 is a cold action and a '
                        .'confrontation there spends the video. The answer-back starts at the first '
                        .'scene after the hook and runs to the end of the act.'
                        // The same exception for the accomplice's act and the
                        // running thought, both new in 3g: each says where it
                        // starts, and neither adds a line to the beats.
                        .(trim((string) $story->accomplice_performance) === '' && trim((string) $story->running_thought) === ''
                            ? ''
                            : ' The accomplice\'s act and the running thought start there too, in chapter '
                                .'one — neither adds anything to the five beats.')
                    : ''),

            ActPhase::Departure => "THIS IS THE DEPARTURE ACT. The narrator goes:\n\n"
                .$story->departure."\n\n"
                .'It is still an escalation act until they leave — this is the worst it gets, and it '
                .'is what makes staying impossible — and it is the LAST attempt, the one that had '
                .'the best chance and fails worst. Stage it failing. Then they go. THEY DO NOT '
                .'ANNOUNCE IT: no ultimatum, no farewell speech, no note, no final phone call. They '
                .'are simply not there any more, and the antagonist has not worked that out yet '
                .'when the act ends. '
                .$this->withheldInformationHere()
                .' Do not explain where they went — the audience may know, the antagonist must not.'
                .$this->accompliceWinsHere($story)
                .$this->accompliceFallFor($story, ActPhase::Departure),

            ActPhase::Search => 'This act is in the SEARCH phase. The narrator is gone, and the '
                ."antagonist is looking for them:\n\n"
                .$story->reversal_beats."\n\n"
                .'The direction of the escalation has reversed. Everything in this act costs the '
                .'ANTAGONIST — money, standing, the people who found her excuse reasonable — and it '
                .'costs her more than the last attempt did. PUT THEM IN THE SAME SCENE TWICE IN THIS '
                .'ACT — TWO SEPARATE MEETINGS, BOTH STAGED: she runs into them, follows them, sits '
                .'down across from them, turns up where they are — on her initiative or by chance, in '
                .'front of people — and the second costs her more than the first. Each one is a scene '
                .'as it happens, in a room, with what they said to each other in quotation marks. '
                .'Not "she found me at the fair and I told her to go home": that is the meeting '
                .'reported instead of played, and it is the whole act\'s worth of drama spent in one '
                .'sentence. The narrator answers in one short, exact, funny line '
                .'and leaves. They do not go back, do not explain, do not search for her, do not send '
                .'a message. The narrator\'s own life is ON SCREEN in this act, not a paragraph: what '
                .'they are doing, who they are with, what the days look like, WITH SOMEBODY ELSE IN '
                .'THE ROOM SAYING SOMETHING — because a narrator the '
                .'audience cannot see is a narrator the antagonist is not losing to. She may learn '
                .'where they are; if she does, the finding costs her and the scene is still the '
                .'narrator\'s. End the act with her worse off than she started it. '
                .$this->withheldInformationHere()
                .$this->accompliceFallFor($story, ActPhase::Search),

            ActPhase::Refusal => 'THIS IS THE FINAL ACT. Both payoffs land here, in this '
                ."order.\n\nFIRST, the exposure — the public one:\n\n"
                .$story->exposure_moment."\n\n"
                .'THE WITHHELD INFORMATION COMES OUT TO THE ANTAGONIST AND THE ROOM HERE, AND '
                .'NOWHERE EARLIER. It is not news to the audience and must not be written as if it '
                .'were: they have known what it is and what it can do since act 1, which is the '
                .'only reason they have spent forty minutes waiting for this. What lands here is '
                .'HER face when she learns it, not the fact. Do not stage a revelation to the '
                .'viewer, do not have the narrator explain the rule for the first time, and do not '
                .'let a character summarise what the audience already knows for their benefit. '
                .$this->witnessInstruction($story).' The antagonist repeats their justification in front of '
                ."people who now know it is false.\n\n"
                .'THE NARRATOR IS IN THE ROOM FOR IT, by their own choice, and the audience is there '
                ."with them — this is a scene the narrator lives, not a report they receive later:\n\n"
                .(trim((string) $story->narrator_at_exposure) !== ''
                    ? $story->narrator_at_exposure
                    : 'They arrive unexpected and uninvited, having chosen this moment, and they '
                        .'produce the withheld information themselves.')
                ."\n\n"
                .'Whether they chose the moment or she came to where they are, the narrator is in '
                .'the room and puts the thing only they can produce on the table in person. Do not '
                .'have a document, a lawyer or a friend do it while the narrator is eight hundred '
                .'kilometers away hearing about it afterwards; story 25\'s narrator raises his hand '
                ."at the back of the room in a work jacket, and that is the shape."
                .$this->accompliceFallFor($story, ActPhase::Refusal)
                ."\n\nTHEN the refusal — the private one, and the thing the audience has waited the "
                ."whole video for:\n\n"
                .$story->refusal."\n\n"
                .'She reaches them afterwards. She asks them to come back. The narrator decides where '
                .'and how long they talk, says no, and each refusal answers ONE specific earlier '
                .'humiliation in the words it was done in — hand her own sentence back to her, and the '
                .'sentence she said aloud in the betrayal scene is the strongest one to hand back. The '
                .'strongest refusal names a loss she can no longer repair. The narrator does not '
                .'retaliate, gloat, or explain the moral. The last refusal lands and the narrator walks '
                .'away.'
                .(trim((string) $story->running_thought) === ''
                    ? ''
                    : ' ONE OF THE REFUSALS PAYS OFF THE RUNNING THOUGHT. The narrator has thought it all '
                        .'video and never said it: here they say it out loud, once, in its own specific words '
                        .'— frank, not crude, and not a gloat. The one moment the head and the mouth agree: '
                        .trim((string) $story->running_thought))
                .$this->closingFor($story),

            // No phase: an anthology act, or an outline written before the
            // reversal phase existed. The old shape, unchanged, because that is
            // the shape its outline was built to.
            null => $isLast
                ? "THIS IS THE FINAL ACT. It contains the exposure:\n\n"
                    .$story->exposure_moment."\n\n"
                    .'The withheld information comes out here and nowhere earlier. '
                    .$this->witnessInstruction($story).' The antagonist repeats their justification in front '
                    .'of people who now know it is false — that is the moment the video exists for. '
                    .'The narrator does not retaliate, gloat, or explain the moral. They state the '
                    .'fact, and the room reacts. End within a few sentences of the reveal landing: no '
                    .'epilogue about what everyone learned, no ambiguity, no "I still think about it '
                    .'sometimes".'
                : 'This is NOT the final act. The withheld information does not come out here. '
                    .'Nothing is resolved: the narrator does not win a round, get a real apology, or '
                    .'find an ally who fixes anything. End the act worse off than it started.',
        };
    }

    /**
     * The last minutes of the video: ONE ending, the one the operator chose.
     *
     * Until 2026-09-19 the refusal act was asked for an optional narrator
     * epilogue AND, whenever `antagonist_regret` was written, her chapter
     * after it. Every epilogue came back as the same list — a headcount and
     * square meters, her decline in three facts, one object sent back — and
     * story 37's two endings restated each other's year. Now the ending is
     * exclusive, and each says what it does not carry. See StoryEnding.
     *
     * A story with NO ending was outlined before the choice existed. It gets
     * the new-life scene (the epilogue's content fix reaches it too) and, if
     * its outline wrote a regret, her chapter after it — the stacked shape it
     * was outlined for. Only story 37 is in that position, and it is published.
     */
    private function closingFor(Story $story): string
    {
        if ($story->ending === StoryEnding::AntagonistVoice) {
            return "\n\nTHE ENDING IS THE ANTAGONIST'S, chosen for this story. The narrator's own story ends "
                .'with the walk away from the last refusal. '.StoryEnding::AntagonistVoice->doesNotCarry()
                .$this->pointOfViewChapterFor($story);
        }

        $closing = "\n\n".$this->newLifeEnding($story);

        if ($story->ending === StoryEnding::NewLife) {
            return $closing.' No chapter is told from anybody else\'s point of view.';
        }

        return $closing.$this->pointOfViewChapterFor($story);
    }

    /**
     * The narrator's new life, as one scene — and the cast decides whether a
     * partner is in it.
     *
     * NO NUMBERS, stated as the move and not as a list of phrases: the old
     * epilogue asked for "what the narrator's life is now", and every writer
     * answered with an audit. A scene with people in it cannot be an audit.
     */
    private function newLifeEnding(Story $story): string
    {
        $partner = OutlineCast::futurePartner($story->outline_cast);
        $state = PartnerEnding::stateFor($story);

        return 'THEN THE ENDING: THE NARRATOR\'S NEW LIFE. It is the last chapter of this act and of the '
            .'video — a chapter of its own, numbered and announced like the others — about a year after '
            .'the refusal. ONE SCENE, NOT A SUMMARY OF THE YEAR: one afternoon or one evening, in one '
            .'place, with people in it, told as it happens, with at least one exchange in their own words. '
            .'What the narrator\'s life is now is shown by what is in that room — who is there, what they '
            .'are doing — and never listed. NO NUMBERS: no headcount, no floor area, no salary, no '
            .'contract value, no "a year later my studio had N people". Every ending this channel wrote '
            .'before was that list, and it made every video end the same way. No moral, no account of '
            .'what anyone learned, and no "I still think about it sometimes". '
            .($partner !== null
                // "A COUPLE NOW" is the sentence story 38 did not have. It was
                // told the partner was in the scene and asked for "something
                // they are doing together", and wrote a boss sliding a folder
                // under an employee's elbow at a staff dinner. Nothing asked
                // what they are to each other, so nothing said it. Not checked:
                // the operator reads this chapter at Gate 1 (CLAUDE.md 3f).
                //
                // AND IT WAS STILL THE WEAKEST THING THIS SCENE COULD SAY.
                // Story 39's idea asked for a marriage and this sentence could
                // only ask for a couple, so a couple is what came back. The
                // chosen state is named here in its own words; with none
                // chosen the four are named, so the writer at least knows the
                // choice exists. See App\Enums\PartnerEndState.
                ? sprintf(
                    '%s IS IN THE SCENE, ON SCREEN, AND THIS IS WHAT THE TWO OF THEM ARE TO EACH OTHER NOW: '
                    .'%s %s arrived as %s; '
                    .'a year on the scene makes that plain — once in the narration, '
                    .'said the way a person says it about their own life, and in what they do: something the '
                    .'two of them are doing together, and one exchange between them in their own words that '
                    .'only two people who are together would have. Never a manager and an employee, never '
                    .'colleagues at a work event, never a friend who helps: if the scene could be read as '
                    .'work, it is the wrong scene. If the story has not yet shown how they came into the '
                    .'narrator\'s life, one sentence here may say it, and no more. ',
                    $partner->name,
                    $state !== null
                        ? $state->instruction()
                        : 'MARRIED, ENGAGED, LIVING TOGETHER, OR TOGETHER AND NOTHING FURTHER — say which, in '
                            .'its own words. "A couple", "together" and "my partner" are true of all four and '
                            .'say the least of any of them; do not settle for one because it is safe.',
                    $partner->name,
                    $partner->relationship !== '' ? $partner->relationship : 'someone already in the narrator\'s life',
                )
                : 'THERE IS NO PARTNER IN THIS STORY, AND THE NARRATOR IS ALONE AND FINE: not lonely and '
                    .'not waiting for anyone. The scene has the narrator\'s own people in it — friends, family, '
                    .'the people they work with — and nobody in it treats being single as a gap to be closed. '
                    .'Do not start a romance here. ')
            .StoryEnding::NewLife->doesNotCarry();
    }

    /**
     * The antagonist's own chapter, closing the refusal act.
     *
     * Empty on a story with no antagonist_regret, which gets exactly the
     * sentence it had: "no chapter told from anybody else's point of view".
     * That sentence was right for a single-narrator story and is kept for
     * them; what changed is that the outline can now plan a reveal worth her
     * chapter, and the reference's own ending is hers (CLAUDE.md 3d, 32:20).
     *
     * ABOUT A YEAR, NOT TWENTY: the operator's decision. Her face is drawn
     * from one reference sheet at her age in the story and ageing texture is
     * refused, so a jump of decades could only be shown through objects.
     *
     * ONE VOICE reads it. The spoken announcement is the only marker that the
     * "I" has changed hands, which is why it names her.
     */
    private function pointOfViewChapterFor(Story $story): string
    {
        $name = AntagonistPointOfView::nameFor($story);

        if ($name === null) {
            return ' No chapter is told from anybody else\'s point of view.';
        }

        return "\n\nTHEN, LAST, THE ANTAGONIST'S CHAPTER. The final chapter of this act — and of the "
            ."video — is told by {$name}, in {$name}'s own first person: inside it, \"I\" is {$name}, not the "
            .'narrator. It is the last thing the viewer hears. It is set '
            ."ABOUT A YEAR after the refusal — not ten or twenty years: {$name} looks as they do in "
            .'this story, so the year shows in the life, not the face. It opens by announcing whose it '
            .'is, as its own sentence: "'.ChapterAnnouncement::pointOfViewSentence($name).'" It has no '
            ."chapter number. Then it reveals what the narrator never saw:\n\n"
            .trim((string) $story->antagonist_regret)."\n\n"
            ."Tell the chance as a scene {$name} remembers — who offered it, the words they used, what "
            ."{$name} said back — and then the year, in things that can be seen. No apology to make, no "
            .'reunion, no moral, no hope. End it on the loss, in one concrete sentence. '
            .sprintf(
                'Mark it in the chapters list: its point_of_view is exactly "%s". Every other chapter\'s '
                .'point_of_view is an empty string. It does not count toward this act\'s chapter number '
                .'or its chapter count, and it is at least %d words.',
                $name,
                (int) config('chapters.min_words', 150),
            );
    }

    /**
     * What "the withheld information does not come out here" means, and to
     * WHOM it does not come out.
     *
     * -----------------------------------------------------------------------
     * THE SPLIT THIS CONTRACT ALREADY DRAWS TWICE AND NEVER DREW HERE
     * -----------------------------------------------------------------------
     *
     * `endingFor(Departure)` says, of where the narrator went: "the audience
     * may know, the antagonist must not". Hook beat 4 says the cold action is
     * "something quiet and exact that the audience understands and the
     * antagonist does not". Both are the same distinction, drawn correctly,
     * one paragraph from the place it matters most — and for the withheld
     * information three phases said only "does not come out here" and the
     * refusal said "comes out here and nowhere earlier". "Comes out" reads as
     * "is revealed", and a writer told a fact is revealed in the last act
     * keeps it from the viewer for the whole video.
     *
     * Measured on the four published stories, in rendered time: the card's
     * CONTENT — what it is and what it can do — reaches the viewer at 14:42 of
     * 40:18 on story 33 (36%), 19:46 of 38:30 on story 37 (51%), 29:26 of
     * 39:15 on story 36 (75%) and 29:37 of 39:13 on story 38 (75%). Story 36
     * shows the same pink envelope five times across twenty-two minutes and
     * never says what it can do; story 38's narrator holds a veto for
     * thirty-three minutes and the reason he is not using it arrives as a
     * line of dialogue at the exposure.
     *
     * That is half of "kept giving for no reason": a viewer cannot price a
     * concession against a card they cannot read. It is also, on the same
     * evidence, half of "too confusing".
     *
     * The rule is unchanged in the only direction it was ever about — the
     * ANTAGONIST learns nothing before the final act. What is new is that the
     * AUDIENCE is told, early, in plain words, and the last act is written as
     * her finding out rather than as the viewer finding out.
     *
     * NOT WIRED INTO THE PHASELESS BRANCH, deliberately: that branch is an
     * anthology act or an outline written before the reversal existed, and it
     * is kept as the shape its outline was built to. No story in the pipeline
     * is phaseless.
     */
    private function withheldInformationHere(): string
    {
        return 'THE WITHHELD INFORMATION DOES NOT COME OUT TO THE ANTAGONIST HERE — AND THE '
            .'AUDIENCE ALREADY HAS IT. Those are two different rules and only the first one is '
            .'about secrecy. By the end of act 1 the viewer knows exactly what the narrator holds, '
            .'what it can do, and why they are not using it yet; from then on every scene is the '
            .'viewer watching a man with a card in his pocket decide not to play it, which is the '
            .'tension this format runs on. A viewer who does not know what the card is cannot '
            .'price anything the narrator gives up, and reads him as a man giving things away for '
            .'no reason. So: name the thing plainly, in the narrator\'s own words, the first time '
            .'it is on screen — not "I checked one line" or "a document I kept", but what the line '
            .'says, what it lets the narrator do that nobody else can, and what the narrator would '
            .'lose by saying it now. Then the antagonist goes on not knowing, and nobody in the '
            .'story says it aloud until the final act.';
    }

    /**
     * The accomplice before the narrator leaves: he talks, and he wins.
     *
     * Empty on a story whose cast has no accomplice, and on every story
     * outlined before the field existed — which gets exactly the ending it had.
     */
    private function accompliceWinsHere(Story $story): string
    {
        if (trim((string) $story->accomplice_performance) === '') {
            return '';
        }

        return ' THE ACCOMPLICE IS IN THIS ACT AND HE TALKS, in the act he puts on for her. She takes '
            .'his side and he wins the round with her. The narrator sees through the act IN THEIR HEAD, '
            .'tagged as a thought, and says nothing about it aloud. His motive does not come out here '
            .'and he does not lose here.';
    }

    /**
     * The accomplice's fall, handed only to the acts it happens in.
     *
     * The departure, search and refusal acts — the last three. An escalation
     * act is never handed it, because a writer told in act 2 how he ends
     * spends it in act 2, and that is the narrator winning early: the shape
     * the arc decision in CLAUDE.md 3g rules out.
     */
    private function accompliceFallFor(Story $story, ActPhase $phase): string
    {
        $fall = trim((string) $story->accomplice_fall);

        if ($fall === '') {
            return '';
        }

        return "\n\n".match ($phase) {
            ActPhase::Departure => 'THE ACCOMPLICE\'S FALL MAY BEGIN IN THIS ACT, and only after the narrator '
                ."has gone — never as the narrator's doing. The whole of it, for context:\n\n".$fall,
            ActPhase::Search => 'THE ACCOMPLICE IS LOSING TOO. Stage the part of his fall that belongs to '
                .'this act, in front of people, in a scene the narrator is in: his act stops working, '
                .'on her or on the room, and it costs him more than the last time. He talks; the narrator '
                ."answers in one line. The whole of his fall, for context:\n\n".$fall,
            default => 'THE ACCOMPLICE\'S FALL COMPLETES HERE, in the room, in front of people. His motive '
                .'comes out in front of her if it has not already, and the act goes with it. Where he '
                ."ends up is worse than where she does, and may be the epilogue's fact:\n\n".$fall,
        };
    }

    // -- Character extraction --------------------------------------------------

    private function characterSystemPrompt(Story $story): string
    {
        // Filtered, because castAgeBlock() returns an empty string for a story
        // that states no age range, and an unfiltered implode would open that
        // section with a blank gap where an instruction used to be.
        return implode("\n\n", array_filter([
            <<<'TEXT'
            You write character sheets for an ANIME-STYLE illustrated video. Each description
            you write will be pasted, WORD FOR WORD AND UNCHANGED, into 150-250 separate
            image generation prompts across a 35-minute video.

            THE ONE RULE THAT DECIDES EVERYTHING ELSE: write SILHOUETTE, not TEXTURE.

            Most of these frames are mid shots and wide shots, and this is an anime style.
            Fine surface detail does not survive either. "Faint smile lines at the corners
            of her eyes" and "a light spray of freckles" are real observations that render
            as nothing at conversational distance, so a character built out of them is a
            character who is identifiable in close-up and anonymous everywhere else. That
            was measured, not guessed: a woman described that way read as mid-forties in a
            close portrait and mid-thirties in a two-person kitchen shot, from the same
            description, in the same run.

            So every character must be identifiable from their HAIR AND HEAD SHAPE before a
            single feature is read.

            DO NOT DESCRIBE BUILD, HEIGHT OR FRAME. Not "broad-shouldered", not "stocky",
            not "small and frail", not "tall". This was measured and it is not a matter of
            taste: the illustration style draws every body toward one idealised frame, so a
            character written "broad and thick through the chest" is drawn lean and one
            written "small and frail with stooped shoulders" is drawn upright. A style
            instruction saying stated build is preserved exactly was tried and changed
            nothing. Words spent on build are words the renderer discards, and they read as
            coverage that is not there — so the room goes to hair and face, which do render.

            - HAIR IS THE PRIMARY IDENTIFIER, and it must differ in SHAPE across the cast,
              not merely in color and length. Three women all described as "shoulder-length"
              plus a color will collapse into one another at any distance.

              Default to LONGER, STYLED hair. This look is long hair with visible styling,
              not short and practical, so reach first for: long and straight with a centre
              part, long with a high ponytail, a long braid over one shoulder, waist-length
              with heavy volume, half-up with the rest loose, a low twisted knot with strands
              down, long and layered with a deep side part. A bob, a crop or a tight bun is
              a deliberate choice for one character, not the default for the cast.

              THAT MAKES DISTINCTNESS HARDER, NOT EASIER, and it is the reason the check at
              the bottom of these instructions is written the way it is. Three women with
              long hair converge fast. Length alone stops separating them, so each one must
              differ on the axes that still read at distance:
                * where the mass sits — down the back, over one shoulder, piled high, at the
                  nape, swinging free at the jaw
                * up or down — and if up, how high and how tight
                * the parting — centre, deep side, none, severe
                * volume and texture in outline — flat and sleek, heavy and wavy, tight
                  curls with width at the sides
              Say what shape the hair makes, not just how long it is.
            - AGE MUST READ IN THE HEAD. Three places carry it and they are the three that
              survive a wide shot: hairline (receding, thinning, widow's peak), hair color
              (steel gray, white, salt-and-pepper, still dark) and face shape (gaunt,
              jowled, softly rounded, angular, heavy-browed). State the decade explicitly as
              well. Do not reach for the body: a stooped, narrow frame reads as age to a
              reader and is drawn as an upright one, so it buys nothing.
            - NEVER WRITE AGEING TEXTURE. Not "deeply lined", not "sagging", not
              "weathered", not creased, furrowed, leathery, crepey or liver-spotted —
              and the ban is on the WORD, not on one phrase it appears in. "Weathered
              skin" and "weathered square jaw" are the same instruction to a generator;
              the second one was written after the first was banned. This is not a
              stylistic preference: this is drawn in an anime style, where the only thing a
              generator has for photoreal ageing is to draw it literally, and it will. A
              woman written as "late sixties, deeply lined round face, soft sagging
              jawline" came back at eighty-five with the lines and the spots drawn on, in
              every frame she appeared in. Every one of those words has a structural
              replacement that reads at distance and none of them do — say what shape the
              face is, not what the skin has been through.
            - THEN the fixed features, all of them above the collar: face shape, eye shape
              and color, eyebrow shape, facial hair, glasses shape, and any single strong
              distinguishing mark. Eye SHAPE matters here more than eye color — an anime
              style draws eye shape distinctly and reads color as an afterthought.
            - Habitual clothing goes in style_notes, not in the description: what someone
              usually wears is stable, but it is not their face.
            - NO HATS unless the script actually requires one. A hat is clothing, so nothing
              else in these rules stops you writing one — but it covers the hair, and hair
              is the thing this cast is told apart by. A cast in caps is a cast with one
              silhouette. If the script gives someone a hat for a reason, keep it; if you
              are reaching for one to make a character feel ordinary, do not.
            - style_notes is CLOTHING ONLY. Never props, never anything held or carried,
              never anything the word "often" or "sometimes" would apply to. style_notes is
              pasted into every one of that character's prompts, so "often holding a
              handheld microphone" puts a microphone in all 36 scenes they appear in,
              including the ones in a parking lot four acts before the speech, and "and a
              wooden cane" puts a cane in the hand of a man sitting at a kitchen table. If
              they carry something in a particular scene, that scene's frame will say so.
            - NEVER anything that changes between scenes. No mood, no expression, no
              posture, no gait, no action, no location, no lighting, no camera angle. Those
              belong to the individual frame and will be written separately for each one.
              "Walks with a stiffness in one hip" asks for a man mid-stride in every frame
              he is in, including the ones where he is sitting down.
            - NEVER a hedge. No "usually", "often", "typically", "sometimes". These fields
              are applied unconditionally, so "hair usually pulled back" means hair pulled
              back in all 92 of her scenes — write the one state you want drawn every time.
            - NEVER anything a picture cannot show. Not their job history, not their
              motives, not how the narrator feels about them.
            - One flowing description, 25-45 words. No lists, no bullet points, no labels.

            USABLE — hair shaped, age in the hairline and the face, nothing below the collar:
              "Man in his late sixties, deeply receding white hairline swept back from a long
              gaunt face, heavy gray eyebrows, deep-set narrow eyes, square rimless glasses."

            USABLE — a woman, hair carrying the identification:
              "Woman in her early thirties, long black hair in a high tight ponytail with a
              deep side part, heart-shaped face, wide round eyes, fine arched brows."

            NOT USABLE — build doing work the renderer discards:
              "Mid-forties, heavy through the shoulders and thick-waisted, short greying hair
              receding at the temples, square jaw, deep-set brown eyes."

            NOT USABLE — texture doing the work, and it disappears past close-up:
              "Mid-forties, short greying brown hair receding at the temples, deeply lined
              brow, crow's feet, two-day stubble."

            NOT USABLE — vague; renders differently every time:
              "Tired-looking, worn down by life."

            Before you answer, read your own cast back AS A GROUP and check every pair of
            characters against each other. Two of them may both have long hair only if the
            SHAPES read differently at a distance where no face is legible — different
            parting, different volume, one up and one down, or the mass sitting somewhere
            else. "Both long and dark" is two characters the viewer will confuse, however
            different their faces are on the page.

            Check age the same way: the oldest and the youngest must be orderable from
            hairline, hair color and face shape alone, with no body to help. If any pair
            fails, change one of them. A viewer tells these people apart at a glance or not
            at all.

            Consistency across the whole video depends on this text never varying. If a
            description is vague, the generator fills the gap differently in every scene and
            the character's face changes halfway through the video.
            TEXT,
            $this->castAgeBlock($story),
            $this->locale->guidanceFor((string) $story->locale_profile),
        ], fn (string $block): bool => trim($block) !== ''));
    }

    /**
     * The story's stated cast age range, if it has one.
     *
     * This is where a per-story casting decision belongs, and it is here
     * rather than in the art style constant for a reason worth keeping. The
     * style constant is one string appended to every prompt in every story;
     * asking it to carry "this cast is young" means asking one sentence to be
     * true of a workplace marriage drama and of a story about a dead mother and
     * an uncle with a cane at the same time. It cannot be, so it compensates —
     * and a rendering rule compensating for a casting decision is how the style
     * ended up instructing an anime generator to draw photoreal ageing.
     *
     * Note what this deliberately does NOT do: it does not override the script.
     * The extractor's job is to read the cast that was written, and a profile
     * that could rewrite a character's stated age would put the picture in
     * contradiction with the narration — the exact failure the style line is
     * also written to avoid. What it steers is INFERENCE, which is most of the
     * work: scripts rarely state an age, the model guesses one, and in this
     * genre it guesses old.
     *
     * Empty when the story states nothing, and array_filter drops it. Null
     * means no intention was expressed, not that a young cast was intended.
     */
    private function castAgeBlock(Story $story): string
    {
        $profile = trim((string) $story->cast_age_profile);

        if ($profile === '') {
            return '';
        }

        return "THE INTENDED AGE RANGE OF THIS CAST, from the operator:

  {$profile}

"
            .'Use this for every character whose age the script does not state outright. Where '
            .'the script DOES state an age, or makes one unambiguous — a grandmother, a '
            .'retirement, a character described as decades older than another — the script wins '
            .'and you write that age. A description that contradicts the narration is worse than '
            .'one outside the intended range, because the viewer hears both.';
    }

    /**
     * @param  array<int, string>  $scripts
     */
    /**
     * What a previous attempt got wrong, handed back verbatim.
     *
     * Re-asking the same question is not a repair — it re-rolls the same
     * mistake at the same rate. The first fix here corrected the SYSTEM prompt
     * and left the user message still asking for "habitual clothing and
     * recurring props"; the model followed the more specific instruction, which
     * was the wrong one. Naming the exact offending text is what makes a retry
     * a different question rather than the same one.
     *
     * @param  array<int, string>  $rejectionNotes
     */
    private function rejectionBlock(array $rejectionNotes): string
    {
        if ($rejectionNotes === []) {
            return '';
        }

        return "YOUR PREVIOUS ANSWER WAS REJECTED. Fix exactly this and change nothing else:\n\n  "
            .implode("\n  ", $rejectionNotes)
            ."\n\nThe rule you broke, in full:\n\n"
            .$this->text->ruleSummary()
            ."\n\nBoth description and style_notes are applied to EVERY prompt for that "
            .'character, so anything conditional, carried, momentary or textural becomes '
            .'unconditional and permanent. Remove the offending words and leave the rest of '
            ."each description exactly as it is.\n\n";
    }

    private function characterPrompt(Story $story, array $scripts, array $rejectionNotes = []): string
    {
        $body = '';

        foreach ($scripts as $index => $script) {
            $body .= sprintf("--- ACT %d ---\n%s\n\n", $index + 1, trim($script));
        }

        $outlineCast = OutlineCast::members($story->outline_cast);

        // With an outline cast the question changes: not "who is in this
        // script" but "describe these people". The extractor used to decide
        // the cast, and it invented three characters from role labels with no
        // script mentions at all ("Shen's Father"). ExtractCharacters drops
        // anyone returned who is not on the list, and says so on the job row.
        $opening = $outlineCast === []
            ? 'Read the whole script below and list every character who APPEARS IN MORE THAN '
                ."ONE SCENE or who matters to the story.\n\n"
            : "This story was outlined with exactly these people, and they are the cast:\n"
                .implode("\n", array_map(
                    static fn (CastMember $m): string => sprintf(
                        '- %s (%s)%s',
                        $m->name,
                        $m->role?->label() ?? 'no role',
                        $m->relationship === '' ? '' : ': '.$m->relationship,
                    ),
                    $outlineCast,
                ))
                ."\n\nRead the whole script below and describe these people and NOBODY ELSE, using "
                .'each name exactly as written. Leave out anyone on the list who never appears in the '
                .'script. Do not add anyone who is not on it — not a parent, not a witness, not a '
                ."relative referred to by role — however often they are mentioned.\n\n";

        return sprintf(
            '%s'
            .'Include the narrator. The narrator is on screen constantly and is the single '
            .'most important character to pin down — a narrator whose face changes is the '
            .'fastest way to lose a viewer. Name them from the script if they are named '
            // Was "a plain American name", hardcoded, for the whole of the time
            // there was only one setting to be wrong about. It would have named
            // the narrator of a story set in China "Karen" — and that name is
            // then pasted into every prompt she appears in and read aloud in
            // every act. The setting is already in this call's system prompt,
            // from the story's locale profile, so this defers to it rather than
            // carrying a second opinion about it.
            .'there; if the script never names them, give them a plain name that fits '
            ."the setting described in your instructions, and use it consistently.\n\n"
            .'Do NOT include people mentioned once in passing, people who are only spoken '
            .'about and never seen, or crowds. Every entry costs prompt space in every '
            ."scene they appear in.\n\n"
            .'For each: name, description (physical, fixed, silhouette-first, 25-45 words '
            .'— hair SHAPE distinct from every other character and longer and styled by '
            .'default, age carried by hairline, hair color and face shape, never by skin '
            .'and never by build, height or frame, which this illustration style discards), '
            .'style_notes '
            .'(habitual CLOTHING ONLY, one short phrase — no props, nothing held or carried, '
            .'and no "often" / "sometimes" / "usually"; style_notes is pasted into every '
            .'prompt this character appears in, so anything conditional in it becomes '
            ."permanent), and importance (lead, supporting, or minor).\n\n"
            .'%s'
            ."THE SCRIPT:\n\n%s",
            $opening,
            $this->rejectionBlock($rejectionNotes),
            trim($body)
        );
    }

    // -- Scene drafting --------------------------------------------------------

    /**
     * @param  array<int, CharacterProfile>  $cast
     */
    private function sceneSystemPrompt(Story $story, array $cast): string
    {
        return implode("\n\n", [
            <<<'TEXT'
            You break a narrated script into still images. The video is 150-250 still
            illustrations with a slow camera move over each one, and a narrator reading the
            script over the top. You decide where each still starts and what is in it.

            YOU DO NOT WRITE OR EDIT THE NARRATION. You choose sentence ranges. The script
            was approved by a human and is read aloud exactly as written.

            THE FRAME IS NOT THE SENTENCE. This is the part that gets done wrong.

            The narration is spoken OVER the picture. The picture is not an illustration of
            the words — it is what the camera is looking at while those words are said. If
            the line is "I paid it on a Tuesday and did not tell anyone", the frame is NOT
            "a woman paying a bill on a Tuesday". It is a composed shot: a kitchen table at
            night, a laptop open on a banking screen, a woman in her forties sitting back
            with her hands in her lap, one lamp on, the rest of the house dark.

            Write each frame as: who is in it, where they are, what their expression and
            posture are, what the light is doing, and what is in shot around them.

            - Never restate the sentence. If your frame reads like a paraphrase of the
              narration, it is wrong.
            - Vary the shot. Faces, hands, objects, empty rooms, wide establishing shots,
              things seen over a shoulder. Twenty consecutive medium shots of people talking
              is the same failure as a slideshow.
            - Not every scene needs a person in it. An envelope on a doormat, a street at
              dusk, a phone face-down on a table — cutaways are what make the people land
              when they come back.
            - Present tense, concrete nouns. No metaphor.
            - EVERY SCENE WITH A PERSON IN IT NEEDS AN EXPRESSION, in the `expression` field,
              and it must name what the face is DOING. "Jaw set, eyes down." "Openly crying,
              tears on both cheeks." "Mouth open mid-word, brows driven down." Describe the
              face rather than labelling the emotion — "brows drawn together, mouth set hard"
              rather than "angry" — but a plain emotional state stated as a visible fact is
              better than nothing. Leave it empty ONLY when nobody is in the frame.
            - NEVER HEDGE AN EXPRESSION. "Slightly", "faintly", "a little", "somewhat",
              "barely", "almost", "subtly", "a touch", "half" applied to a face render as
              NOTHING — the picture comes back with the neutral expression it would have had
              if you had written no expression at all. Write the expression at the strength it
              actually is, or leave it out and spend the words on the room. A hedged
              expression is strictly worse than no expression: it costs words and buys a
              blank face.
            - 25-45 words per frame.

            DO NOT describe the art style, the medium, the color palette, the rendering or
            the aspect ratio. Those are applied identically to every image afterwards and
            anything you say about them will conflict with them.

            DO NOT re-describe what a character looks like. Name them and list them in
            characters_present; their fixed description is pasted in automatically. Writing
            your own is exactly how a face drifts over 200 images.
            TEXT,
            $this->castBlock($cast),
            $this->motionGuidance(),
            $this->locale->guidanceFor((string) $story->locale_profile),
        ]);
    }

    /**
     * @param  array<int, CharacterProfile>  $cast
     */
    private function castBlock(array $cast): string
    {
        if ($cast === []) {
            return 'THE CAST: none extracted. Use no character names in characters_present.';
        }

        $lines = array_map(
            fn (CharacterProfile $c): string => sprintf('- %s (%s): %s', $c->name, $c->importance, $c->description),
            $cast
        );

        return "THE CAST — these are the only names you may put in characters_present:\n"
            .implode("\n", $lines)
            ."\n\nList a name only when that person is VISIBLE in the frame. Someone being "
            .'talked about is not someone in the picture.';
    }

    private function motionGuidance(): string
    {
        return <<<'TEXT'
        MOTION. Each still gets one slow camera move. Choose it from what the frame is:

        - zoom_in: pushes in. Faces, reactions, a detail becoming important.
        - zoom_out: pulls back. A reveal, or isolating someone in a bigger space.
        - pan_left / pan_right: travels sideways. Wide shots, interiors, landscapes,
          rooms with several people. A pan needs width to travel across.
        - static: no move. Use sparingly, for a held beat. More than a few in a row
          makes the video look broken.

        Vary it. A run of the same move reads as mechanical.
        TEXT;
    }

    /**
     * Where this act sits in the story, and what it is costing whom.
     *
     * THE SECOND CALL SITE OF A FINDING RECORDED AS CLOSED. `escalation_beat`
     * was required at outline, checked by ValidateOutlineSpine, editable at Gate
     * 1, shown on the page — and never reached `GenerateActScripts`, which
     * printed "COSTS: %s" as a blank for two phases. That was found and fixed.
     * It was fixed AT ONE CALL SITE.
     *
     * This call also decides what 150-250 pictures contain, and it has been
     * receiving `Act $act` and `Story $story` the whole time. The phase, the
     * beat and the whole genre spine were sitting on those two objects,
     * unasked. Nothing noticed, because nothing asks which OTHER callers read a
     * field — the same gap that let `CostUnit::TotalTokens` be added in code
     * while eleven migrations built their columns from the enum.
     *
     * Why a scene generator wants it: the frame is the picture the narration is
     * spoken over, and what a face should be doing depends entirely on whether
     * this act is an escalation the narrator is losing or a refusal they are
     * winning. Without it the generator has the sentences and nothing else, and
     * the safest picture to draw from a sentence is a neutral one.
     *
     * Kept short on purpose. This rides in every act's scene call, so it is
     * input tokens on 6-7 calls per story; the spine fields are one line each
     * and the phase is a word.
     */
    private function sceneContext(Story $story, Act $act): string
    {
        $lines = [];

        if ($act->phase !== null) {
            $lines[] = sprintf('PHASE: %s — %s', $act->phase->value, match ($act->phase->value) {
                'escalation' => 'the narrator is losing ground and absorbing it',
                'departure' => 'the narrator leaves, and does not announce it',
                'search' => 'the antagonist is looking for them and reaching them, and every meeting is costing her',
                'refusal' => 'the narrator is in the room for the exposure, and says no',
                default => 'unstated',
            });
        }

        // When the act is set. A present-day act's one-sentence citation of
        // an earlier incident is a cutaway at most, never a staged flashback
        // scene — and the scene writer is the stage that would draw one.
        if ($act->timeframe !== null) {
            $lines[] = 'SET IN: '.match ($act->timeframe) {
                ActTimeframe::Present => 'the story\'s present. A sentence citing an earlier '
                    .'incident is at most one cutaway still; it is not a scene from that year.',
                ActTimeframe::Prior => 'the past, before the story\'s present.',
            };
        }

        foreach ([
            'COSTS' => $act->escalation_beat,
            'THE GRIEVANCE' => $story->narrator_grievance,
            'THE ANTAGONIST BELIEVES' => $story->antagonist_justification,
            // Act 1 only: it is the act the scene is in. This is the call that
            // decides who is IN the frame, and a betrayal scene drawn as two
            // people at a table is the discovery again, in pictures — the
            // person it is done with and the witnesses have to be drawn.
            'THE BETRAYAL SCENE (who is in the room)' => $act->sequence === 1 ? $story->betrayal_scene : '',
            // The last three acts only, the acts his losses happen in. This is
            // the call that decides who is IN the frame, and a fall the script
            // stages in front of people drawn as her alone is the silent
            // accomplice again, in pictures. The consumer question for 3g.
            'THE ACCOMPLICE\'S FALL (he is in the room when he loses)' => in_array(
                $act->phase,
                [ActPhase::Departure, ActPhase::Search, ActPhase::Refusal],
                true,
            ) ? $story->accomplice_fall : '',
        ] as $label => $value) {
            if (trim((string) $value) !== '') {
                $lines[] = $label.': '.trim((string) $value);
            }
        }

        // The partner. This call decides who is IN a frame and how they stand,
        // and story 38's last chapter was a boss at a staff dinner in pictures
        // as well as in prose.
        //
        // TWO ACTS, BECAUSE THE ACT PROMPT ASKS FOR TWO THINGS. `partnerArcFor`
        // asks the SEARCH act for two moments where they are plainly becoming
        // more than what they arrived as, and the REFUSAL act for a third
        // and for the last chapter a year on. Only the second one used to reach this stage, so
        // the moment had no reinforcement where the pictures are decided —
        // measured on story 38, whose search act gave her four scenes and drew
        // all four as work (operations director, a rebate schedule, a line
        // review). Nothing here could have known one of them was the moment,
        // because nothing here had been told she existed.
        $partner = OutlineCast::futurePartner($story->outline_cast);

        // Deliberately NOT gated on the ending, unlike the refusal line below.
        // `partnerArcFor(Search)` asks for the moment whatever the ending is,
        // and the whole point of this block is that the two stages agree; an
        // extra condition here would put the prompt and the pictures back into
        // disagreement on the one story shape that already gets a Gate 1
        // warning for it.
        if ($partner !== null && $act->phase === ActPhase::Search) {
            $lines[] = sprintf(
                'THE NARRATOR\'S PARTNER: %s. This act has TWO MOMENTS where the two of them are '
                .'plainly becoming more than %s, and they differ by WHO IS WATCHING: the first is private '
                .'or nearly so, the second is in front of people. When the narration reaches one of them, '
                .'that frame is not a work frame — the two of them are close, turned toward each other, '
                .'and what is happening between them is what the picture is of; on the second, the people '
                .'who can see it are in the frame too. EVERY OTHER FRAME SHE IS IN THIS ACT IS NOT ONE OF '
                .'THOSE: there she is what she arrived as, drawn as she would be on any ordinary day, and '
                .'nothing in the frame says more. Two moments, two frames — not a mood over the act.',
                $partner->name,
                // 'what they arrived as', never the relationship LINE, and
                // the act prompt already does it this way. A relationship
                // line is written for the outline and routinely carries the
                // ending in it — story 39's reads "...and who by the end of
                // this story is my wife" — so interpolating it here would
                // hand the ending to a frame instruction about BECOMING,
                // in the one act whose whole point is that it has not
                // happened yet. Found by rendering the prompt, not by a test.
                'what they arrived as',
            );
        }

        if ($partner !== null && $act->phase === ActPhase::Refusal && $story->ending === StoryEnding::NewLife) {
            $lines[] = sprintf(
                'THE NARRATOR\'S PARTNER: %s. In the last chapter, a year on, the two of them are %s: '
                .'where they share a frame there, draw them as one — together, at ease, side by side — never '
                .'across a desk or as a boss and an employee.',
                $partner->name,
                // The word, not a staging instruction. This call decides who is
                // in a frame and how they stand, and "married" changes the
                // second of those about as much as "a couple" does — but the
                // narration in that chapter now says the chosen word, and a
                // frame told something weaker is a picture arguing with its own
                // narration. See App\Support\PartnerEnding.
                ($state = PartnerEnding::stateFor($story)) !== null
                    ? mb_strtolower($state->label())
                    : 'a couple',
            );
        }

        // Her chapter, when the act actually came back with one. Read off the
        // stored chapter rather than the story's intent, because this call
        // cuts the script that exists. The scene writer is the stage that
        // decides who is IN a frame, and inside her chapter "I" is not the
        // narrator: without this line it draws the narrator into every still
        // of it. And it is the stage that would draw a year as a face, which
        // one reference sheet at her age in the story cannot carry.
        $herChapter = $act->chapters->first(fn (Chapter $chapter): bool => $chapter->isPointOfView());

        if ($herChapter !== null) {
            $lines[] = sprintf(
                'THE ANTAGONIST\'S CHAPTER: from sentence %d to the end of the act, the narration is '
                .'%s\'s own first person — "I" there is %s, and the narrator is in a frame only where the '
                .'narration describes seeing them. It is set about a year after the refusal: draw the '
                .'year in rooms, objects and other people\'s faces, and draw %s as in the rest of the '
                .'story, never aged. What it reveals: %s',
                (int) $herChapter->first_sentence,
                $herChapter->point_of_view,
                $herChapter->point_of_view,
                $herChapter->point_of_view,
                trim((string) $story->antagonist_regret),
            );
        }

        // The chapter boundaries, read off the act the way the phase and the
        // beat are — the consumer question asked in the change that added the
        // field. A chapter starts at a sentence, a scene is a sentence range,
        // and a scene straddling the boundary puts the previous chapter's
        // still under the new chapter's opening line.
        $boundaries = $act->chapters
            ->filter(fn (Chapter $chapter): bool => $chapter->first_sentence > 1)
            ->map(fn (Chapter $chapter): string => sprintf(
                'sentence %d ("%s")',
                $chapter->first_sentence,
                $chapter->title,
            ))
            ->all();

        if ($boundaries !== []) {
            $lines[] = 'CHAPTER BOUNDARIES: a new chapter begins at '.implode(', ', $boundaries).'. '
                .'A scene NEVER straddles one — end a scene on the sentence before, start the next '
                .'scene on that sentence.';
        }

        return $lines === [] ? '' : "\n".implode("\n", $lines)."\n";
    }

    /**
     * @param  array<int, string>  $sentences
     * @param  array<int, CharacterProfile>  $cast
     */
    private function scenePrompt(
        Story $story,
        Act $act,
        array $sentences,
        array $cast,
        int $targetScenes,
    ): string {
        $numbered = '';

        foreach ($sentences as $index => $sentence) {
            $numbered .= sprintf("%d. %s\n", $index + 1, $sentence);
        }

        $total = count($sentences);
        $wordsPerScene = (int) config('scenes.words_per_scene');

        // The same number DraftScenes keeps per act. Asking for two and keeping
        // two is one decision; asking for two and keeping six story-wide was
        // the pool that never reached the refusal phase.
        $thumbnailsPerAct = (int) config('scenes.thumbnail_candidates.per_act');

        return sprintf(
            "ACT %d: %s\n%s\n"
            ."Break the numbered script below into about %d scenes.\n\n"
            ."RULES FOR THE RANGES:\n"
            ."- Every sentence from 1 to %d must be in exactly one scene.\n"
            .'- Scenes are contiguous and in order: the first scene starts at sentence 1, '
            .'the last ends at sentence %d, and each scene starts at the sentence after the '
            ."previous one ended.\n"
            ."- Never split a sentence. Ranges are whole sentences only.\n"
            .'- Aim for roughly %d words of narration per scene — usually one to three '
            .'sentences. Break where the picture would naturally change: a new place, a new '
            ."person speaking, a jump in time.\n"
            .'- A long sentence can be a scene on its own. Several short ones can share a '
            ."scene if they are the same moment.\n\n"
            ."FOR EACH SCENE GIVE:\n"
            ."- first_sentence, last_sentence: the range.\n"
            .'- frame: the composed shot. What is in the picture, not what the line says. '
            ."25-45 words, at most %d characters.\n"
            ."- characters_present: names from the cast who are VISIBLE. Empty if nobody is.\n"
            ."- motion_preset: zoom_in, zoom_out, pan_left, pan_right or static.\n"
            .'- expression: what the faces are DOING, named plainly. Empty ONLY if nobody is '
            ."in the frame. At most %d characters.\n"
            .'- thumbnail_candidate: true for at most %d scene(s) in this act — the ones that '
            .'would stop someone scrolling. A face mid-reaction, close enough to read at '
            .'thumbnail size. Never a frame with nobody in it — cutaways belong in the video '
            ."and never on the thumbnail — and never a wide establishing shot.\n\n"
            ."THE SCRIPT (%d sentences):\n\n%s",
            $act->sequence,
            $act->title,
            $this->sceneContext($story, $act),
            $targetScenes,
            $total,
            $total,
            $wordsPerScene,
            // The bounds Gate 2's editor validates the two authored sections
            // against, and DraftScenes enforces after the call. Not schema
            // constraints — structured outputs do not honour maxLength.
            Scene::FRAME_MAX_CHARS,
            Scene::EXPRESSION_MAX_CHARS,
            $thumbnailsPerAct,
            $total,
            trim($numbered),
        );
    }

    // -- Schemas -------------------------------------------------------------

    /**
     * The outline's shape, with the genre spine as REQUIRED fields.
     *
     * Required in the schema rather than merely asked for in the prompt, and
     * that is the point of putting them here: a model asked in prose for five
     * things will reliably give four when one is awkward, and the awkward one
     * is usually `antagonist_justification` — writing a self-justifying
     * antagonist is harder than writing a villain. A required field cannot be
     * skipped, only answered badly, and a bad answer is something Gate 1 can
     * see. See ValidateOutlineSpine.
     *
     * @return array<string, mixed>
     */
    private function outlineSchema(StoryFormat $format = StoryFormat::Single, ?StoryEnding $ending = null): array
    {
        $schema = $this->fullOutlineSchema($format);

        // The regret is asked for only when the chosen ending is hers. Removed
        // from the schema rather than left optional: a required field gets
        // filled, and an optional one on a structured output is still a field
        // the model is invited to write. SpineQuestions::outlineOrderFor() is
        // the prompt's half of the same decision.
        if ($ending !== null && ! $ending->asksForRegret()) {
            unset($schema['properties']['antagonist_regret']);
            $schema['required'] = array_values(array_diff($schema['required'], ['antagonist_regret']));
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private function fullOutlineSchema(StoryFormat $format): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],

                // The narrator, as a property of their own and before the
                // cast. A first-person premise never says the narrator's name,
                // and the cast was asked for "every person this story NAMES",
                // so a narrator row depended on one line in a role list under
                // a heading that excluded them. Story 37 returned eight rows
                // and no narrator, and was refused after a billed call. A
                // required property cannot be left off; `narrator` is out of
                // the cast's role enum on a single narrative, so there cannot
                // be two either. Merged back as the first cast row by
                // castFrom(). See OutlineCast::castArrayRoles().
                'narrator' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'relationship' => ['type' => 'string'],
                    ],
                    'required' => ['name', 'relationship'],
                    'additionalProperties' => false,
                ],

                // The people this story names, FIRST, before a word of the
                // spine. Property order is generation order, and every spine
                // field below names people: decided first, the spine is
                // written to the list; decided last, the list is harvested from
                // a spine that already named twelve. Required for the reason
                // `expression` is a scene field — a schema field's surface
                // measured 24 times a prompt rule's. See OutlineCast.
                'cast' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'role' => ['type' => 'string', 'enum' => array_column(OutlineCast::castArrayRoles($format), 'value')],
                            'relationship' => ['type' => 'string'],
                        ],
                        'required' => ['name', 'role', 'relationship'],
                        'additionalProperties' => false,
                    ],
                ],

                // The first thirty seconds. Required in the schema for the
                // reason the other seven are — a model asked in prose for
                // several things drops the awkward one — and this is an
                // awkward one in a specific way: the beats it asks for are
                // the LAST things that happen in the chronology it wants to
                // open with. Left optional it would come back as a summary of
                // the premise, which is what the opening already is.
                'hook' => ['type' => 'string'],

                'narrator_grievance' => ['type' => 'string'],
                'antagonist_justification' => ['type' => 'string'],

                // The accomplice's stake and the act he puts on, BEFORE the
                // betrayal scene, because property order is generation order
                // and that scene is where he first speaks in that act. Empty
                // strings when the cast has no accomplice. Required for the
                // reason every spine field is: asked in prose, the awkward one
                // is dropped, and a silent accomplice is the path of least
                // resistance the first reference taught this prompt. 3g.
                'accomplice_motive' => ['type' => 'string'],
                'accomplice_performance' => ['type' => 'string'],

                // The betrayal as a scene. Required because it is awkward in
                // the way a premise makes it awkward: most premises have the
                // betrayal FOUND — photos, a booking, a message — and the path
                // of least resistance is to stage the finding. Seven stories
                // took it. See the migration.
                'betrayal_scene' => ['type' => 'string'],

                'withheld_information' => ['type' => 'string'],
                'exposure_moment' => ['type' => 'string'],

                // How the narrator comes to be in the room for the exposure.
                // Required because it is the awkward one in the most
                // expensive way: the departure says the narrator is gone, so
                // the path of least resistance is an exposure they hear about
                // later. Two of three phase stories took it, and their public
                // payoff is a report — see the migration.
                'narrator_at_exposure' => ['type' => 'string'],

                // The reversal half. Required for the same reason the first
                // four are: asked for in prose, the awkward one gets dropped,
                // and these are the awkward ones — a model trained on this
                // genre's most common shape will happily escalate to an
                // exposure and stop, which is exactly the video story 21 was.
                'departure' => ['type' => 'string'],
                'reversal_beats' => ['type' => 'string'],

                // His losses run beside her search, so they are written after
                // it; the running thought is written before the refusal
                // because the refusal pays it off. 3g.
                'accomplice_fall' => ['type' => 'string'],
                'running_thought' => ['type' => 'string'],

                'refusal' => ['type' => 'string'],

                // After the refusal, because it happens after the refusal and
                // is written knowing what she was refused. Required, when it is
                // asked at all, for the reason every spine field is: asked in
                // prose, the awkward one is dropped. Asked only when the story's
                // ending is her chapter — see outlineSchema() and StoryEnding.
                'antagonist_regret' => ['type' => 'string'],

                'acts' => [
                    'type' => 'array',
                    // No minItems/maxItems: structured outputs reject any
                    // minItems other than 0 or 1, so the exact count is
                    // enforced against the decoded response instead. The
                    // prompt still enumerates the slots.
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'summary' => ['type' => 'string'],
                            'escalation_beat' => ['type' => 'string'],
                            // Declared per act, because a model asked in
                            // prose to keep every act in the present would
                            // keep most of them; asked to SAY where each act
                            // sits, it says "prior" for the one that is, and
                            // Gate 1 can refuse before the act is bought.
                            'timeframe' => ['type' => 'string', 'enum' => ['present', 'prior']],
                        ],
                        'required' => ['title', 'summary', 'escalation_beat', 'timeframe'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => [
                'title',
                'narrator',
                'cast',
                'hook',
                'narrator_grievance',
                'antagonist_justification',
                'accomplice_motive',
                'accomplice_performance',
                'betrayal_scene',
                'withheld_information',
                'exposure_moment',
                'narrator_at_exposure',
                'departure',
                'reversal_beats',
                'accomplice_fall',
                'running_thought',
                'refusal',
                'antagonist_regret',
                'acts',
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function actSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                // The act AS chapters. Required as an array of objects rather
                // than asked for as headings in one prose string, for the
                // reason `expression` is a scene field: a shape the model has
                // to fill is honoured where a prose instruction is honoured
                // most of the time. No minItems/maxItems — structured outputs
                // reject any minItems other than 0 or 1 — so the count is
                // enforced against the decoded response in GenerateActScripts,
                // after the cost row, beside the summary bound.
                'chapters' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'rehook_line' => ['type' => 'string'],
                            'text' => ['type' => 'string'],
                            // Empty for the narrator. The antagonist's cast
                            // name on the one chapter that ends the refusal act
                            // of a story with an antagonist_regret. Required so
                            // the writer SAYS whose chapter it is rather than
                            // leaving it to be inferred from the prose; checked
                            // after the cost row in GenerateActScripts.
                            'point_of_view' => ['type' => 'string'],
                        ],
                        'required' => ['title', 'rehook_line', 'text', 'point_of_view'],
                        'additionalProperties' => false,
                    ],
                ],
                // No maxLength, deliberately: structured outputs do not honour
                // string constraints, so a bound written here would be either
                // rejected or ignored — and ignored is the documented-guard
                // shape, a limit that reads as enforced and is not. The bound
                // (Act::SUMMARY_MAX_CHARS) is stated in the prompt and checked
                // against the decoded response in GenerateActScripts, after the
                // cost row, the way the outline's act count is. A test asserts
                // this schema carries no maxLength so nobody "fixes" it in.
                'summary' => ['type' => 'string'],
            ],
            'required' => ['chapters', 'summary'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function characterSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'characters' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'style_notes' => ['type' => 'string'],
                            'importance' => ['type' => 'string', 'enum' => ['lead', 'supporting', 'minor']],
                        ],
                        'required' => ['name', 'description', 'style_notes', 'importance'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['characters'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sceneSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'scenes' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'first_sentence' => ['type' => 'integer'],
                            'last_sentence' => ['type' => 'integer'],
                            'frame' => ['type' => 'string'],
                            'characters_present' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'motion_preset' => [
                                'type' => 'string',
                                'enum' => ['zoom_in', 'zoom_out', 'pan_left', 'pan_right', 'static'],
                            ],
                            'expression' => ['type' => 'string'],
                            'thumbnail_candidate' => ['type' => 'boolean'],
                        ],
                        'required' => [
                            'first_sentence',
                            'last_sentence',
                            'frame',
                            'characters_present',
                            'motion_preset',
                            // REQUIRED, and empty only when nobody is in frame.
                            // Asked for in prose since Phase 2 and supplied on
                            // 22.2% of peopled frames; the other 77.8% bought a
                            // still wearing the reference portrait's neutral
                            // face. A required field cannot be skipped, only
                            // answered badly, and a bad answer is visible at
                            // Gate 2.
                            'expression',
                            'thumbnail_candidate',
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['scenes'],
            'additionalProperties' => false,
        ];
    }
}
