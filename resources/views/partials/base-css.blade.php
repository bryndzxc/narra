{{--
    One stylesheet for the whole internal tool, inlined.

    No build step on purpose: this is an operator console that has to work when
    Vite is not running, and a render-queue dashboard that fails to load because
    an asset pipeline is down is worse than a plain one that always loads. For
    the same reason there are no webfonts — a console that has to work offline
    cannot have its type depend on a CDN.

    ---------------------------------------------------------------------------
    THE ONE RULE FOR EDITING THIS FILE
    ---------------------------------------------------------------------------

    **No refusal, warning or advisory may get quieter.** This project has been
    saved repeatedly by a message being loud, and a restyle is the easiest place
    in the world to lose that without noticing, because nothing fails and no
    test goes red — the page just becomes slightly calmer than the truth.

    So the alerts here are LOUDER than they were: a 4px accent edge, a stronger
    tint, and a capped measure so a four-line refusal is read rather than
    skimmed. If a future change to this file makes an alert less noticeable than
    the panel next to it, that change is wrong however good it looks.

    ---------------------------------------------------------------------------
    WHAT THIS REVISION CHANGED, AND WHY
    ---------------------------------------------------------------------------

    The console worked and was tiring to read for an hour. Four causes, all
    typographic rather than structural — the structure was right and is
    untouched:

    1. **Hierarchy was inverted.** `h2` was 13px uppercase in the MUTED colour,
       which made every section heading less prominent than the body text under
       it. A page you cannot skim is a page you read linearly, and these pages
       are long. Headings are now real headings.

    2. **One text colour was doing two jobs.** `.muted` carried both the
       explanatory paragraphs — which are long, and which are where the actual
       reasoning lives — and the 11px meta labels. Tuning it for the labels made
       the prose hard work at ~5.4:1. There are three tiers now: body, secondary
       (`.muted`, lifted), and meta (labels, `th`, badges), which get the
       dimmest value.

    3. **Nothing capped the measure.** Explanations ran the full 1180px, which
       is ~150 characters a line against a comfortable 60-80. Prose is capped;
       tables and scene rows are not, because they need the width.

    4. **Everything was one surface.** One panel colour, one border, no
       elevation, so a page was an undifferentiated field of boxes. Panels now
       have a top highlight and a real shadow, and the two surfaces that mean
       something — money and alerts — are visibly not ordinary panels.

    ---------------------------------------------------------------------------
    `.panel.money` NOW EXISTS
    ---------------------------------------------------------------------------

    Three blades write `class="panel money"` — the outline write button, the
    Gate 2 asset dispatch, and the new-story estimate. All three are the screen
    where an operator authorises spending, and NOTHING DEFINED THAT RULE, so all
    three rendered as an ordinary panel. Exactly the defect `.alert.err` had:
    a class the markup has been asking for since it was written, silently
    matching nothing. The money surface is now unmistakable.
--}}
<style>
    :root {
        /*
         * A neutral charcoal rather than the blue-black this started as. The
         * old ground was saturated enough to tint every still on the Gate 2
         * page, which matters here more than it would elsewhere: the operator
         * is judging artwork against it for an hour at a time.
         */
        --bg: #0e0f13;
        --bg-2: #131620;
        --panel: #171a22;
        --panel-2: #1f2330;
        --line: #282d3a;
        --line-2: #363c4d;

        /*
         * Three tiers, not two. `--text` for what is being read, `--muted` for
         * the explanations beside it, `--meta` for labels and column headings.
         * The old file had `--muted` doing the last two jobs at once and had to
         * pick a value that suited neither.
         */
        --text: #e5e8f0;
        --muted: #a2aaba;
        --meta: #7c8598;

        --ok: #4cc264;
        --run: #6cb0ff;
        --fail: #ff6b60;
        --warn: #e0a33a;
        --idle: #333949;
        --money: #f0c258;

        --radius: 10px;
        --radius-sm: 7px;

        /* One shadow, used at two strengths, so surfaces stack consistently. */
        --lift: 0 1px 2px rgba(0, 0, 0, .35), 0 4px 14px -6px rgba(0, 0, 0, .5);
        --lift-lg: 0 2px 4px rgba(0, 0, 0, .4), 0 12px 32px -12px rgba(0, 0, 0, .65);
    }

    * { box-sizing: border-box; }

    ::selection { background: color-mix(in srgb, var(--run) 35%, transparent); }

    body {
        margin: 0;
        background: var(--bg);
        color: var(--text);
        /*
         * 14.5/1.62 rather than 14/1.55. Half a pixel and a little more leading
         * is most of what "hard to look at for an hour" was.
         */
        font: 14.5px/1.62 ui-sans-serif, "Segoe UI Variable Text", "Segoe UI", -apple-system, system-ui, sans-serif;
        -webkit-font-smoothing: antialiased;
        text-rendering: optimizeLegibility;
    }

    a { color: var(--run); text-decoration: none; }
    a:hover { text-decoration: underline; text-underline-offset: 2px; }

    /* Keyboard use is real in a form this dense, and the old file had no ring. */
    :focus-visible {
        outline: 2px solid color-mix(in srgb, var(--run) 70%, transparent);
        outline-offset: 2px;
        border-radius: 3px;
    }

    /* -- Chrome ----------------------------------------------------------- */

    header.top {
        border-bottom: 1px solid var(--line);
        padding: 13px 26px;
        display: flex;
        align-items: center;
        gap: 22px;
        position: sticky;
        top: 0;
        /* Slightly translucent so content scrolling under it is visible as
           motion rather than vanishing at a hard edge. */
        background: color-mix(in srgb, var(--bg) 88%, transparent);
        backdrop-filter: blur(10px);
        z-index: 5;
    }

    header.top .brand {
        font-weight: 700;
        letter-spacing: .16em;
        font-size: 13px;
        color: var(--text);
        padding-right: 4px;
        border-right: 1px solid var(--line);
        margin-right: -6px;
    }

    header.top nav a {
        color: var(--meta);
        font-size: 13px;
        padding: 4px 2px;
        border-bottom: 1.5px solid transparent;
    }

    header.top nav a:hover { color: var(--muted); text-decoration: none; }

    header.top nav a.on {
        color: var(--text);
        border-bottom-color: var(--money);
    }

    header.top .sub { color: var(--meta); font-size: 12.5px; }
    header.top .right { margin-left: auto; }

    main { padding: 26px; max-width: 1180px; margin: 0 auto; }

    footer {
        color: var(--meta);
        font-size: 12.5px;
        padding: 32px 26px 40px;
        text-align: center;
        border-top: 1px solid var(--line);
        margin-top: 40px;
    }

    /* -- Type ------------------------------------------------------------- */

    h1 {
        font-size: 23px;
        line-height: 1.25;
        font-weight: 640;
        letter-spacing: -.011em;
        margin: 0 0 6px;
    }

    /*
     * Was 13px uppercase in the muted colour, which put every section heading
     * BELOW its own body text in the visual order. These pages are long and
     * skimming them is how an operator finds the thing they came for.
     */
    h2 {
        font-size: 15.5px;
        font-weight: 620;
        letter-spacing: -.005em;
        color: var(--text);
        margin: 32px 0 12px;
        padding-top: 14px;
        border-top: 1px solid var(--line);
    }

    /* The first heading on a page has nothing above it to be separated from. */
    main > h2:first-child { margin-top: 0; padding-top: 0; border-top: none; }

    h3 { font-size: 14.5px; font-weight: 620; margin: 0 0 8px; }

    p { margin: 0 0 10px; max-width: 78ch; }
    p:last-child { margin-bottom: 0; }

    /* -- Surfaces --------------------------------------------------------- */

    .panel {
        background: linear-gradient(var(--panel), color-mix(in srgb, var(--panel) 92%, #000));
        border: 1px solid var(--line);
        border-radius: var(--radius);
        padding: 18px;
        margin-bottom: 16px;
        box-shadow: var(--lift);
        /* A one-pixel highlight along the top edge. It is what stops a column
           of panels reading as one flat field. */
        position: relative;
    }

    .panel::before {
        content: '';
        position: absolute;
        inset: 0 0 auto;
        height: 1px;
        border-radius: var(--radius) var(--radius) 0 0;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, .06) 18%, rgba(255, 255, 255, .06) 82%, transparent);
        pointer-events: none;
    }

    /*
     * THE MONEY SURFACE. Three blades have asked for this class since they were
     * written and nothing defined it, so the screen where an operator
     * authorises spending looked exactly like the screen above it.
     */
    .panel.money {
        border-color: color-mix(in srgb, var(--money) 45%, transparent);
        background:
            linear-gradient(color-mix(in srgb, var(--money) 7%, var(--panel)),
                            color-mix(in srgb, var(--money) 3%, var(--panel)));
        box-shadow: var(--lift-lg), inset 3px 0 0 var(--money);
        padding-left: 21px;
    }

    .panel.muted { opacity: .82; box-shadow: none; }

    .row { display: flex; gap: 14px; align-items: center; flex-wrap: wrap; }
    .grow { flex: 1; }
    .right { margin-left: auto; }

    /*
     * Secondary prose. Lifted from the old value, which was tuned for 11px
     * labels and left the actual explanations — where the reasoning lives — at
     * about 5.4:1 against the panel.
     */
    .muted { color: var(--muted); }

    /* Capped where it is prose. Tables, rows and inline meta are exempt below. */
    div.muted, span.muted.small { max-width: 82ch; }
    td .muted, th .muted, .row .muted, .scene .muted { max-width: none; }

    .small { font-size: 12.5px; }

    .mono {
        font-family: ui-monospace, "Cascadia Mono", "Cascadia Code", Consolas, "SF Mono", monospace;
        font-size: 12.5px;
        font-variant-ligatures: none;
    }

    /* -- Badges ----------------------------------------------------------- */

    .badge {
        display: inline-block;
        padding: 2px 9px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 650;
        text-transform: uppercase;
        letter-spacing: .07em;
        border: 1px solid var(--line-2);
        background: var(--panel-2);
        color: var(--meta);
        vertical-align: 1px;
        white-space: nowrap;
    }

    .badge.muted { color: var(--meta); }
    .badge.ok { color: var(--ok); border-color: color-mix(in srgb, var(--ok) 45%, transparent); background: color-mix(in srgb, var(--ok) 12%, var(--panel-2)); }
    .badge.run { color: var(--run); border-color: color-mix(in srgb, var(--run) 45%, transparent); background: color-mix(in srgb, var(--run) 12%, var(--panel-2)); }
    .badge.fail { color: var(--fail); border-color: color-mix(in srgb, var(--fail) 50%, transparent); background: color-mix(in srgb, var(--fail) 14%, var(--panel-2)); }
    .badge.warn { color: var(--warn); border-color: color-mix(in srgb, var(--warn) 50%, transparent); background: color-mix(in srgb, var(--warn) 14%, var(--panel-2)); }
    .badge.money { color: var(--money); border-color: color-mix(in srgb, var(--money) 55%, transparent); background: color-mix(in srgb, var(--money) 14%, var(--panel-2)); }

    /* -- Progress bars ---------------------------------------------------- */

    .bar {
        height: 7px;
        background: var(--idle);
        border-radius: 4px;
        overflow: hidden;
        display: flex;
        box-shadow: inset 0 1px 2px rgba(0, 0, 0, .35);
    }

    .bar span { display: block; height: 100%; }
    .bar .done { background: var(--ok); }
    .bar .bad { background: var(--fail); }
    .bar .busy { background: var(--run); }
    .bar .warnfill { background: var(--warn); }

    /* -- Tables ----------------------------------------------------------- */

    table { width: 100%; border-collapse: collapse; }

    th, td {
        text-align: left;
        padding: 9px 11px;
        border-bottom: 1px solid var(--line);
        vertical-align: top;
    }

    th {
        color: var(--meta);
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .07em;
        font-weight: 650;
        /* Sticky, because the render page and the cost ledger are long enough
           that the column you are reading scrolls away from its own heading.
           The offset is the site header's height; it is the one number here
           that has to agree with something else on the page. */
        position: sticky;
        top: 48px;
        background: var(--panel);
        z-index: 1;
    }

    /* Not in the money panel: its ground is gold-tinted, so a sticky heading
       painted in the ordinary panel colour would tear a visible band across
       the one surface that has to look deliberate. */
    .panel.money th { position: static; background: none; }

    tbody tr:hover td { background: color-mix(in srgb, var(--run) 4%, transparent); }
    tr:last-child td { border-bottom: none; }

    /* -- Alerts ------------------------------------------------------------
     *
     * LOUDER than the version this replaces, deliberately. A refusal is the
     * most valuable thing on any of these pages and the restyle was the moment
     * to lose it, so it gained an accent edge, a stronger tint and a shadow.
     *
     * The measure is capped at 84ch, which is not a reduction in prominence: a
     * four-line refusal running the full width gets skimmed, and skimmed is
     * indistinguishable from unread.
     */
    .alert {
        border-radius: var(--radius);
        padding: 13px 17px;
        margin-bottom: 16px;
        border: 1px solid;
        max-width: 96ch;
        box-shadow: var(--lift);
        line-height: 1.6;
    }

    .alert strong { font-weight: 660; }

    /*
     * Consecutive alerts of the SAME kind close up, so a run of them reads as
     * one block of warnings rather than fourteen separate shouts.
     *
     * Prominence is untouched and that is deliberate: every box keeps its own
     * accent edge, its own tint and its own shadow. Only the gap between them
     * changes. Gate 2 on a 168-scene story emits fourteen consecutive
     * `style_notes` advisories — one per character — and at full spacing the
     * page is a column of identical amber rectangles nobody can tell apart.
     * Making any of them quieter is not on the table; letting them cluster
     * costs nothing and makes the SET legible.
     */
    .alert + .alert { margin-top: -6px; }
    .alert.fail + .alert.fail,
    .alert.err + .alert.err,
    .alert.warn + .alert.warn,
    .alert.ok + .alert.ok { margin-top: -8px; }
    .alert ul { padding-left: 20px; }
    .alert li { margin-bottom: 4px; max-width: 84ch; }

    .alert.fail,
    /* `err` is what a dozen call sites already write, and for a long time
       nothing defined it — so every refusal in this app rendered in the default
       border colour. Same treatment as .fail rather than a new colour: they
       mean the same thing, and two reds would just be a second answer. */
    .alert.err {
        border-color: color-mix(in srgb, var(--fail) 55%, transparent);
        background: color-mix(in srgb, var(--fail) 13%, var(--panel));
        box-shadow: var(--lift), inset 4px 0 0 var(--fail);
        padding-left: 21px;
    }

    .alert.warn {
        border-color: color-mix(in srgb, var(--warn) 55%, transparent);
        background: color-mix(in srgb, var(--warn) 13%, var(--panel));
        box-shadow: var(--lift), inset 4px 0 0 var(--warn);
        padding-left: 21px;
    }

    .alert.ok {
        border-color: color-mix(in srgb, var(--ok) 45%, transparent);
        background: color-mix(in srgb, var(--ok) 10%, var(--panel));
        box-shadow: var(--lift), inset 4px 0 0 var(--ok);
        padding-left: 21px;
    }

    .alert.money {
        border-color: color-mix(in srgb, var(--money) 60%, transparent);
        background: color-mix(in srgb, var(--money) 13%, var(--panel));
        box-shadow: var(--lift), inset 4px 0 0 var(--money);
        padding-left: 21px;
    }

    /* -- Forms ------------------------------------------------------------
     *
     * Dense by design: this is a tool, not a landing page.
     */
    label {
        display: block;
        font-size: 11.5px;
        text-transform: uppercase;
        letter-spacing: .07em;
        font-weight: 650;
        color: var(--meta);
        margin-bottom: 6px;
    }

    input[type=text], input[type=number], input[type=datetime-local], textarea, select {
        width: 100%;
        background: var(--bg-2);
        border: 1px solid var(--line-2);
        border-radius: var(--radius-sm);
        color: var(--text);
        padding: 9px 11px;
        font: inherit;
        box-shadow: inset 0 1px 2px rgba(0, 0, 0, .25);
    }

    textarea { resize: vertical; min-height: 74px; line-height: 1.6; }

    input:focus, textarea:focus, select:focus {
        outline: none;
        border-color: color-mix(in srgb, var(--run) 70%, transparent);
        box-shadow: inset 0 1px 2px rgba(0, 0, 0, .25), 0 0 0 3px color-mix(in srgb, var(--run) 22%, transparent);
    }

    input:disabled, textarea:disabled, select:disabled {
        opacity: .55;
        cursor: not-allowed;
        background: var(--panel);
    }

    .field { margin-bottom: 18px; }
    .field:last-child { margin-bottom: 0; }
    .field .muted.small { margin-top: 7px; }

    button {
        background: linear-gradient(var(--panel-2), color-mix(in srgb, var(--panel-2) 88%, #000));
        border: 1px solid var(--line-2);
        color: var(--text);
        border-radius: var(--radius-sm);
        padding: 8px 15px;
        font: inherit;
        font-size: 13px;
        font-weight: 550;
        cursor: pointer;
        box-shadow: var(--lift);
    }

    button:hover:not(:disabled) { border-color: var(--meta); filter: brightness(1.12); }
    button:active:not(:disabled) { transform: translateY(1px); box-shadow: none; }
    button:disabled { opacity: .42; cursor: not-allowed; box-shadow: none; }

    button.primary {
        background: linear-gradient(color-mix(in srgb, var(--ok) 26%, var(--panel-2)), color-mix(in srgb, var(--ok) 16%, var(--panel-2)));
        border-color: color-mix(in srgb, var(--ok) 55%, transparent);
        color: #eefcf0;
    }

    button.danger {
        background: linear-gradient(color-mix(in srgb, var(--fail) 22%, var(--panel-2)), color-mix(in srgb, var(--fail) 13%, var(--panel-2)));
        border-color: color-mix(in srgb, var(--fail) 50%, transparent);
        color: #ffeceb;
    }

    /* The gate crossings and the spend buttons. The heaviest thing on a page,
       because they are the decisions that cannot be taken back. */
    button.gate {
        background: linear-gradient(color-mix(in srgb, var(--money) 26%, var(--panel-2)), color-mix(in srgb, var(--money) 15%, var(--panel-2)));
        border-color: color-mix(in srgb, var(--money) 65%, transparent);
        color: #fff6e0;
        font-weight: 660;
    }

    button.tiny { padding: 4px 9px; font-size: 12px; box-shadow: none; }

    .actions { display: flex; gap: 9px; align-items: center; flex-wrap: wrap; }

    /*
     * Pagination. Text controls only, no icons.
     *
     * The framework's default paginator draws its arrows as inline SVG sized by
     * Tailwind utility classes. There is no Tailwind here, so those SVGs
     * rendered at the height of the viewport on the 199-scene Gate 2 page. A
     * text arrow cannot be mis-sized by a stylesheet that never loaded, and
     * this app has exactly one stylesheet to lose.
     */
    .pagination {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 5px;
        margin: 18px 0;
    }

    .pagination .page {
        display: inline-flex;
        align-items: center;
        min-width: 32px;
        justify-content: center;
        padding: 5px 10px;
        font-size: 12.5px;
        font-family: inherit;
        font-weight: 550;
        line-height: 1.4;
        color: var(--text);
        background: var(--panel-2);
        border: 1px solid var(--line-2);
        border-radius: var(--radius-sm);
        cursor: pointer;
        box-shadow: none;
    }

    .pagination a.page:hover,
    .pagination button.page:hover { border-color: var(--meta); }

    .pagination .page.current {
        color: var(--bg);
        border-color: transparent;
        background: var(--run);
        font-weight: 680;
        cursor: default;
    }

    .pagination .page.disabled { opacity: .38; cursor: not-allowed; }
    .pagination .page.gap { border-color: transparent; background: none; cursor: default; }
    .pagination-count { margin-left: 10px; }

    .checks label {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        text-transform: none;
        letter-spacing: 0;
        font-weight: 450;
        font-size: 13.5px;
        line-height: 1.55;
        color: var(--text);
        margin-bottom: 12px;
        max-width: 88ch;
    }

    .checks input { margin-top: 4px; accent-color: var(--ok); width: 15px; height: 15px; flex: none; }

    /* -- Gate stepper ------------------------------------------------------ */

    .gates { display: flex; gap: 9px; margin-bottom: 22px; flex-wrap: wrap; }

    .gates a, .gates span {
        flex: 1 1 200px;
        border: 1px solid var(--line);
        border-radius: var(--radius);
        padding: 11px 14px;
        background: var(--panel);
        color: var(--muted);
        display: block;
        box-shadow: var(--lift);
    }

    .gates a:hover { border-color: var(--line-2); text-decoration: none; }
    .gates .num { font-size: 10.5px; letter-spacing: .1em; text-transform: uppercase; color: var(--meta); }
    .gates .name { color: var(--text); font-weight: 620; }
    .gates .state { font-size: 11.5px; }
    .gates .passed { border-color: color-mix(in srgb, var(--ok) 40%, transparent); }
    .gates .passed .state { color: var(--ok); }

    .gates .current {
        border-color: color-mix(in srgb, var(--money) 65%, transparent);
        background: color-mix(in srgb, var(--money) 10%, var(--panel));
        box-shadow: var(--lift-lg), inset 0 -2px 0 var(--money);
    }

    .gates .current .state { color: var(--money); }
    .gates .locked { opacity: .5; box-shadow: none; }

    /* -- Scene list -------------------------------------------------------- */

    .scene {
        display: flex;
        gap: 16px;
        padding: 14px 0;
        border-bottom: 1px solid var(--line);
    }

    .scene:last-child { border-bottom: none; }

    /* Stills are 16:9 sources at whatever the image model emits — Seedream
       returns 3416x1920 — so the box is always declared here and never left to
       the intrinsic size. This rule used to be scoped `.scene .still`, which
       meant the Gate 4 thumbnail picker, whose markup has no `.scene` ancestor,
       matched nothing and painted a 3416px-wide image into the layout. */
    .still {
        display: block;
        width: 136px;
        height: 76px;
        max-width: 100%;
        object-fit: cover;
        border-radius: var(--radius-sm);
        background: var(--panel-2);
        border: 1px solid var(--line);
        flex: none;
    }

    /* The Gate 4 choosers, where the operator is judging the picture itself. */
    .still.pick { width: 208px; height: 117px; }
    label:hover > .still.pick { border-color: var(--line-2); }
    input:checked + .still.pick { border-color: var(--money); box-shadow: 0 0 0 2px color-mix(in srgb, var(--money) 45%, transparent); }

    /*
     * The composed-thumbnail chooser. A card rather than a loose stack: four
     * compositions with different amounts of explanation under them were
     * ragged, and the operator is comparing them side by side.
     */
    .pickcard {
        display: block;
        width: 336px;
        max-width: 100%;
        text-transform: none;
        letter-spacing: 0;
        font-weight: 450;
        color: var(--text);
        background: var(--panel-2);
        border: 1px solid var(--line);
        border-radius: var(--radius);
        padding: 10px;
        margin: 0 0 12px;
        cursor: pointer;
    }

    .pickcard:hover { border-color: var(--line-2); }
    .pickcard .still.pick { width: 100%; height: 178px; }

    .pickcard .head { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
    .pickcard .head input { accent-color: var(--money); width: 16px; height: 16px; flex: none; margin: 0; }
    .pickcard .why { margin-top: 8px; color: var(--muted); font-size: 12.5px; line-height: 1.5; }
    .pickcard .why .badge { margin: 0 4px 4px 0; }

    /* Chosen. Unmissable, because it is the one that gets uploaded. */
    .pickcard:has(input:checked) {
        border-color: var(--money);
        background: color-mix(in srgb, var(--money) 9%, var(--panel-2));
        box-shadow: var(--lift), 0 0 0 1px color-mix(in srgb, var(--money) 45%, transparent);
    }

    .scene .seq {
        font-family: ui-monospace, "Cascadia Mono", Consolas, monospace;
        color: var(--meta);
        font-size: 12.5px;
        width: 36px;
        flex: none;
        padding-top: 1px;
    }

    .scene .body { flex: 1; min-width: 0; }
    .scene .narration { margin: 0 0 7px; max-width: 88ch; }

    .scene .prompt {
        margin: 0;
        font-size: 12.5px;
        color: var(--meta);
        font-family: ui-monospace, "Cascadia Mono", Consolas, monospace;
        max-width: 100ch;
    }

    .scene .actions { display: flex; flex-direction: column; gap: 5px; flex: none; }

    /* -- Batch heat map ---------------------------------------------------- */

    .grid { display: flex; flex-wrap: wrap; gap: 3px; margin-top: 12px; }
    .cell { width: 14px; height: 14px; border-radius: 3px; background: var(--idle); }
    .cell.succeeded { background: var(--ok); }
    .cell.running { background: var(--run); box-shadow: 0 0 0 1px color-mix(in srgb, var(--run) 60%, transparent); }
    .cell.failed { background: var(--fail); }
    .cell.cancelled { background: var(--warn); }

    .legend { display: flex; gap: 16px; margin-top: 12px; font-size: 11.5px; color: var(--meta); }
    .legend span::before { content: ''; display: inline-block; width: 9px; height: 9px; border-radius: 2px; margin-right: 6px; }
    .legend .l-ok::before { background: var(--ok); }
    .legend .l-run::before { background: var(--run); }
    .legend .l-fail::before { background: var(--fail); }
    .legend .l-queued::before { background: var(--idle); }

    /* -- Preformatted ------------------------------------------------------ */

    pre.err, pre.sheet {
        margin: 8px 0 0;
        padding: 12px 14px;
        background: var(--bg);
        border: 1px solid var(--line-2);
        border-radius: var(--radius-sm);
        white-space: pre-wrap;
        word-break: break-word;
        font-family: ui-monospace, "Cascadia Mono", Consolas, monospace;
        font-size: 12.5px;
        line-height: 1.6;
        box-shadow: inset 0 1px 3px rgba(0, 0, 0, .35);
    }

    /* A failure's own words, in the failure's own colour. */
    pre.err { color: #ffb9b2; border-color: color-mix(in srgb, var(--fail) 35%, transparent); }

    video { width: 100%; border-radius: var(--radius); background: #000; box-shadow: var(--lift-lg); }

    .error { color: var(--fail); font-size: 12.5px; margin-top: 5px; font-weight: 550; }

    /* -- Motion ------------------------------------------------------------ */

    @media (prefers-reduced-motion: reduce) {
        * { animation: none !important; transition: none !important; }
    }
</style>
