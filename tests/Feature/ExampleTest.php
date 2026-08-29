<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * There is no landing page and there should not be one. This is an internal
     * single-operator tool, and the first question is always the same: which
     * story is waiting on a decision. The root goes there.
     */
    public function test_the_root_sends_the_operator_to_the_story_list(): void
    {
        $this->get('/')->assertRedirect(route('stories.index'));
    }
}
