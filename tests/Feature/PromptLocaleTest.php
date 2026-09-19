<?php

namespace Tests\Feature;

use Anthropic\Client;
use App\Enums\ActPhase;
use App\Enums\ActTimeframe;
use App\Enums\StoryFormat;
use App\Models\Act;
use App\Models\Story;
use App\Services\Claude\ClaudeMetadataWriter;
use App\Services\Claude\ClaudeScriptWriter;
use App\Support\CharacterTextGuard;
use App\Support\LocaleGuard;
use App\Support\Providers\ActOutline;
use App\Support\Providers\CharacterProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\Support\PromptLocaleScan;
use Tests\TestCase;

/**
 * THE PROMPTS WE WRITE ARE HELD TO THE LOCALE LISTS THE WRITER'S OUTPUT IS.
 *
 * `LocaleGuard` checks what the model returns and refuses a stage after the
 * call is billed. Nothing checked what we SEND. A prompt that spells "colour"
 * teaches the writer the word the guard then refuses: the cast and scene
 * prompt sources carried eleven "colour" and two "grey", and cast descriptions
 * and scene frames are exactly the outputs the guard refuses. 3g's own
 * "apologise" was caught only because the fake outline used it too.
 *
 * Every writer prompt is built from repo code and repo config — no env var
 * reaches one — so this runs at test time, not at dispatch.
 *
 * Two halves:
 *
 *  - STATIC, which matters more: every string literal in every app file a
 *    writer can reach, closure derived from the code. Catches a branch no
 *    matrix renders. Held to the SHARED lists (British spelling, operator
 *    idiom), because a literal does not know which profile it will reach.
 *  - RENDERED: the prompts as sent, across both profiles, both formats, every
 *    phase and the optional fields filled and empty, each held to its own
 *    story's profile. Catches what only exists once assembled.
 *
 * Denylist: zero, with ONE exception excised by its exact text: the line
 * LocaleGuard::americanWordsLine() generates, naming each denied term beside
 * its American word (the operator's decision, 2026-09-19 — a ban that names
 * no right word cost three refusals on "car park"). Warnlist: allowed only by
 * an exception naming its phrase.
 *
 * NOT covered: story data (premise, spine, summaries typed at Gate 1). That is
 * not in the repo; the output guard is still the only check on it. And the
 * image generator's config strings (art style, reference frame) are out of
 * scope on purpose: no writer reads them, the spelling means nothing to that
 * model, and they are env-overridable.
 */
class PromptLocaleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Deliberate warnlist uses, each keyed on the phrase that makes it one.
     *
     * @var array<int, array{term: string, phrase: string}>
     */
    private const ALLOWED_WARN = [
        // The ban has to name the words it bans (config/locale.php, en-CN guidance).
        ['term' => 'tito', 'phrase' => 'Never Tito, Lola, Ate or Po'],
        ['term' => 'lola', 'phrase' => 'Never Tito, Lola, Ate or Po'],
        ['term' => 'ate', 'phrase' => 'Never Tito, Lola, Ate or Po'],
        ['term' => 'po', 'phrase' => 'Ate or Po as a given name'],
        // "flat" the adjective, not the British apartment.
        ['term' => 'flat', 'phrase' => 'a flat courtesy'],
        ['term' => 'flat', 'phrase' => 'flat and sleek'],
        // Inside the ageing-texture ban list; en-CN warns "feet" as an imperial unit.
        ['term' => 'feet', 'phrase' => "crow's feet"],
        // The artisan command, in an operator message the closure reaches through
        // RunFingerprint. The closure over-includes on purpose; this is the cost.
        ['term' => 'queue', 'phrase' => 'artisan queue:'],
    ];

    // -- The static half -----------------------------------------------------

    public function test_no_string_literal_a_writer_can_reach_carries_a_shared_locale_term(): void
    {
        $guard = app(LocaleGuard::class);
        $files = PromptLocaleScan::reachableFiles(base_path());

        // The closure has to contain what is known to feed a prompt, or a zero
        // below is a zero about the wrong files.
        foreach ([
            'app/Services/Claude/ClaudeScriptWriter.php',
            'app/Services/Claude/ClaudeMetadataWriter.php',
            'app/Services/Claude/TalksToClaude.php',   // same namespace, no `use`
            'app/Support/CharacterTextGuard.php',      // its rule summary is sent on a retry
            'app/Enums/ActPhase.php',                  // slot guidance in the outline prompt
            'app/Enums/CastRole.php',                  // role guidance in the outline prompt
        ] as $known) {
            $this->assertContains($known, $files, "The derived closure does not reach {$known}.");
        }

        $findings = [];

        foreach ($files as $file) {
            $literals = PromptLocaleScan::literals((string) file_get_contents(base_path($file)));

            foreach (PromptLocaleScan::findings($guard, $literals, $guard->list(null, 'denylist')) as $hit) {
                $findings[] = "DENY {$file} \"{$hit['term']}\": {$hit['context']}";
            }

            foreach (PromptLocaleScan::findings($guard, $literals, $guard->list(null, 'warnlist'), self::ALLOWED_WARN) as $hit) {
                $findings[] = "WARN {$file} \"{$hit['term']}\": {$hit['context']}";
            }
        }

        $this->assertSame([], $findings, "Prompt source text teaches a locale term the guard refuses or warns on:\n".implode("\n", $findings));
    }

    /**
     * The locale guidance is config, not a literal the closure reads, and it
     * is the one config string that reaches a writer. It has no branches, so
     * checking each profile's text against that profile's lists is complete.
     */
    public function test_each_profiles_guidance_carries_none_of_its_own_terms(): void
    {
        $guard = app(LocaleGuard::class);
        $findings = [];

        foreach (array_keys($guard->profiles()) as $profile) {
            $text = $guard->guidanceFor($profile);

            // The one exception: the generated line naming each denied term
            // with its American word. It must be there, once, and nothing
            // else in the guidance may carry a denied term.
            $this->assertSame(1, substr_count($text, $guard->americanWordsLine()), $profile);
            $text = str_replace($guard->americanWordsLine(), '', $text);

            foreach (PromptLocaleScan::findings($guard, $text, $guard->list($profile, 'denylist')) as $hit) {
                $findings[] = "DENY {$profile} \"{$hit['term']}\": {$hit['context']}";
            }

            foreach (PromptLocaleScan::findings($guard, $text, $guard->list($profile, 'warnlist'), self::ALLOWED_WARN) as $hit) {
                $findings[] = "WARN {$profile} \"{$hit['term']}\": {$hit['context']}";
            }
        }

        $this->assertSame([], $findings, implode("\n", $findings));
    }

    /**
     * The claim above — that the guidance is the only config PROSE a writer
     * reads — made into an assertion. Every `config('...')` key read by a
     * reachable file must resolve to something that is not prose. A new prompt
     * sentence moved into config fails here, naming the key, instead of
     * escaping both halves.
     */
    public function test_no_other_config_prose_reaches_a_writer(): void
    {
        $keys = [];

        foreach (PromptLocaleScan::reachableFiles(base_path()) as $file) {
            preg_match_all("/config\\('([a-z0-9_.]+)'/", (string) file_get_contents(base_path($file)), $m);
            foreach ($m[1] as $key) {
                $keys[$key][] = $file;
            }
        }

        $this->assertArrayHasKey('chapters.announce', $keys, 'precondition: the scan finds config reads at all');

        // Config prose that a reachable file reads and no writer receives,
        // each checked by reading its consumers (2026-09-17). A new key is
        // not on this list and fails.
        $notSentToAWriter = [
            'locale.profiles' => 'the guidance; checked per profile by the test above',
            'providers.anthropic.operations' => 'truncation_remedy, into an exception message',
            'render.script.measured_act_words_on' => 'a provenance note shown at Gate 1 and on the new-story form',
            'scenes.art_style' => 'image generator and StyleFingerprint; out of scope, env-overridable',
            'scenes.constraints' => 'image generator and StyleFingerprint; out of scope, env-overridable',
            'characters.reference_frame' => 'image generator and StyleFingerprint; out of scope, env-overridable',
        ];

        foreach (array_keys($notSentToAWriter) as $key) {
            $this->assertArrayHasKey($key, $keys, "{$key} is exempted but no reachable file reads it any more; drop the exemption.");
        }

        $prose = [];

        foreach (array_diff_key($keys, $notSentToAWriter) as $key => $files) {
            $value = config($key);
            $strings = is_array($value) ? array_filter(array_merge(...array_map(
                static fn ($v) => is_array($v) ? array_values($v) : [$v],
                array_values($value),
            )), 'is_string') : (is_string($value) ? [$value] : []);

            foreach ($strings as $string) {
                if (str_word_count($string) >= 6) {
                    $prose[] = "{$key} (read by ".implode(', ', array_unique($files)).')';
                    break;
                }
            }
        }

        $this->assertSame([], $prose, "Config prose reaches a writer and neither half checks it:\n".implode("\n", $prose));
    }

    // -- The rendered half ---------------------------------------------------

    public function test_every_rendered_prompt_is_clean_for_its_own_profile(): void
    {
        $guard = app(LocaleGuard::class);
        $rendered = $this->renderMatrix();

        // Size precondition: the matrix is what it claims, and nothing rendered
        // empty (a render that silently returned '' would read as clean).
        $this->assertGreaterThanOrEqual(300, count($rendered));
        foreach ($rendered as $label => $variant) {
            $this->assertNotSame('', trim($variant['text']), "{$label} rendered empty.");
        }

        $findings = [];

        foreach ($rendered as $label => ['profile' => $profile, 'text' => $text]) {
            // Excised by its exact text, so a denied word anywhere else in the
            // prompt still fails. See americanWordsLine().
            $text = str_replace($guard->americanWordsLine(), '', $text);

            foreach (PromptLocaleScan::findings($guard, $text, $guard->list($profile, 'denylist')) as $hit) {
                $findings["DENY \"{$hit['term']}\": {$hit['context']}"][] = $label;
            }

            foreach (PromptLocaleScan::findings($guard, $text, $guard->list($profile, 'warnlist'), self::ALLOWED_WARN) as $hit) {
                $findings["WARN \"{$hit['term']}\": {$hit['context']}"][] = $label;
            }
        }

        $this->assertSame([], array_keys($findings), "Rendered prompts carry locale terms:\n".implode("\n", array_map(
            static fn (string $finding, array $labels): string => $finding.' — in '.$labels[0].(count($labels) > 1 ? ' and '.(count($labels) - 1).' more' : ''),
            array_keys($findings),
            $findings,
        )));
    }

    // -- Known answers --------------------------------------------------------

    /** RED: a literal teaching the word, in a file the closure reaches. GREEN: the same in a comment. */
    public function test_the_literal_scan_reports_a_literal_and_ignores_a_comment(): void
    {
        $guard = app(LocaleGuard::class);
        $deny = $guard->list(null, 'denylist');

        $red = "<?php\nreturn 'Describe the hair colour and the car park.';\n";
        $green = "<?php\n// Describe the hair colour and the car park.\nreturn 'Describe the hair color and the parking lot.';\n";

        $this->assertCount(2, PromptLocaleScan::findings($guard, PromptLocaleScan::literals($red), $deny));
        $this->assertCount(0, PromptLocaleScan::findings($guard, PromptLocaleScan::literals($green), $deny));

        // A heredoc body is a literal too.
        $heredoc = "<?php\nreturn <<<'TEXT'\n    not merely in colour and length\n    TEXT;\n";
        $this->assertCount(1, PromptLocaleScan::findings($guard, PromptLocaleScan::literals($heredoc), $deny));
    }

    /** Every occurrence counts, not one per term — the undercount behind the first sweep. */
    public function test_every_occurrence_is_reported(): void
    {
        $guard = app(LocaleGuard::class);

        $this->assertCount(3, $guard->occurrences('colour, colour and colour', ['colour']));
    }

    /** An exception is keyed on its phrase: the ban stays allowed, a new use of the word does not. */
    public function test_an_allowed_exception_covers_its_phrase_and_nothing_else(): void
    {
        $guard = app(LocaleGuard::class);
        $warn = $guard->list('en-US', 'warnlist');

        $this->assertCount(0, PromptLocaleScan::findings($guard, 'Never Tito, Lola, Ate or Po as a given name.', $warn, self::ALLOWED_WARN));
        $this->assertCount(1, PromptLocaleScan::findings($guard, 'Her aunt Lola arrived at the banquet.', $warn, self::ALLOWED_WARN));
        $this->assertCount(1, PromptLocaleScan::findings($guard, 'the year the flat was bought', $warn, self::ALLOWED_WARN));
    }

    /**
     * The shared list is the INTERSECTION: British spelling is in it, a
     * setting term like Thanksgiving (correct in en-US guidance) is not.
     */
    /**
     * EVERY DENIED BRITISH TERM NAMES ITS AMERICAN WORD, AND THE WRITER GETS IT.
     *
     * The pairs are sound (each term is denied in every profile, each word is
     * on no list), the line carries every pair, and it reaches the prompts
     * that write prose: the outline, the act and the cast.
     */
    public function test_every_denied_british_term_names_its_american_word_for_the_writer(): void
    {
        $guard = app(LocaleGuard::class);
        $pairs = (array) config('locale.american_words');

        $this->assertArrayHasKey('car park', $pairs);
        $this->assertSame('parking lot', $pairs['car park']);

        foreach ($pairs as $british => $american) {
            $this->assertNotSame('', trim($american), "{$british} names no word.");
            $this->assertStringContainsString("{$british} → {$american}", $guard->americanWordsLine());

            foreach (array_keys($guard->profiles()) as $profile) {
                $this->assertContains($british, $guard->list($profile, 'denylist'), "{$british} is not denied in {$profile}.");
                $this->assertSame([], PromptLocaleScan::findings($guard, $american, $guard->list($profile, 'denylist')), "{$american} is itself denied in {$profile}.");
                $this->assertSame([], PromptLocaleScan::findings($guard, $american, $guard->list($profile, 'warnlist')), "{$american} is itself warned in {$profile}.");
            }
        }

        $rendered = $this->renderMatrix();
        foreach (['outline', 'act', 'character'] as $prefix) {
            $hits = array_filter($rendered, fn (array $v, string $label): bool => str_starts_with(mb_strtolower($label), $prefix)
                && str_contains($v['text'], 'car park → parking lot'), ARRAY_FILTER_USE_BOTH);
            $this->assertNotEmpty($hits, "No {$prefix} prompt carries the pairs.");
        }
    }

    /**
     * RED/GREEN on the excision: the line is exempt by its exact text and
     * nothing else is. A prompt that says "car park" outside it still fails.
     */
    public function test_red_green_only_the_generated_line_is_exempt(): void
    {
        $guard = app(LocaleGuard::class);
        $deny = $guard->list('en-CN', 'denylist');

        $green = 'Write the scene. '.$guard->americanWordsLine();
        $red = $green.' She waited in the car park.';

        $this->assertSame([], PromptLocaleScan::findings($guard, str_replace($guard->americanWordsLine(), '', $green), $deny));
        $this->assertNotSame([], PromptLocaleScan::findings($guard, str_replace($guard->americanWordsLine(), '', $red), $deny));
    }

    public function test_the_shared_list_holds_spelling_and_not_setting(): void
    {
        $shared = app(LocaleGuard::class)->list(null, 'denylist');

        $this->assertContains('colour', $shared);
        $this->assertContains('car park', $shared);
        $this->assertNotContains('thanksgiving', $shared);
    }

    // -- The matrix ----------------------------------------------------------

    /** @return array<string, array{profile: string, text: string}> */
    private function renderMatrix(): array
    {
        $guard = app(LocaleGuard::class);
        $writer = new ClaudeScriptWriter(app(Client::class), $guard, app(CharacterTextGuard::class));
        $meta = new ClaudeMetadataWriter(app(Client::class), $guard);
        $out = [];

        foreach ([true, false] as $announce) {
            config(['chapters.announce' => $announce]);

            foreach (array_keys($guard->profiles()) as $profile) {
                foreach ([StoryFormat::Single, StoryFormat::Anthology] as $format) {
                    foreach ([true, false] as $filled) {
                        $story = $this->placeholderStory($profile, $format, $filled);
                        $tag = sprintf('[%s/%s/%s/%s]', $profile, $format->value, $filled ? 'filled' : 'empty', $announce ? 'announce' : 'silent');
                        $add = function (string $label, string $method, object $on, mixed ...$args) use (&$out, $profile, $tag): void {
                            $out["{$label} {$tag}"] = ['profile' => $profile, 'text' => (string) $this->invoke($on, $method, ...$args)];
                        };

                        $add('outline system', 'outlineSystemPrompt', $writer, $story);
                        $add('outline prompt', 'outlinePrompt', $writer, $story, 5);
                        $add('act system', 'actSystemPrompt', $writer, $story);

                        $plan = $format === StoryFormat::Anthology ? [] : ActPhase::planFor(5);
                        $outline = array_map(fn (int $n): ActOutline => new ActOutline(
                            sequence: $n, title: "Zqx act {$n}", summary: 'Zqx summary.', escalationBeat: 'Zqx beat.',
                            phase: $plan[$n] ?? null, timeframe: $filled ? ActTimeframe::Present : null,
                        ), range(1, 5));

                        foreach ($outline as $act) {
                            $add("act {$act->sequence} prompt", 'actPrompt', $writer, $story, $act, $outline, $act->sequence === 1 ? [] : ['Zqx.'], 985);
                        }

                        $legacy = [
                            new ActOutline(sequence: 1, title: 'Zqx', summary: 'Zqx.', phase: null),
                            new ActOutline(sequence: 2, title: 'Zqx', summary: 'Zqx.', phase: null),
                        ];
                        foreach ($legacy as $act) {
                            $add("phaseless act {$act->sequence} prompt", 'actPrompt', $writer, $story, $act, $legacy, [], 985);
                        }

                        $cast = [new CharacterProfile('Zqa Qorvath', 'Zqx description.', 'Zqx clothes.', 'lead')];
                        $add('character system', 'characterSystemPrompt', $writer, $story);
                        $add('character prompt', 'characterPrompt', $writer, $story, ['Zqx script.'], ['Zqx note.']);
                        $add('scene system', 'sceneSystemPrompt', $writer, $story, $cast);

                        foreach (($plan ?: [1 => null]) as $n => $phase) {
                            $act = new Act(['sequence' => $n, 'title' => 'Zqx', 'summary' => 'Zqx.', 'escalation_beat' => 'Zqx.', 'phase' => $phase, 'timeframe' => ActTimeframe::Present]);
                            $act->setRelation('chapters', collect());
                            $add("scene prompt act {$n}", 'scenePrompt', $writer, $story, $act, ['Zqx one.', 'Zqx two.'], $cast, 1);
                        }

                        $add('metadata title system', 'titleSystemPrompt', $meta, $story);
                        $add('metadata title prompt', 'titlePrompt', $meta, $story, 5);
                        $add('metadata copy system', 'copySystemPrompt', $meta, $story);
                        $add('metadata copy prompt', 'copyPrompt', $meta, $story, ['Zqx title']);
                        $add('metadata tag system', 'tagSystemPrompt', $meta, $story);
                        $add('metadata tag prompt', 'tagPrompt', $meta, $story, ['Zqx title'], 500);
                    }
                }
            }
        }

        return $out;
    }

    /** Placeholder data that matches no list, so every hit is text the repo wrote. */
    private function placeholderStory(string $profile, StoryFormat $format, bool $filled): Story
    {
        $story = Story::factory()->make([
            'title' => 'Zqx title', 'premise' => 'Zqx premise.', 'format' => $format, 'locale_profile' => $profile,
            'target_duration_min' => 30, 'target_duration_max' => 40, 'sized_against_wpm' => 197,
        ]);
        $story->id = 999999;

        foreach ([
            'hook', 'narrator_grievance', 'antagonist_justification', 'betrayal_scene', 'withheld_information',
            'exposure_moment', 'narrator_at_exposure', 'departure', 'reversal_beats', 'refusal', 'cast_age_profile',
            'accomplice_motive', 'accomplice_performance', 'accomplice_fall', 'running_thought',
        ] as $field) {
            $story->{$field} = $filled ? "Zqx {$field}." : null;
        }

        $story->outline_cast = $filled ? [
            ['name' => 'Zqa Qorvath', 'role' => 'narrator', 'relationship' => 'zqx'],
            ['name' => 'Zqb Qorvath', 'role' => 'antagonist', 'relationship' => 'zqx'],
            ['name' => 'Zqc Vornak', 'role' => 'accomplice', 'relationship' => 'zqx'],
        ] : null;
        $story->setRelation('acts', collect());

        return $story;
    }

    private function invoke(object $target, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($target, $method))->invoke($target, ...$args);
    }
}
