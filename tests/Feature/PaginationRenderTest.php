<?php

namespace Tests\Feature;

use App\Enums\StoryStatus;
use App\Livewire\Gates\ScenesGate;
use App\Models\Act;
use App\Models\Scene;
use App\Models\Story;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Paginated pages render against this project's stylesheet, not Tailwind's.
 *
 * Laravel's default paginator view is `pagination::tailwind`, and Livewire
 * overrides that with `livewire::tailwind` for its own components. This app has
 * one hand-written stylesheet and no Tailwind at all, so every utility class in
 * those views does nothing — including the ones that hide the duplicate desktop
 * block and size the inline SVG chevrons.
 *
 * The reported symptom was a pagination arrow rendered at the height of the
 * viewport on the Gate 2 scenes page, with the scene list pushed off the bottom
 * of the screen behind it.
 *
 * These tests exist because that failure is invisible from PHP: nothing errors,
 * the page returns 200, the markup is valid, and the only thing wrong is that
 * an icon is nine hundred pixels tall. The check that catches it is structural
 * — no SVG and no Tailwind class names reach the browser from a paginator.
 */
class PaginationRenderTest extends TestCase
{
    use RefreshDatabase;

    /** Utility classes that only mean anything if Tailwind is loaded. */
    private const TAILWIND_MARKERS = ['hidden sm:', 'sm:flex', 'w-5 h-5', 'dark:bg-gray-'];

    public function test_the_livewire_scenes_gate_paginator_renders_no_svg(): void
    {
        // The page in the bug report: 199 scenes at 20 a page.
        $story = $this->storyWithScenes(45);

        $html = Livewire::test(ScenesGate::class, ['story' => $story])->html();

        $this->assertStringNotContainsString(
            '<svg',
            $html,
            'The Gate 2 paginator is rendering an inline SVG again. Without Tailwind there is nothing '
            .'to size it, so it expands to fill its container — which is what put a viewport-height '
            .'chevron on this page.'
        );

        foreach (self::TAILWIND_MARKERS as $marker) {
            $this->assertStringNotContainsString(
                $marker,
                $html,
                "The paginator is emitting the Tailwind class \"{$marker}\", which does nothing here."
            );
        }
    }

    public function test_the_livewire_paginator_is_usable_on_the_first_page(): void
    {
        // Page 1 is where it was reported: previous is disabled, next is live,
        // and both still have to be visible and the right size.
        $story = $this->storyWithScenes(45);

        $component = Livewire::test(ScenesGate::class, ['story' => $story]);

        // escape: false — the count renders a typographic &ndash; entity, and
        // assertSee escapes its needle by default.
        $component->assertSee('prev')->assertSee('next')->assertSee('1&ndash;20 of 45', false);

        // And it actually paginates rather than merely looking like it does.
        $component->call('gotoPage', 2)->assertSee('21&ndash;40 of 45', false);
    }

    public function test_the_plain_blade_story_index_paginator_renders_no_svg(): void
    {
        // Livewire ignores Paginator::defaultView(), so the two paths are fixed
        // separately and both need asserting — fixing one and assuming the
        // other is how half of this bug survives a fix.
        Story::factory()->count(25)->create();

        $html = $this->get(route('stories.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('<svg', $html);

        foreach (self::TAILWIND_MARKERS as $marker) {
            $this->assertStringNotContainsString($marker, $html);
        }
    }

    public function test_the_paginator_is_absent_when_everything_fits_on_one_page(): void
    {
        $story = $this->storyWithScenes(5);

        // Asserted on the markup, not on the arrow glyph. The view emits
        // `&laquo;` and assertDontSee escapes its needle, so looking for a
        // literal guillemet is a test that cannot fail.
        Livewire::test(ScenesGate::class, ['story' => $story])
            ->assertDontSee('class="pagination"', false);
    }

    private function storyWithScenes(int $count): Story
    {
        $story = Story::factory()->status(StoryStatus::ScenesDrafted)->create(['slug' => 'paginate-test']);
        $act = Act::factory()->for($story)->atSequence(1)->create();

        for ($i = 1; $i <= $count; $i++) {
            Scene::factory()->forAct($act)->atSequence($i)->create();
        }

        return $story;
    }
}
