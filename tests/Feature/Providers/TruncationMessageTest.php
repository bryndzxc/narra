<?php

namespace Tests\Feature\Providers;

use Anthropic\Client;
use App\Services\Claude\ClaudeScriptWriter;
use App\Support\CharacterTextGuard;
use App\Support\LocaleGuard;
use ReflectionMethod;
use Tests\TestCase;

/**
 * What a stage says when it runs out of output tokens.
 *
 * ---------------------------------------------------------------------------
 * THE DEFECT
 * ---------------------------------------------------------------------------
 *
 * One sentence served all eight operations: *"Raise ANTHROPIC_MAX_TOKENS or
 * lower the per-act word target."* It fired on `generate_outline`, which has
 * neither. There is no per-act word target at the outline stage — that lever
 * belongs to the act scripts — and `ANTHROPIC_MAX_TOKENS` is not a variable
 * this app reads at all; every ceiling is suffixed.
 *
 * The second half is the worse one. Setting an env var nothing consults looks
 * like applying the fix, so the next run fails identically and the remedy
 * appears to have been tried. **A message naming a remedy the stage does not
 * have is worse than no message** — the same family as a checklist item about
 * something that cannot exist.
 *
 * ---------------------------------------------------------------------------
 * WHAT IS ASSERTED
 * ---------------------------------------------------------------------------
 *
 * Every operation's remedy names an env var that this app actually reads, and
 * no operation is handed another stage's lever. Both are checked against the
 * whole roster rather than against the one operation that failed, because
 * fixing the instance is what produced the defect in the first place: the
 * message was written for the act stage and inherited by seven others.
 */
class TruncationMessageTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    /**
     * The ANTHROPIC_* env vars config/providers.php actually reads, taken from
     * the file rather than retyped here.
     *
     * This was a hand-typed list of the seven ceiling variables, and it was
     * correct for exactly as long as a remedy only ever named a ceiling. The
     * outline's remedy now names `ANTHROPIC_EFFORT_OUTLINE`, because effort is
     * what fills that ceiling, and a list that did not know effort existed
     * failed a true sentence. A second copy of "what config reads" is the
     * two-copies shape; the file is the one source.
     *
     * @return array<int, string>
     */
    private function variablesConfigReads(): array
    {
        preg_match_all(
            '/env\(\s*[\'"](ANTHROPIC_[A-Z_]+)[\'"]/',
            (string) file_get_contents(config_path('providers.php')),
            $matches,
        );

        return array_values(array_unique($matches[1]));
    }

    /**
     * Every operation DECLARES its remedy, and a declared null means none is
     * known.
     *
     * The key must exist so that "nobody wrote one" and "nothing is known" are
     * different states in config. Only a measured remedy is a string; the page
     * renders "No known repair." for null.
     */
    public function test_every_operation_declares_its_remedy_or_declares_none_is_known(): void
    {
        foreach ($this->operations() as $operation => $config) {
            $this->assertArrayHasKey(
                'truncation_remedy',
                $config,
                "Operation '{$operation}' has a ceiling and says nothing about hitting it. Add a "
                .'truncation_remedy beside its max_tokens: the measured repair, or null for none known.',
            );

            $remedy = $config['truncation_remedy'];
            $this->assertTrue(
                $remedy === null || (is_string($remedy) && trim($remedy) !== ''),
                "'{$operation}': a remedy is a measured sentence or null, never an empty string.",
            );
        }
    }

    /**
     * RED, as the shipped string: no remedy names a variable this app does not
     * read. RemediesNameRealKnobsTest checks every string in the app for the
     * same thing; this one is kept beside the defect it was written for.
     */
    public function test_no_remedy_names_an_env_var_that_does_not_exist(): void
    {
        foreach ($this->operations() as $operation => $config) {
            preg_match_all('/\bANTHROPIC_[A-Z_]+\b/', (string) ($config['truncation_remedy'] ?? ''), $matches);

            foreach ($matches[0] as $named) {
                $this->assertContains(
                    $named,
                    $this->variablesConfigReads(),
                    "'{$operation}' tells the operator to set {$named}, which config/providers.php "
                    .'never reads. Setting it changes nothing and looks like the fix was applied — '
                    .'which is how the original message wasted a run.',
                );
            }
        }
    }

    /**
     * The two measured remedies are the two that exist, and each names its OWN
     * stage's variables.
     *
     * This used to require every operation to name its ceiling variable, which
     * pushed seven remedies toward "raise ANTHROPIC_MAX_TOKENS_*" — advice
     * CLAUDE.md records as backwards. A remedy is only written where a
     * truncation was traced to a cause.
     */
    public function test_only_the_measured_remedies_exist_and_each_names_its_own_levers(): void
    {
        $measured = [
            'generate_outline' => 'ANTHROPIC_EFFORT_OUTLINE',
            'draft_scenes' => 'ANTHROPIC_EFFORT_SCENES_FALLBACK',
        ];

        foreach ($this->operations() as $operation => $config) {
            if (isset($measured[$operation])) {
                $this->assertStringContainsString($measured[$operation], (string) $config['truncation_remedy']);
            } else {
                $this->assertNull(
                    $config['truncation_remedy'],
                    "'{$operation}' carries a remedy nothing has measured. If a truncation on this stage "
                    .'has been traced to a cause, write that; otherwise it stays null and the page says '
                    .'"No known repair."',
                );
            }
        }
    }

    /**
     * The outline is not offered the act script's lever.
     *
     * The shipped defect stated as an assertion, on the remedy the page shows.
     */
    public function test_the_outline_is_not_told_to_lower_a_word_target_it_does_not_have(): void
    {
        $remedy = (string) $this->operations()['generate_outline']['truncation_remedy'];

        $this->assertStringContainsString(
            'no per-act word target',
            $remedy,
            'The outline stage must say plainly that this lever is not its own — it was sent '
            .'looking for one for a whole phase.',
        );
    }

    /**
     * An operation with no remedy says so, on the page, rather than inventing
     * one — the failure mode being avoided is precisely the old message.
     */
    public function test_an_operation_with_no_remedy_admits_it(): void
    {
        $story = \App\Models\Story::factory()->create();

        foreach (['generate_act_script', 'extract_characters', 'generate_titles', 'some_new_operation'] as $operation) {
            $remedy = \App\Support\FailureRemedy::for(\App\Enums\FailureKind::Truncated, ['operation' => $operation], $story);

            $this->assertFalse($remedy->known, $operation);
            $this->assertNull($remedy->text, $operation);
        }
    }

    /** Every message states the ceiling, the model, and that it was billed. */
    public function test_every_message_states_the_ceiling_the_model_and_the_bill(): void
    {
        foreach ($this->operations() as $operation => $config) {
            $message = $this->message($operation, $config);

            $this->assertStringContainsString(number_format((int) $config['max_tokens']), $message);
            $this->assertStringContainsString((string) $config['model'], $message);
            $this->assertStringContainsString('billed in full', $message);
        }
    }

    /**
     * THE THROW SITE ACTUALLY USES THE BUILDER, and no source file outside
     * config carries ceiling advice of its own.
     *
     * **Found by a drill passing.** Every other case here reflects straight into
     * `truncationMessage()`, so restoring the old inline `sprintf` at the throw
     * site left all of them green while the shipped message came back verbatim —
     * the builder was correct, well tested, and no longer called. That is
     * `escalation_beat` reaching a prompt that never sent it, in a test file.
     *
     * Two assertions, because either alone is defeatable. The first catches a
     * revert that names a ceiling variable anywhere in `app/` — which is where
     * the original defect lived, and it would have caught it. The second catches
     * a revert that avoids naming one, by requiring the branch to delegate.
     */
    public function test_the_thrower_delegates_and_holds_no_advice_of_its_own(): void
    {
        $source = (string) file_get_contents(base_path('app/Services/Claude/TalksToClaude.php'));

        // The builder's own docblock quotes the defect it replaces, so the scan
        // runs on code rather than on comments.
        $code = (string) preg_replace('#/\*.*?\*/#s', '', $source);

        $this->assertDoesNotMatchRegularExpression(
            '/ANTHROPIC_MAX_TOKENS/',
            $code,
            'A ceiling env var is named in app/ code. Every one of them lives beside the '
            .'max_tokens it controls, in config/providers.php -> anthropic.operations, and a copy '
            .'here is how the shipped message came to name a variable this app never reads.',
        );

        $this->assertMatchesRegularExpression(
            '/stopReason\s*===\s*.max_tokens.[\s\S]{0,200}?truncationMessage\s*\(/',
            $code,
            'The max_tokens branch no longer builds its message through truncationMessage(). '
            .'Every other assertion in this file reflects into that method, so a throw site that '
            .'stops calling it leaves them all green — which is exactly how this was found.',
        );
    }

    /**
     * No source file outside config offers another stage's lever.
     *
     * The phrase is the shipped one. Scanning the whole of `app/` rather than
     * the one file, because the defect was a sentence written for one stage and
     * inherited by seven, and the next copy of it will not be in the file this
     * one was.
     */
    public function test_no_source_file_offers_the_word_target_as_advice(): void
    {
        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $code = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($file->getPathname()));

            if (preg_match('/lower the per-act word target/i', $code)) {
                $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Advice about the per-act word target is written in app/ code. It belongs to exactly '
            .'one operation and lives in that operation\'s truncation_remedy.',
        );
    }

    /** @return array<string, array<string, mixed>> */
    private function operations(): array
    {
        return (array) config('providers.anthropic.operations');
    }

    /** @param  array<string, mixed>  $config */
    private function message(string $operation, array $config): string
    {
        $writer = new ClaudeScriptWriter(
            // Never called: only the private message builder is invoked.
            client: app(Client::class),
            locale: app(LocaleGuard::class),
            text: app(CharacterTextGuard::class),
        );

        $method = new ReflectionMethod($writer, 'truncationMessage');
        $method->setAccessible(true);

        return (string) $method->invoke($writer, $operation, $config);
    }
}
