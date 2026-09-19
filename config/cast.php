<?php

/*
|--------------------------------------------------------------------------
| The outline's cast
|--------------------------------------------------------------------------
|
| Every person a story names is declared by the outline, before the spine is
| written. See App\Support\OutlineCast and CLAUDE.md 3f.
|
*/

return [

    /*
    | How many named people an outline is asked for, at most, BESIDES THE
    | NARRATOR, and above which Gate 1 warns. NOT a refusal: a cast is
    | editorial, the operator can delete a row at Gate 1 for free, and a
    | refusal would re-bill the outline.
    |
    | Besides the narrator since 2026-09-18. The narrator is a required outline
    | field of their own (see OutlineCast::castArrayRoles), and story 37's
    | refused outline had filled all eight rows with everyone else — the
    | narrator was competing for the last seat and lost it.
    |
    | 8, from two readings and a margin. The reference transcript names six
    | people (Sophia, Willow, George, Maria, Luis, Kim) and leaves the other man
    | and both sets of parents unnamed. The operator's premise batch of
    | 2026-09-15 names six. Two more is room for a parent who is drawn often
    | enough to need a face. Measured against the stored casts before this
    | existed: 8 to 13, median 10, with 76% of them already named in the spine.
    */
    'max_named' => (int) env('CAST_MAX_NAMED', 8),

    /*
    | How many people a GENERATED premise names, besides the narrator. The
    | operator's own premise batch of 2026-09-15 named six, and so does the
    | reference transcript. Below max_named on purpose: the outline adds the
    | people the spine needs, and a premise that spends the whole budget leaves
    | it none. The premise checks warn above it.
    */
    'premise_named' => (int) env('CAST_PREMISE_NAMED', 6),

    /*
    | How many earlier stories a new cast is checked against for reused names.
    |
    | The fix for the example-name reuse: Grace Zhou was in four of the last
    | five casts, Wang Suhua in four, because the locale guidance's example
    | names were read as a list to draw from. The outline prompt lists the
    | names these stories used as unavailable, and GenerateOutline refuses a
    | cast that reuses one.
    |
    | Ten, not five, because the operator runs premises in batches: a window of
    | five is filled by the batch itself and forgets the published videos a
    | viewer has actually seen.
    */
    'recent_story_window' => (int) env('CAST_RECENT_STORY_WINDOW', 10),

];
