<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The root goes to the dashboard.
     *
     * ---------------------------------------------------------------------
     * THIS TEST WAS CHANGED, AND THE OLD ONE WAS NOT WRONG
     * ---------------------------------------------------------------------
     *
     * It used to assert a redirect to `/stories`, and its reasoning was:
     * "there is no landing page and there should not be one — this is an
     * internal single-operator tool, and the first question is always the
     * same: which story is waiting on a decision."
     *
     * The premise is still right and the conclusion no longer follows. The
     * first question IS "which story is waiting on a decision" — but a
     * paginated list ordered by `updated_at` does not answer it. It answers
     * "what is there". A story waiting at Gate 2 can be on page three, and a
     * story whose asset worker died yesterday sorts next to one that finished
     * cleanly, because both were touched at about the same time.
     *
     * The dashboard answers the question the list was being read for, and it
     * answers a second one the list structurally could not: has anything
     * stopped. So the destination moved and the rule did not.
     *
     * Recorded at this length because a test rewritten quietly to accommodate
     * a change is how coverage disappears — the assertion still passes, and
     * the thing it was protecting is gone.
     */
    public function test_the_root_sends_the_operator_to_the_dashboard(): void
    {
        $this->get('/')->assertRedirect(route('dashboard'));
    }
}
