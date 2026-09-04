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
    /*
     * ═══════════════════════════════════════════════════════════════════════
     *  THE TOKEN LAYER
     * ═══════════════════════════════════════════════════════════════════════
     *
     * Two palettes and one set of names. Every rule below this block reads a
     * SEMANTIC token (`--panel`, `--fail-ink`) and never a raw colour, so a
     * theme is a remapping rather than a second stylesheet.
     *
     * ---------------------------------------------------------------------
     * WHY THE RAW VALUES ARE NAMED AND THE MAPPING IS NOT
     * ---------------------------------------------------------------------
     *
     * CSS has no way to write one dark block that answers both an explicit
     * `[data-theme="dark"]` and a `prefers-color-scheme` media query, so the
     * REMAP is written twice. That is the shape this project has been bitten
     * by repeatedly — two hand-maintained copies of one rule, agreeing only on
     * the day they were written.
     *
     * So the duplication is pushed to the cheapest possible place. Every
     * literal lives exactly once, in `--d-*` and `--l-*` below; what is
     * duplicated is only the line `--panel: var(--d-panel)`, which cannot
     * drift in VALUE because it holds none. And the two copies are not trusted
     * to stay identical either: `tools/theme-audit.php` refuses them if they
     * diverge, because "they should match" is a comment and a comment has
     * never stopped anything in this codebase.
     *
     * ---------------------------------------------------------------------
     * WHY EVERY STATUS COLOUR HAS AN `-ink` TWIN
     * ---------------------------------------------------------------------
     *
     * The dark console used one token per status for three jobs: the border,
     * the tint, and the TEXT. That works when the ground is near-black —
     * #f0c258 gold is legible on #171a22 and also makes a good edge. On white
     * it is not: gold-on-white measures about 1.8:1, which is not a colour
     * choice, it is a message nobody can read.
     *
     * Since the whole point of this file is that a refusal is loud, a warning
     * whose text quietly fails contrast is the single worst thing a light
     * theme could introduce here. So the hue (edges, fills, accent bars) and
     * the ink (text) are separate tokens. In dark they are the same value and
     * nothing changes; in light the ink is darkened until it reads.
     */
    :root {
        color-scheme: light;

        /* ── Dark palette ────────────────────────────────────────────────
         *
         * A neutral charcoal rather than the blue-black this started as. The
         * old ground was saturated enough to tint every still on the Gate 2
         * page, which matters here more than it would elsewhere: the operator
         * is judging artwork against it for an hour at a time.
         *
         * These are the console's shipped values, carried over unchanged.
         */
        --d-bg: #0e0f13;
        --d-bg-2: #131620;
        --d-panel: #171a22;
        --d-panel-2: #1f2330;
        --d-line: #282d3a;
        --d-line-2: #363c4d;

        /*
         * Three tiers, not two. `--text` for what is being read, `--muted` for
         * the explanations beside it, `--meta` for labels and column headings.
         * The old file had `--muted` doing the last two jobs at once and had to
         * pick a value that suited neither.
         */
        --d-text: #e5e8f0;
        --d-muted: #a2aaba;
        --d-meta: #7c8598;

        --d-ok: #4cc264;
        --d-run: #6cb0ff;
        --d-fail: #ff6b60;
        --d-warn: #e0a33a;
        --d-idle: #333949;
        --d-money: #f0c258;

        /* On this ground the hue IS the ink. Stated rather than implied, so
           the light theme reads as a divergence from a rule and not as a
           second rule. */
        --d-ok-ink: #4cc264;
        --d-run-ink: #6cb0ff;
        --d-fail-ink: #ff6b60;
        --d-warn-ink: #e0a33a;
        --d-money-ink: #f0c258;

        /* Text sitting ON a filled accent. */
        --d-on-accent: #0e0f13;
        --d-primary-ink: #eefcf0;
        --d-danger-ink: #ffeceb;
        --d-gate-ink: #fff6e0;
        --d-err-ink: #ffb9b2;

        /* Surfaces. The panel gradient and its one-pixel top highlight are
           tokens rather than expressions in the rule, because the light theme
           wants a different answer and not a different percentage. */
        --d-panel-from: var(--d-panel);
        --d-panel-to: color-mix(in srgb, var(--d-panel) 92%, #000);
        --d-panel-edge: rgba(255, 255, 255, .06);
        --d-btn-from: var(--d-panel-2);
        --d-btn-to: color-mix(in srgb, var(--d-panel-2) 88%, #000);
        --d-primary-from: color-mix(in srgb, var(--d-ok) 26%, var(--d-panel-2));
        --d-primary-to: color-mix(in srgb, var(--d-ok) 16%, var(--d-panel-2));
        --d-danger-from: color-mix(in srgb, var(--d-fail) 22%, var(--d-panel-2));
        --d-danger-to: color-mix(in srgb, var(--d-fail) 13%, var(--d-panel-2));
        --d-gate-from: color-mix(in srgb, var(--d-money) 26%, var(--d-panel-2));
        --d-gate-to: color-mix(in srgb, var(--d-money) 15%, var(--d-panel-2));

        /* One shadow, used at two strengths, so surfaces stack consistently. */
        /*
         * THE LOUD SURFACES, as tokens rather than as color-mix expressions
         * inside their own rules.
         *
         * Two reasons, and the second is the one that matters. The first is
         * that the light theme needs a different answer for the money panel: a
         * 7% gold wash on a near-black ground is clearly a different surface,
         * and the same 7% on white is 1.07x from the panel beside it, which is
         * to say invisible. The screen where an operator authorises spending
         * would have looked exactly like the screen above it — the precise
         * defect `.panel.money` was created to fix, reintroduced by a theme.
         *
         * The second: `tools/theme-audit.php` measures how far each of these
         * separates from an ordinary panel, and it has to measure what the CSS
         * actually paints. While the percentages lived in the rules the tool
         * carried its own copy of every one of them — a second source of truth
         * for exactly the numbers it exists to police, agreeing only until
         * somebody tuned a wash and not the audit.
         */
        --d-alert-err-bg: color-mix(in srgb, var(--d-fail) 13%, var(--d-panel));
        --d-alert-warn-bg: color-mix(in srgb, var(--d-warn) 13%, var(--d-panel));
        --d-alert-ok-bg: color-mix(in srgb, var(--d-ok) 10%, var(--d-panel));
        --d-alert-money-bg: color-mix(in srgb, var(--d-money) 13%, var(--d-panel));
        --d-money-panel-from: color-mix(in srgb, var(--d-money) 7%, var(--d-panel));
        --d-money-panel-to: color-mix(in srgb, var(--d-money) 3%, var(--d-panel));
        --d-warnfill-bg: color-mix(in srgb, var(--d-warn) 11%, var(--d-panel));
        --d-gate-current-bg: color-mix(in srgb, var(--d-money) 10%, var(--d-panel));

        --d-lift: 0 1px 2px rgba(0, 0, 0, .35), 0 4px 14px -6px rgba(0, 0, 0, .5);
        --d-lift-lg: 0 2px 4px rgba(0, 0, 0, .4), 0 12px 32px -12px rgba(0, 0, 0, .65);
        --d-inset: inset 0 1px 2px rgba(0, 0, 0, .25);
        --d-inset-bar: inset 0 1px 2px rgba(0, 0, 0, .35);
        --d-inset-well: inset 0 1px 3px rgba(0, 0, 0, .35);

        /* ── Light palette ───────────────────────────────────────────────
         *
         * Cool near-white rather than pure white: the Gate 2 page is judged
         * against this ground for an hour at a time, and #fff throws more
         * light at an operator than a panel needs to be distinguishable.
         *
         * The status hues are DARKER than their dark-theme twins rather than
         * lighter. A border at 55% opacity has to survive on white, and a
         * pastel edge on a pale tint is how a warning becomes decoration.
         */
        --l-bg: #f6f7f9;
        --l-bg-2: #eef0f4;
        --l-panel: #ffffff;
        --l-panel-2: #f2f4f7;
        --l-line: #e2e5eb;
        --l-line-2: #ccd2dc;

        --l-text: #11141a;
        --l-muted: #4d5563;
        --l-meta: #6b7382;

        --l-ok: #1a9d4d;
        --l-run: #2563eb;
        --l-fail: #dc2626;
        --l-warn: #d97706;
        --l-idle: #dde1e8;
        --l-money: #c98a04;

        --l-ok-ink: #0d7233;
        --l-run-ink: #1d4ed8;
        --l-fail-ink: #b3170f;
        --l-warn-ink: #8a5a06;
        --l-money-ink: #7d5300;

        --l-on-accent: #ffffff;

        /*
         * SOLID, not tinted. The dark theme can make a decision button loud
         * with a 26% wash because the ground is near-black; the same wash on
         * white is a pale rectangle. The gate and spend buttons are the two
         * presses that cannot be taken back, so in this theme they are filled
         * and their text is white.
         */
        --l-primary-ink: #ffffff;
        --l-danger-ink: #ffffff;
        /*
         * Near-black on bright gold, where the other two are white on a fill.
         *
         * Measured, not chosen: white on a gold dark enough to carry it is
         * 2.75:1, and darkening the gold until white works produces a brown
         * that is quieter than the panel beside it. Inverting keeps the fill at
         * full brightness — so the loudest control on the page is also the one
         * with the most contrast, which is the right way round.
         */
        --l-gate-ink: #33210a;
        --l-err-ink: #96150e;

        --l-panel-from: #ffffff;
        --l-panel-to: #fcfdfe;
        --l-panel-edge: rgba(255, 255, 255, 0);
        --l-btn-from: #ffffff;
        --l-btn-to: #f4f6f9;
        --l-primary-from: #12833f;
        --l-primary-to: #0d6b33;
        --l-danger-from: #c8261d;
        --l-danger-to: #a81a12;
        --l-gate-from: #edaa2b;
        --l-gate-to: #d99310;

        /*
         * The alert washes hold their percentages: measured on white they
         * separate by 1.12-1.22x, the same band they occupy on the dark
         * ground. The money panel does NOT hold its percentage — 7% gold on
         * white is 1.07x and reads as an ordinary panel — so it is tripled
         * here. The tint is the only thing that differs; the gold edge and the
         * heavier shadow are shared by both themes.
         */
        --l-alert-err-bg: color-mix(in srgb, var(--l-fail) 13%, var(--l-panel));
        --l-alert-warn-bg: color-mix(in srgb, var(--l-warn) 13%, var(--l-panel));
        --l-alert-ok-bg: color-mix(in srgb, var(--l-ok) 10%, var(--l-panel));
        --l-alert-money-bg: color-mix(in srgb, var(--l-money) 13%, var(--l-panel));
        --l-money-panel-from: color-mix(in srgb, var(--l-money) 22%, var(--l-panel));
        --l-money-panel-to: color-mix(in srgb, var(--l-money) 15%, var(--l-panel));
        --l-warnfill-bg: color-mix(in srgb, var(--l-warn) 11%, var(--l-panel));
        --l-gate-current-bg: color-mix(in srgb, var(--l-money) 10%, var(--l-panel));

        --l-lift: 0 1px 2px rgba(16, 24, 40, .05), 0 4px 14px -6px rgba(16, 24, 40, .14);
        --l-lift-lg: 0 2px 4px rgba(16, 24, 40, .07), 0 12px 32px -12px rgba(16, 24, 40, .22);
        --l-inset: inset 0 1px 2px rgba(16, 24, 40, .05);
        --l-inset-bar: inset 0 1px 2px rgba(16, 24, 40, .09);
        --l-inset-well: inset 0 1px 3px rgba(16, 24, 40, .06);

        /* ── Semantic names. Light is the default. ───────────────────── */
        --bg: var(--l-bg);
        --bg-2: var(--l-bg-2);
        --panel: var(--l-panel);
        --panel-2: var(--l-panel-2);
        --line: var(--l-line);
        --line-2: var(--l-line-2);
        --text: var(--l-text);
        --muted: var(--l-muted);
        --meta: var(--l-meta);
        --ok: var(--l-ok);
        --run: var(--l-run);
        --fail: var(--l-fail);
        --warn: var(--l-warn);
        --idle: var(--l-idle);
        --money: var(--l-money);
        --ok-ink: var(--l-ok-ink);
        --run-ink: var(--l-run-ink);
        --fail-ink: var(--l-fail-ink);
        --warn-ink: var(--l-warn-ink);
        --money-ink: var(--l-money-ink);
        --on-accent: var(--l-on-accent);
        --primary-ink: var(--l-primary-ink);
        --danger-ink: var(--l-danger-ink);
        --gate-ink: var(--l-gate-ink);
        --err-ink: var(--l-err-ink);
        --panel-from: var(--l-panel-from);
        --panel-to: var(--l-panel-to);
        --panel-edge: var(--l-panel-edge);
        --btn-from: var(--l-btn-from);
        --btn-to: var(--l-btn-to);
        --primary-from: var(--l-primary-from);
        --primary-to: var(--l-primary-to);
        --danger-from: var(--l-danger-from);
        --danger-to: var(--l-danger-to);
        --gate-from: var(--l-gate-from);
        --gate-to: var(--l-gate-to);
        --alert-err-bg: var(--l-alert-err-bg);
        --alert-warn-bg: var(--l-alert-warn-bg);
        --alert-ok-bg: var(--l-alert-ok-bg);
        --alert-money-bg: var(--l-alert-money-bg);
        --money-panel-from: var(--l-money-panel-from);
        --money-panel-to: var(--l-money-panel-to);
        --warnfill-bg: var(--l-warnfill-bg);
        --gate-current-bg: var(--l-gate-current-bg);
        --lift: var(--l-lift);
        --lift-lg: var(--l-lift-lg);
        --inset: var(--l-inset);
        --inset-bar: var(--l-inset-bar);
        --inset-well: var(--l-inset-well);

        /* Theme-independent. A letterboxed video mats to black in both. */
        --video-mat: #000;

        /* ── Scale ───────────────────────────────────────────────────────
         *
         * One spacing ramp so a margin is chosen from a set rather than typed.
         * The console had ~230 hand-written `style="margin-top:10px"` and the
         * values were 4, 5, 6, 8, 10, 12 and 14 with no system behind which
         * appeared where.
         */
        --s-hair: 2px;
        --s-1: 4px;
        --s-2: 6px;
        --s-3: 8px;
        --s-4: 10px;
        --s-5: 12px;
        --s-6: 14px;
        --s-7: 16px;
        --s-8: 18px;
        --s-9: 22px;
        --s-10: 26px;
        --s-11: 32px;
        --s-12: 40px;

        --radius: 10px;
        --radius-sm: 7px;
        --radius-lg: 14px;

        /* The one number that has to agree with the chrome. Read by the
           sticky table heading, which would otherwise tear a band across a
           scrolled table at whatever height the header happens to be.
           Was a bare `top: 48px` in the `th` rule with a comment saying it
           had to agree with the header — now it is the same value in both
           places because it is the same token. */
        --chrome-h: 52px;
    }

    /*
     * The dark remap, twice, for the two ways a viewer can ask for it.
     *
     * The explicit choice wins over the system preference, which is why the
     * media query excludes a root that has been stamped `light`. Neither block
     * contains a colour: both are lists of `--x: var(--d-x)`, and
     * `tools/theme-audit.php` fails if they stop being identical.
     */
    @media (prefers-color-scheme: dark) {
        :root:not([data-theme="light"]) {
            color-scheme: dark;
            --bg: var(--d-bg);
            --bg-2: var(--d-bg-2);
            --panel: var(--d-panel);
            --panel-2: var(--d-panel-2);
            --line: var(--d-line);
            --line-2: var(--d-line-2);
            --text: var(--d-text);
            --muted: var(--d-muted);
            --meta: var(--d-meta);
            --ok: var(--d-ok);
            --run: var(--d-run);
            --fail: var(--d-fail);
            --warn: var(--d-warn);
            --idle: var(--d-idle);
            --money: var(--d-money);
            --ok-ink: var(--d-ok-ink);
            --run-ink: var(--d-run-ink);
            --fail-ink: var(--d-fail-ink);
            --warn-ink: var(--d-warn-ink);
            --money-ink: var(--d-money-ink);
            --on-accent: var(--d-on-accent);
            --primary-ink: var(--d-primary-ink);
            --danger-ink: var(--d-danger-ink);
            --gate-ink: var(--d-gate-ink);
            --err-ink: var(--d-err-ink);
            --panel-from: var(--d-panel-from);
            --panel-to: var(--d-panel-to);
            --panel-edge: var(--d-panel-edge);
            --btn-from: var(--d-btn-from);
            --btn-to: var(--d-btn-to);
            --primary-from: var(--d-primary-from);
            --primary-to: var(--d-primary-to);
            --danger-from: var(--d-danger-from);
            --danger-to: var(--d-danger-to);
            --gate-from: var(--d-gate-from);
            --gate-to: var(--d-gate-to);
            --alert-err-bg: var(--d-alert-err-bg);
            --alert-warn-bg: var(--d-alert-warn-bg);
            --alert-ok-bg: var(--d-alert-ok-bg);
            --alert-money-bg: var(--d-alert-money-bg);
            --money-panel-from: var(--d-money-panel-from);
            --money-panel-to: var(--d-money-panel-to);
            --warnfill-bg: var(--d-warnfill-bg);
            --gate-current-bg: var(--d-gate-current-bg);
            --lift: var(--d-lift);
            --lift-lg: var(--d-lift-lg);
            --inset: var(--d-inset);
            --inset-bar: var(--d-inset-bar);
            --inset-well: var(--d-inset-well);
        }
    }

    :root[data-theme="dark"] {
        color-scheme: dark;
        --bg: var(--d-bg);
        --bg-2: var(--d-bg-2);
        --panel: var(--d-panel);
        --panel-2: var(--d-panel-2);
        --line: var(--d-line);
        --line-2: var(--d-line-2);
        --text: var(--d-text);
        --muted: var(--d-muted);
        --meta: var(--d-meta);
        --ok: var(--d-ok);
        --run: var(--d-run);
        --fail: var(--d-fail);
        --warn: var(--d-warn);
        --idle: var(--d-idle);
        --money: var(--d-money);
        --ok-ink: var(--d-ok-ink);
        --run-ink: var(--d-run-ink);
        --fail-ink: var(--d-fail-ink);
        --warn-ink: var(--d-warn-ink);
        --money-ink: var(--d-money-ink);
        --on-accent: var(--d-on-accent);
        --primary-ink: var(--d-primary-ink);
        --danger-ink: var(--d-danger-ink);
        --gate-ink: var(--d-gate-ink);
        --err-ink: var(--d-err-ink);
        --panel-from: var(--d-panel-from);
        --panel-to: var(--d-panel-to);
        --panel-edge: var(--d-panel-edge);
        --btn-from: var(--d-btn-from);
        --btn-to: var(--d-btn-to);
        --primary-from: var(--d-primary-from);
        --primary-to: var(--d-primary-to);
        --danger-from: var(--d-danger-from);
        --danger-to: var(--d-danger-to);
        --gate-from: var(--d-gate-from);
        --gate-to: var(--d-gate-to);
        --alert-err-bg: var(--d-alert-err-bg);
        --alert-warn-bg: var(--d-alert-warn-bg);
        --alert-ok-bg: var(--d-alert-ok-bg);
        --alert-money-bg: var(--d-alert-money-bg);
        --money-panel-from: var(--d-money-panel-from);
        --money-panel-to: var(--d-money-panel-to);
        --warnfill-bg: var(--d-warnfill-bg);
        --gate-current-bg: var(--d-gate-current-bg);
        --lift: var(--d-lift);
        --lift-lg: var(--d-lift-lg);
        --inset: var(--d-inset);
        --inset-bar: var(--d-inset-bar);
        --inset-well: var(--d-inset-well);
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

    a { color: var(--run-ink); text-decoration: none; }
    a:hover { text-decoration: underline; text-underline-offset: 2px; }

    /* Keyboard use is real in a form this dense, and the old file had no ring. */
    :focus-visible {
        outline: 2px solid color-mix(in srgb, var(--run) 70%, transparent);
        outline-offset: 2px;
        border-radius: 3px;
    }

    /* -- Chrome ------------------------------------------------------------
     *
     * A left rail rather than a top bar. The console has four destinations and
     * a growing amount of per-page context; the horizontal bar was spending
     * the widest axis on four words and had nowhere to put anything else.
     *
     * The rail is its own scroll context and does not move, so the nav is in
     * the same place on a 12-row index and on a 270-scene Gate 2 page. That
     * matters more here than it would elsewhere: the pages this tool is made
     * of are LONG, and "scroll back up to leave" is a real cost when it is
     * paid every few minutes for a year.
     */

    .shell { display: flex; align-items: stretch; min-height: 100vh; }

    .side {
        width: 216px;
        flex: none;
        position: sticky;
        top: 0;
        height: 100vh;
        display: flex;
        flex-direction: column;
        gap: var(--s-7);
        padding: var(--s-8) var(--s-5) var(--s-5);
        border-right: 1px solid var(--line);
        background: var(--bg-2);
    }

    .side .brand {
        font-weight: 700;
        letter-spacing: .16em;
        font-size: 13px;
        color: var(--text);
        padding: 0 var(--s-3);
    }

    .side .brand:hover { text-decoration: none; color: var(--text); }

    .side nav { display: flex; flex-direction: column; gap: 2px; }

    .side nav a {
        display: flex;
        align-items: center;
        gap: var(--s-4);
        color: var(--muted);
        font-size: 13.5px;
        font-weight: 500;
        padding: var(--s-2) var(--s-3);
        border-radius: var(--radius-sm);
        border-left: 2px solid transparent;
    }

    .side nav a:hover {
        color: var(--text);
        background: color-mix(in srgb, var(--text) 5%, transparent);
        text-decoration: none;
    }

    /* The current destination, marked on the edge as well as in the fill —
       the fill alone is a few percent of luminance and disappears entirely on
       a bright screen in a lit room. */
    .side nav a.on {
        color: var(--text);
        font-weight: 620;
        background: color-mix(in srgb, var(--money) 12%, transparent);
        border-left-color: var(--money);
    }

    .side nav .ico { width: 15px; text-align: center; opacity: .75; font-size: 12px; }

    .sidefoot { margin-top: auto; display: flex; flex-direction: column; gap: var(--s-4); }
    .sidefoot p { color: var(--meta); margin: 0; max-width: none; line-height: 1.5; }

    .themetoggle {
        display: flex;
        align-items: center;
        gap: var(--s-3);
        width: 100%;
        text-align: left;
        justify-content: flex-start;
    }

    .content { flex: 1; min-width: 0; display: flex; flex-direction: column; }

    header.top {
        border-bottom: 1px solid var(--line);
        padding: 0 var(--s-11);
        height: var(--chrome-h);
        display: flex;
        align-items: center;
        gap: var(--s-9);
        position: sticky;
        top: 0;
        /* Slightly translucent so content scrolling under it is visible as
           motion rather than vanishing at a hard edge. */
        background: color-mix(in srgb, var(--bg) 88%, transparent);
        backdrop-filter: blur(10px);
        z-index: 5;
    }

    header.top .where {
        font-size: 13px;
        font-weight: 620;
        letter-spacing: -.005em;
        color: var(--text);
    }

    header.top .sub { color: var(--meta); font-size: 12.5px; }
    header.top .right { margin-left: auto; }

    /*
     * Fills the space beside the rail. No max-width, deliberately.
     *
     * A centred 1180px column is a reading measure for a marketing page, and
     * this is neither: at 1900px it left ~250px of dead ground on both sides
     * of a console whose main content is a 200-row scene list and a five-column
     * job table. Those want the room, and an operator who chose a wide monitor
     * chose it for this.
     *
     * Line length is capped where line length actually matters — `p` at 78ch,
     * `.muted` prose at 82ch, `.alert` at 96ch, scene narration at 88ch. Those
     * caps are per-block and already existed, which is what makes removing the
     * page-level one safe: prose does not get wider, only tables do.
     */
    main { padding: var(--s-10) var(--s-11); width: 100%; flex: 1; min-width: 0; }

    footer {
        color: var(--meta);
        font-size: 12.5px;
        padding: var(--s-11) var(--s-11) var(--s-12);
        text-align: center;
        border-top: 1px solid var(--line);
        margin-top: var(--s-12);
    }

    /*
     * Narrow: the rail lies down across the top rather than disappearing
     * behind a control that has to be found. There is no hamburger here — a
     * nav of four items that hides itself is worse than a nav that wraps.
     */
    @media (max-width: 860px) {
        .shell { display: block; }

        .side {
            width: auto;
            height: auto;
            position: static;
            flex-direction: row;
            align-items: center;
            flex-wrap: wrap;
            gap: var(--s-5);
            padding: var(--s-4) var(--s-6);
            border-right: none;
            border-bottom: 1px solid var(--line);
        }

        .side nav { flex-direction: row; flex-wrap: wrap; }
        .side nav a { border-left: none; border-bottom: 2px solid transparent; }
        .side nav a.on { border-left: none; border-bottom-color: var(--money); }
        .sidefoot { margin-top: 0; margin-left: auto; flex-direction: row; align-items: center; }
        .sidefoot p { display: none; }
        main { padding: var(--s-7); }
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
        background: linear-gradient(var(--panel-from), var(--panel-to));
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
        background: linear-gradient(90deg, transparent, var(--panel-edge) 18%, var(--panel-edge) 82%, transparent);
        pointer-events: none;
    }

    /*
     * THE MONEY SURFACE. Three blades have asked for this class since they were
     * written and nothing defined it, so the screen where an operator
     * authorises spending looked exactly like the screen above it.
     */
    .panel.money {
        border-color: color-mix(in srgb, var(--money) 45%, transparent);
        background: linear-gradient(var(--money-panel-from), var(--money-panel-to));
        box-shadow: var(--lift-lg), inset 3px 0 0 var(--money);
        padding-left: 21px;
    }

    .panel.muted { opacity: .82; box-shadow: none; }

    /*
     * A panel whose only child is a full-bleed table. Seven blades wrote
     * `style="padding:0"` for this; it is one thing, so it gets one name.
     *
     * NO `overflow: hidden`, and that is load-bearing rather than an omission.
     * It was here to clip the table's square corners to the panel's radius,
     * and it broke every table in the console: `overflow: hidden` makes the
     * panel its own scrollport, so `th { position: sticky; top: var(--chrome-h) }`
     * stopped resolving against the page and started resolving against the
     * PANEL — pushing the header row 52px down from the panel's own top edge
     * and leaving an empty band above it with the header's bottom border
     * stranded across it.
     *
     * The sticky offset exists so a column heading does not scroll away under
     * the site header on a 270-scene table. Clipping four corners is not worth
     * breaking that, and the corners were never clipped before this either.
     */
    .panel.flush { padding: 0; }

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
    .badge.ok { color: var(--ok-ink); border-color: color-mix(in srgb, var(--ok) 45%, transparent); background: color-mix(in srgb, var(--ok) 12%, var(--panel-2)); }
    .badge.run { color: var(--run-ink); border-color: color-mix(in srgb, var(--run) 45%, transparent); background: color-mix(in srgb, var(--run) 12%, var(--panel-2)); }
    .badge.fail { color: var(--fail-ink); border-color: color-mix(in srgb, var(--fail) 50%, transparent); background: color-mix(in srgb, var(--fail) 14%, var(--panel-2)); }
    .badge.warn { color: var(--warn-ink); border-color: color-mix(in srgb, var(--warn) 50%, transparent); background: color-mix(in srgb, var(--warn) 14%, var(--panel-2)); }
    .badge.money { color: var(--money-ink); border-color: color-mix(in srgb, var(--money) 55%, transparent); background: color-mix(in srgb, var(--money) 14%, var(--panel-2)); }

    /* -- Progress bars ---------------------------------------------------- */

    .bar {
        height: 7px;
        background: var(--idle);
        border-radius: 4px;
        overflow: hidden;
        display: flex;
        box-shadow: var(--inset-bar);
    }

    .bar span { display: block; height: 100%; }
    .bar .done { background: var(--ok); }
    .bar .bad { background: var(--fail); }
    .bar .busy { background: var(--run); }

    /* -- `.warnfill` — a defect fix, not a styling decision ----------------
     *
     * `.warnfill` was defined ONLY as `.bar .warnfill`, a fill inside a
     * progress bar. Two surfaces have written it standalone since they were
     * written, and both are load-bearing:
     *
     *   `<div class="panel" @@class(['warnfill' => $worst !== OK])>`
     *       — the worker-health panel, marked whenever a queue is stale,
     *         absent or STRANDED;
     *   `<tr @@class(['warnfill' => $n->warnings !== []])>`
     *       — a story row with failed jobs or a silent heartbeat.
     *
     * (Both written `@@class` above: this is a blade file, and blade compiles
     *  its directives inside a `<style>` block exactly as it would anywhere
     *  else. An unescaped `@class` in a CSS comment is a directive — it took
     *  every page in the console down with `Undefined constant "OK"`.)
     *
     * Neither has ever rendered anything. The panel's explicit alerts still
     * fired underneath, so the stranded case was not invisible — but the
     * ambient "something on this page is wrong" was, and on the stories index
     * the row tint was the ONLY marking a warning row got beyond a badge in
     * one cell.
     *
     * This is the exact shape of the entries in the false-success table: a
     * check that runs, produces the right answer, and cannot be seen. The
     * markup was right the whole time. Nothing about when it fires changes
     * here — only whether looking at the page tells you it did.
     *
     * The bar fill keeps its meaning by specificity, and drops the surface
     * treatment it would otherwise inherit.
     */
    .warnfill {
        background: var(--warnfill-bg);
        box-shadow: var(--lift), inset 4px 0 0 var(--warn);
    }

    .panel.warnfill {
        border-color: color-mix(in srgb, var(--warn) 50%, transparent);
        padding-left: 21px;
    }

    /*
     * A row cannot carry a box-shadow reliably under `border-collapse`, so the
     * tint goes on the cells and the accent edge on the first one. It has to
     * beat `tbody tr:hover td`, which is why it is written with the row in the
     * selector rather than as a bare `.warnfill td`.
     */
    tr.warnfill { background: none; box-shadow: none; }
    tbody tr.warnfill td { background: color-mix(in srgb, var(--warn) 12%, transparent); }
    tbody tr.warnfill td:first-child { box-shadow: inset 4px 0 0 var(--warn); }
    tbody tr.warnfill:hover td { background: color-mix(in srgb, var(--warn) 18%, transparent); }

    .bar .warnfill { background: var(--warn); box-shadow: none; }

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
        top: var(--chrome-h);
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
        background: var(--alert-err-bg);
        box-shadow: var(--lift), inset 4px 0 0 var(--fail);
        padding-left: 21px;
    }

    .alert.warn {
        border-color: color-mix(in srgb, var(--warn) 55%, transparent);
        background: var(--alert-warn-bg);
        box-shadow: var(--lift), inset 4px 0 0 var(--warn);
        padding-left: 21px;
    }

    .alert.ok {
        border-color: color-mix(in srgb, var(--ok) 45%, transparent);
        background: var(--alert-ok-bg);
        box-shadow: var(--lift), inset 4px 0 0 var(--ok);
        padding-left: 21px;
    }

    .alert.money {
        border-color: color-mix(in srgb, var(--money) 60%, transparent);
        background: var(--alert-money-bg);
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
        box-shadow: var(--inset);
    }

    textarea { resize: vertical; min-height: 74px; line-height: 1.6; }

    input:focus, textarea:focus, select:focus {
        outline: none;
        border-color: color-mix(in srgb, var(--run) 70%, transparent);
        box-shadow: var(--inset), 0 0 0 3px color-mix(in srgb, var(--run) 22%, transparent);
    }

    input:disabled, textarea:disabled, select:disabled {
        opacity: .55;
        cursor: not-allowed;
        background: var(--panel);
    }

    .field { margin-bottom: 18px; }
    .field:last-child { margin-bottom: 0; }
    .field .muted.small { margin-top: 7px; }

    /*
     * ── Buttons, and why these selectors are not tagged ──────────────────
     *
     * This block used to read `button.primary`, `button.danger`, `button.gate`.
     * Two anchors on the stories index have written `class="primary"` since
     * they were added — the "New story" call to action, on the empty state and
     * above the table — and `button.primary` cannot match an `<a>`. Both
     * rendered as ordinary blue text links: the front door of the app, styled
     * as a footnote, on a page whose whole job is to say what to do next.
     *
     * Same family as `.panel.money` and `.alert.err` — a class the markup asks
     * for that the stylesheet does not answer, failing silently and looking
     * deliberate. Found by `tools/class-audit.php`, which reports it as TAG
     * rather than UNDEFINED because the rule exists and simply cannot reach.
     *
     * So the look is element-agnostic now, and the base chrome comes with it.
     * A control that says it is primary is a primary control whatever tag it
     * is made of, which is the property the markup has been assuming all along.
     */
    button,
    .btn,
    .primary,
    .danger,
    .gate {
        display: inline-block;
        background: linear-gradient(var(--btn-from), var(--btn-to));
        border: 1px solid var(--line-2);
        color: var(--text);
        border-radius: var(--radius-sm);
        padding: 8px 15px;
        font: inherit;
        font-size: 13px;
        font-weight: 550;
        line-height: 1.45;
        text-align: center;
        cursor: pointer;
        box-shadow: var(--lift);
    }

    button:hover:not(:disabled),
    .btn:hover,
    .primary:hover,
    .danger:hover,
    .gate:hover { border-color: var(--meta); filter: brightness(1.06); text-decoration: none; }

    button:active:not(:disabled) { transform: translateY(1px); box-shadow: none; }
    button:disabled { opacity: .42; cursor: not-allowed; box-shadow: none; }

    .primary {
        background: linear-gradient(var(--primary-from), var(--primary-to));
        border-color: color-mix(in srgb, var(--ok) 55%, transparent);
        color: var(--primary-ink);
    }

    .danger {
        background: linear-gradient(var(--danger-from), var(--danger-to));
        border-color: color-mix(in srgb, var(--fail) 50%, transparent);
        color: var(--danger-ink);
    }

    /* The gate crossings and the spend buttons. The heaviest thing on a page,
       because they are the decisions that cannot be taken back. */
    .gate {
        background: linear-gradient(var(--gate-from), var(--gate-to));
        border-color: color-mix(in srgb, var(--money) 65%, transparent);
        color: var(--gate-ink);
        font-weight: 660;
    }

    /* An anchor keeps the button's ink rather than the link colour. Without
       this `a { color: var(--run-ink) }` wins on specificity and the primary
       control comes out blue-on-green. */
    a.primary { color: var(--primary-ink); }
    a.danger { color: var(--danger-ink); }
    a.gate { color: var(--gate-ink); }

    button.tiny, .tiny { padding: 4px 9px; font-size: 12px; box-shadow: none; }

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
        color: var(--on-accent);
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

    /* -- Spacing utilities -------------------------------------------------
     *
     * The console carried 228 hand-written `style=""` attributes, and 150 of
     * them were a top or bottom margin. Seven distinct values — 2, 4, 6, 8, 10,
     * 12, 14, 16, 18 — with no system saying which belonged where, so "the gap
     * under an alert" was 6px in one blade, 8px in the next and 10px in a
     * third, and no amount of editing this stylesheet could change any of them.
     *
     * These are named for their step on the ramp, so a spacing decision is made
     * once here rather than retyped per element. Every value is exactly what
     * the inline declaration it replaced said, and anything off the ramp was
     * left inline rather than rounded to the nearest step — a value somebody
     * chose for a reason is not this pass's to change.
     *
     * ---------------------------------------------------------------------
     * IT IS NOT QUITE PIXEL-NEUTRAL, AND THE EXCEPTION IS THE INTERESTING BIT
     * ---------------------------------------------------------------------
     *
     * An inline style beats every selector. A class does not. So in the two
     * places where a more specific rule already set the same property, the
     * hoisted class now LOSES where the inline declaration used to win:
     *
     *   `.field .muted.small { margin-top: 7px }` beats `.mt-2` (6px). One
     *   pixel, on hint text under a form field.
     *
     *   `.alert + .alert { margin-top: -6px }` beats `.mt-4` (10px) — and this
     *   one is a fix rather than a side effect. That rule exists so a run of
     *   consecutive alerts reads as one block of warnings instead of as a
     *   column of identical rectangles; the inline margins had been quietly
     *   defeating it since they were written, which is why the worker-health
     *   panel's stacked queue alarms have always sat 26px apart instead of the
     *   10px the stylesheet asks for.
     *
     * No alert gets quieter either way: the tint, the 4px accent edge, the
     * shadow and the size are untouched, and only the gap between them moves.
     * Clustering was always the documented intent — see the alerts block.
     */
    .m-none { margin: 0; }
    .mt-none { margin-top: 0; }
    .mb-none { margin-bottom: 0; }

    .mt-hair { margin-top: var(--s-hair); }
    .mt-1 { margin-top: var(--s-1); }
    .mt-2 { margin-top: var(--s-2); }
    .mt-3 { margin-top: var(--s-3); }
    .mt-4 { margin-top: var(--s-4); }
    .mt-5 { margin-top: var(--s-5); }
    .mt-6 { margin-top: var(--s-6); }
    .mt-7 { margin-top: var(--s-7); }
    .mt-8 { margin-top: var(--s-8); }

    .mb-hair { margin-bottom: var(--s-hair); }
    .mb-1 { margin-bottom: var(--s-1); }
    .mb-2 { margin-bottom: var(--s-2); }
    .mb-3 { margin-bottom: var(--s-3); }
    .mb-4 { margin-bottom: var(--s-4); }
    .mb-5 { margin-bottom: var(--s-5); }
    .mb-6 { margin-bottom: var(--s-6); }
    .mb-7 { margin-bottom: var(--s-7); }
    .mb-8 { margin-bottom: var(--s-8); }

    /* A guard's own line breaks. Several refusals arrive as one string with
       newlines in it — `PreflightAssetDispatch` and the outline spine both do
       this — and collapsing them would run four separate problems together
       into one paragraph. */
    .pre-line { white-space: pre-line; }

    /* A sub-list hanging under the line it belongs to. */
    .indent { margin: var(--s-2) 0 0 var(--s-8); }

    .tr { text-align: right; }
    .tc { text-align: center; }

    /* -- Dashboard ---------------------------------------------------------
     *
     * A summary page, so its job is to be skimmable at a glance and to make
     * the two things that need acting on impossible to scroll past. The card
     * grid is deliberately plain: the loud surfaces on this page are the
     * alerts and the money panel, and a page where every card competes is a
     * page where the alarm does not stand out.
     */

    .cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: var(--s-6);
        margin-bottom: var(--s-7);
    }

    .cards .card { margin-bottom: 0; }

    .two-up {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
        gap: var(--s-6);
        align-items: start;
    }

    /*
     * The one big number on a card. Tabular figures so a column of them lines
     * up and a changing value does not make the layout twitch on a page that
     * polls itself every fifteen seconds.
     */
    .figure {
        font-size: 27px;
        font-weight: 640;
        letter-spacing: -.02em;
        line-height: 1.15;
        margin: var(--s-1) 0 var(--s-2);
        font-variant-numeric: tabular-nums;
    }

    .panel.money .figure { color: var(--money-ink); }

    /* A story waiting at a gate: what it is, why, and the one way in. */
    .needs {
        display: flex;
        gap: var(--s-6);
        align-items: center;
        padding: var(--s-4) 0;
        border-top: 1px solid var(--line);
    }

    .needs:first-of-type { border-top: none; padding-top: 0; }
    .needs:last-child { padding-bottom: 0; }
    .needs .meta-line { color: var(--meta); margin-top: var(--s-1); }

    /* A heading that opens a page has nothing above it to be separated from.
       `main > h2:first-child` already says this; the dashboard's headings sit
       inside a livewire root, so they need to be able to say it themselves. */
    h2.flush-top { margin-top: 0; padding-top: 0; border-top: none; }

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
    .gates .passed .state { color: var(--ok-ink); }

    .gates .current {
        border-color: color-mix(in srgb, var(--money) 65%, transparent);
        background: var(--gate-current-bg);
        box-shadow: var(--lift-lg), inset 0 -2px 0 var(--money);
    }

    .gates .current .state { color: var(--money-ink); }
    .gates .locked { opacity: .5; box-shadow: none; }

    /*
     * WHICH gate you are looking at, as opposed to which one is waiting.
     *
     * The stepper writes `viewing` on the current page's tile and nothing
     * defined it, so the blade worked around its own missing rule with an
     * inline `style="border-color: var(--run)"`. Third instance of the same
     * defect in this file's history, and the workaround is why it went
     * unnoticed: the page looked right, so nobody asked whether the class did
     * anything.
     *
     * Deliberately quieter than `.current`. `current` means a decision is
     * outstanding and `viewing` means you happen to be here — reversing that
     * would let the page you are ON out-shout the thing it wants you to do.
     */
    .gates .viewing {
        border-color: color-mix(in srgb, var(--run) 70%, transparent);
    }

    .gates .current.viewing {
        border-color: color-mix(in srgb, var(--money) 80%, transparent);
    }

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
        box-shadow: var(--inset-well);
    }

    /* A failure's own words, in the failure's own colour. */
    pre.err { color: var(--err-ink); border-color: color-mix(in srgb, var(--fail) 35%, transparent); }

    video { width: 100%; border-radius: var(--radius); background: var(--video-mat); box-shadow: var(--lift-lg); }

    .error { color: var(--fail-ink); font-size: 12.5px; margin-top: 5px; font-weight: 550; }

    /* -- Motion ------------------------------------------------------------ */

    @media (prefers-reduced-motion: reduce) {
        * { animation: none !important; transition: none !important; }
    }
</style>
