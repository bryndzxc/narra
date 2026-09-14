<?php

namespace Tests\Feature\Providers;

use Anthropic\Client;
use App\Enums\ActPhase;
use App\Models\Story;
use App\Services\Claude\ClaudeScriptWriter;
use App\Support\CharacterTextGuard;
use App\Support\LocaleGuard;
use App\Support\Providers\ActOutline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The middle of the video has the antagonist and the narrator in the same
 * scene, and the narrator answers back.
 *
 * Two rules were derived from one reference TITLE and applied as structure
 * for four stories: "the search fails and there is no contact until the
 * exposure", and "no act ends with the narrator winning a round". The first
 * reference TRANSCRIPT read (2026-09-13, 34:46) runs the other way on both:
 * five encounters after the betrayal, at 9:53, 16:43, 18:21, 27:00 and 28:27,
 * each worse for her, and the narrator answering back inside the betrayal
 * scene itself ("breakup dinner or a date with your new boy toy", 3:30 — the
 * line he SAYS; "marry you my ass" at 2:56 is what he thinks) while still
 * losing the round. Ours
 * kept the two apart for 11 to 23 minutes and said "all right".
 *
 * These assert the prompts say the corrected thing, by reflection, because
 * a prompt rule dropped from one of the three places it lives is the shape
 * this project keeps paying for. The record is in CLAUDE.md 3d.
 */
class ContactThroughTheMiddleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_genre_contract_puts_them_in_the_same_scene_and_lets_the_narrator_answer(): void
    {
        $guidance = $this->invoke($this->writer(), 'genreGuidance', Story::factory()->single()->create());

        $this->assertStringContainsString('THE NARRATOR ANSWERS IN THE ROOM', $guidance);
        $this->assertStringContainsString('The exchange is won and the round is lost', $guidance);
        $this->assertStringContainsString('THE SEARCH, AND THE MEETINGS', $guidance);
        $this->assertStringContainsString('REACHES THEM', $guidance);
        $this->assertStringContainsString('The search may SUCCEED', $guidance);
        $this->assertStringContainsString('WHERE THEY WENT IS NOT ANNOUNCED', $guidance);

        // The old rule, in every wording it had.
        $this->assertStringNotContainsString('The search FAILS', $guidance);
        $this->assertStringNotContainsString('No act ends with the narrator winning a round', $guidance);
        $this->assertStringNotContainsString('because they came, not because', $guidance);
    }

    public function test_the_escalation_ending_asks_for_the_line_and_keeps_the_cost(): void
    {
        $ending = $this->endingFor(ActPhase::Escalation);

        $this->assertStringContainsString('THE NARRATOR ANSWERS BACK', $ending);
        $this->assertStringContainsString('ends worse off for the narrator', $ending);
        $this->assertStringContainsString('The exchange is won and the round is lost', $ending);
        $this->assertStringNotContainsString('no round is won', $ending);
    }

    public function test_the_search_ending_puts_them_in_the_same_scene(): void
    {
        $ending = $this->endingFor(ActPhase::Search);

        $this->assertStringContainsString('PUT THEM IN THE SAME SCENE AT LEAST ONCE IN THIS ACT', $ending);
        $this->assertStringContainsString('own life is ON SCREEN in this act, not a paragraph', $ending);
        $this->assertStringContainsString('She may learn where they are', $ending);
        $this->assertStringNotContainsString('She does not find them in this act', $ending);
        $this->assertStringNotContainsString('should be quiet', $ending);
    }

    public function test_the_refusal_ending_allows_a_found_narrator_and_keeps_the_scene_theirs(): void
    {
        $ending = $this->endingFor(ActPhase::Refusal);

        $this->assertStringContainsString('or she came to where they are', $ending);
        $this->assertStringContainsString('The narrator decides where and how long they talk', $ending);
        $this->assertStringContainsString('a loss she can no longer repair', $ending);
        $this->assertStringNotContainsString('The search did not find them', $ending);
    }

    public function test_the_outline_prompt_asks_the_spine_for_meetings(): void
    {
        $story = Story::factory()->single()->create();
        $prompt = $this->invoke($this->writer(), 'outlinePrompt', $story, 6);

        $this->assertStringContainsString('She and the narrator are IN THE SAME SCENE in at least two of these', $prompt);
        $this->assertStringContainsString('WHERE THEY WENT IS NOT ANNOUNCED', $prompt);
        $this->assertStringContainsString('or because she has found where they are and come', $prompt);
        $this->assertStringNotContainsString('THE SEARCH DOES NOT FIND THEM', $prompt);
        $this->assertStringNotContainsString('she did not.', $prompt);
    }

    public function test_the_phase_guidance_shown_at_gate_one_agrees(): void
    {
        $this->assertStringContainsString('answers back in every scene', ActPhase::Escalation->guidance());
        $this->assertStringContainsString('REACHES them', ActPhase::Search->guidance());
        $this->assertStringNotContainsString('no round is won', ActPhase::Escalation->guidance());

        // The ledger still runs against the narrator until the departure —
        // the method is about the cost, and the cost did not change.
        $this->assertTrue(ActPhase::Escalation->endsWorseForNarrator());
        $this->assertFalse(ActPhase::Search->endsWorseForNarrator());
    }

    private function endingFor(ActPhase $phase): string
    {
        $story = Story::factory()->single()->create([
            'departure' => 'She left on a Tuesday.',
            'reversal_beats' => 'She paid a man. Then she went to his aunt.',
            'exposure_moment' => 'At the banquet.',
            'refusal' => 'No.',
        ]);

        $act = new ActOutline(sequence: 3, title: 'T', summary: 'S', phase: $phase);

        return $this->invoke($this->writer(), 'endingFor', $story, $act, false);
    }

    private function writer(): ClaudeScriptWriter
    {
        return new ClaudeScriptWriter(
            client: app(Client::class),
            locale: app(LocaleGuard::class),
            text: app(CharacterTextGuard::class),
        );
    }

    private function invoke(object $target, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($target, $method))->invoke($target, ...$args);
    }
}
