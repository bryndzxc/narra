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
        --d-bg: #161826;
        --d-bg-2: #12141f;
        --d-panel: #1c1e2b;
        --d-panel-2: #232532;
        --d-line: rgba(233, 233, 237, .11);
        --d-line-2: rgba(233, 233, 237, .055);

        /*
         * Three tiers, not two. `--text` for what is being read, `--muted` for
         * the explanations beside it, `--meta` for labels and column headings.
         * The old file had `--muted` doing the last two jobs at once and had to
         * pick a value that suited neither.
         */
        --d-text: #e5e8f0;
        --d-muted: #a2a6b8;
        --d-meta: #878ca0;

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
        --d-fail-ink: #ff8079;
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
        --d-alert-run-bg: color-mix(in srgb, var(--d-run) 12%, var(--d-panel));
        --d-money-panel-from: color-mix(in srgb, var(--d-money) 7%, var(--d-panel));
        --d-money-panel-to: color-mix(in srgb, var(--d-money) 3%, var(--d-panel));
        --d-warnfill-bg: color-mix(in srgb, var(--d-warn) 11%, var(--d-panel));
        --d-gate-current-bg: color-mix(in srgb, var(--d-money) 10%, var(--d-panel));


        /* ── Nocturne's additions ────────────────────────────────────────
         *
         * `--accent` is navigation and links: the thing you PRESS, as opposed
         * to the status ramps above, which are the thing you are TOLD. Keeping
         * those two jobs on separate hues is most of why the console can be
         * calm and still shout — before this, a link, an in-progress badge and
         * a running progress bar were all the same blue.
         *
         * The alarm band is its own set rather than a tint of `--fail`. It is
         * the one surface in the console that is a saturated FLOOD rather than
         * a wash, so it needs a ground dark enough to carry white text and an
         * ink that is not the same red as the text inside a `.alert.err`.
         */
        --d-accent: #9184d9;
        --d-accent-ink: #9184d9;
        --d-alarm-from: #8e1a16;
        --d-alarm-to: #4a0d0b;
        --d-alarm-edge: #ff6b60;
        --d-alarm-ink: #ffe6e4;
        --d-alarm-warn-from: #7a5410;
        --d-alarm-warn-to: #3f2b07;
        --d-alarm-warn-edge: #e0a33a;
        --d-alarm-warn-ink: #ffeed2;
        /* The inset chip a pasteable command sits in, on the band. */
        --d-well: rgba(0, 0, 0, .34);
        --d-well-ink: rgba(255, 255, 255, .2);
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
        --l-bg: #eceef4;
        --l-bg-2: #e4e7ef;
        --l-panel: #ffffff;
        --l-panel-2: #f2f4f7;
        --l-line: rgba(21, 24, 31, .13);
        --l-line-2: rgba(21, 24, 31, .065);

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
        --l-alert-run-bg: color-mix(in srgb, var(--l-run) 12%, var(--l-panel));
        --l-money-panel-from: color-mix(in srgb, var(--l-money) 22%, var(--l-panel));
        --l-money-panel-to: color-mix(in srgb, var(--l-money) 15%, var(--l-panel));
        --l-warnfill-bg: color-mix(in srgb, var(--l-warn) 11%, var(--l-panel));
        --l-gate-current-bg: color-mix(in srgb, var(--l-money) 10%, var(--l-panel));


        /* The accent darkens for a light ground; the alarm does not lighten.
           A red band with white text is the same object in both themes — it is
           the loudest thing the console can draw and it does not get a pastel
           variant. */
        --l-accent: #5d5294;
        --l-accent-ink: #4a417a;
        --l-alarm-from: #c8261d;
        --l-alarm-to: #8f120c;
        --l-alarm-edge: #ff8b84;
        --l-alarm-ink: #fff1f0;
        --l-alarm-warn-from: #8a5d02;
        --l-alarm-warn-to: #5c3d01;
        --l-alarm-warn-edge: #edaa2b;
        --l-alarm-warn-ink: #fff6e6;
        --l-well: rgba(0, 0, 0, .22);
        --l-well-ink: rgba(255, 255, 255, .26);
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
        --alert-run-bg: var(--l-alert-run-bg);
        --money-panel-from: var(--l-money-panel-from);
        --money-panel-to: var(--l-money-panel-to);
        --warnfill-bg: var(--l-warnfill-bg);
        --gate-current-bg: var(--l-gate-current-bg);
        --accent: var(--l-accent);
        --accent-ink: var(--l-accent-ink);
        --alarm-from: var(--l-alarm-from);
        --alarm-to: var(--l-alarm-to);
        --alarm-edge: var(--l-alarm-edge);
        --alarm-ink: var(--l-alarm-ink);
        --alarm-warn-from: var(--l-alarm-warn-from);
        --alarm-warn-to: var(--l-alarm-warn-to);
        --alarm-warn-edge: var(--l-alarm-warn-edge);
        --alarm-warn-ink: var(--l-alarm-warn-ink);
        --well: var(--l-well);
        --well-ink: var(--l-well-ink);
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
        --chrome-h: 48px;
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
            --alert-run-bg: var(--d-alert-run-bg);
            --money-panel-from: var(--d-money-panel-from);
            --money-panel-to: var(--d-money-panel-to);
            --warnfill-bg: var(--d-warnfill-bg);
            --gate-current-bg: var(--d-gate-current-bg);
            --accent: var(--d-accent);
            --accent-ink: var(--d-accent-ink);
            --alarm-from: var(--d-alarm-from);
            --alarm-to: var(--d-alarm-to);
            --alarm-edge: var(--d-alarm-edge);
            --alarm-ink: var(--d-alarm-ink);
            --alarm-warn-from: var(--d-alarm-warn-from);
            --alarm-warn-to: var(--d-alarm-warn-to);
            --alarm-warn-edge: var(--d-alarm-warn-edge);
            --alarm-warn-ink: var(--d-alarm-warn-ink);
            --well: var(--d-well);
            --well-ink: var(--d-well-ink);
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
        --alert-run-bg: var(--d-alert-run-bg);
        --money-panel-from: var(--d-money-panel-from);
        --money-panel-to: var(--d-money-panel-to);
        --warnfill-bg: var(--d-warnfill-bg);
        --gate-current-bg: var(--d-gate-current-bg);
        --accent: var(--d-accent);
        --accent-ink: var(--d-accent-ink);
        --alarm-from: var(--d-alarm-from);
        --alarm-to: var(--d-alarm-to);
        --alarm-edge: var(--d-alarm-edge);
        --alarm-ink: var(--d-alarm-ink);
        --alarm-warn-from: var(--d-alarm-warn-from);
        --alarm-warn-to: var(--d-alarm-warn-to);
        --alarm-warn-edge: var(--d-alarm-warn-edge);
        --alarm-warn-ink: var(--d-alarm-warn-ink);
        --well: var(--d-well);
        --well-ink: var(--d-well-ink);
        --lift: var(--d-lift);
        --lift-lg: var(--d-lift-lg);
        --inset: var(--d-inset);
        --inset-bar: var(--d-inset-bar);
        --inset-well: var(--d-inset-well);
    }

    * { box-sizing: border-box; }

    ::selection { background: color-mix(in srgb, var(--accent) 35%, transparent); }

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

    /* Links take the accent, not the status blue. The thing you PRESS and the
       thing you are TOLD now have different hues — before this a link, an
       in-progress badge and a running progress bar were all the same colour. */
    a { color: var(--accent-ink); text-decoration: none; }
    a:hover { text-decoration: underline; text-underline-offset: 2px; }

    /* Keyboard use is real in a form this dense, and the old file had no ring. */
    :focus-visible {
        outline: 2px solid var(--accent);
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
        width: 196px;
        flex: none;
        position: sticky;
        top: 0;
        height: 100vh;
        display: flex;
        flex-direction: column;
        gap: var(--s-6);
        padding: var(--s-6) var(--s-5) var(--s-5);
        border-right: 1px solid var(--line);
        background: var(--bg-2);
    }

    /* The wordmark is the one place the accent appears at rest, which is what
       makes the accent read as "this app" rather than as a status colour. */
    .side .brand {
        font-weight: 600;
        letter-spacing: .22em;
        font-size: 13px;
        color: var(--accent);
        padding: var(--s-2) var(--s-4) var(--s-8);
    }

    .side .brand:hover { text-decoration: none; color: var(--accent); }

    .side nav { display: flex; flex-direction: column; gap: 2px; }

    .side nav a {
        display: flex;
        align-items: center;
        gap: var(--s-4);
        color: var(--muted);
        font-size: 13.5px;
        font-weight: 500;
        padding: var(--s-3) var(--s-4);
        border-radius: 6px;
    }

    .side nav a:hover {
        color: var(--text);
        background: color-mix(in srgb, var(--accent) 10%, transparent);
        text-decoration: none;
    }

    /* The current destination, marked on the edge as well as in the fill —
       the fill alone is a few percent of luminance and disappears entirely on
       a bright screen in a lit room. */
    .side nav a.on {
        color: var(--accent-ink);
        font-weight: 600;
        background: color-mix(in srgb, var(--accent) 16%, transparent);
        box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--accent) 34%, transparent);
    }

    .side nav .ico { width: 15px; text-align: center; opacity: .75; font-size: 12px; }

    /*
     * The count on a nav item. Two of them exist and they are not decoration:
     * "how many gates are waiting" and "how much work is queued" are the two
     * questions this console is opened to answer, and carrying them in the rail
     * means the answer is on screen from every page rather than only from the
     * dashboard.
     *
     * `.count.loud` is the stranded/failed case and is the one that gets a
     * filled red pill — a number that means "the pipeline has stopped" must not
     * look like a number that means "four things to read".
     */
    .side nav .count {
        margin-left: auto;
        flex: none;
        padding: 1px 7px;
        border-radius: 9px;
        font-family: ui-monospace, "Cascadia Mono", Consolas, monospace;
        font-size: 10px;
        font-weight: 700;
        line-height: 1.6;
        background: color-mix(in srgb, var(--money) 20%, transparent);
        color: var(--money-ink);
    }

    .side nav .count.loud { background: var(--fail); color: #fff; }

    .sidefoot { margin-top: auto; display: flex; flex-direction: column; gap: var(--s-5); padding-top: var(--s-8); }

    .sidefoot p {
        color: var(--meta);
        margin: 0;
        max-width: none;
        font-family: ui-monospace, "Cascadia Mono", Consolas, monospace;
        font-size: 11px;
        line-height: 1.55;
    }

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
        padding: 0 var(--s-8);
        height: var(--chrome-h);
        background: var(--bg-2);
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
        font-size: 15px;
        font-weight: 500;
        letter-spacing: -.012em;
        color: var(--text);
    }

    /*
     * The standing figures, in the chrome rather than in a panel: what the
     * console is holding right now, and what it has cost this month. Both are
     * true on every page, so both belong to the frame rather than to one of
     * them.
     */
    header.top .stat {
        font-family: ui-monospace, "Cascadia Mono", Consolas, monospace;
        font-size: 12px;
        color: var(--meta);
    }

    header.top .stat.spend { color: var(--money-ink); }
    header.top .stat.spend .when { color: var(--meta); }

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
    main { padding: var(--s-6) var(--s-8); width: 100%; flex: 1; min-width: 0; }

    footer {
        color: var(--meta);
        font-size: 12.5px;
        padding: var(--s-5) var(--s-8) var(--s-7);
        text-align: left;
        font-family: ui-monospace, "Cascadia Mono", Consolas, monospace;
        font-size: 11.5px;
        border-top: 1px solid var(--line-2);
        margin-top: var(--s-9);
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

    /*
     * The same surface on a card. Written as its own rule rather than as
     * `.panel.money, .card.money` because the two carry their edge differently
     * — a panel has padding to inset for the accent bar and a card does not,
     * its rows go to the edge.
     *
     * Caught by `tools/class-audit.php` as COMBO on the first run of this
     * design: the markup said `class="card money"` and every `.money` rule in
     * the file needed a co-class it did not have. The money screen would have
     * rendered as an ordinary card, which is `.panel.money`'s original defect
     * arriving by a new route.
     */
    .card.money {
        background: linear-gradient(var(--money-panel-from), var(--money-panel-to));
        box-shadow:
            var(--lift-lg),
            0 0 0 1px color-mix(in srgb, var(--money) 45%, transparent),
            inset 3px 0 0 var(--money);
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
     *  else. An unescaped `@@class` in a CSS comment is a directive — it took
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
    .alert.ok + .alert.ok,
    .alert.run + .alert.run { margin-top: -8px; }
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

    /*
     * A plain advisory: no severity, because nothing is wrong.
     *
     * Used by the fixture notice, which is the one panel in the console that
     * exists to STOP somebody acting rather than to prompt them. It gets the
     * alert's shape — the measure cap, the shadow, the accent edge — in the
     * neutral colour, so it reads as "read this" without borrowing the
     * vocabulary of a warning. Bare `.alert` had no accent edge at all before
     * this and rendered a plain box.
     */
    .alert:not(.ok):not(.warn):not(.err):not(.fail):not(.money):not(.run) {
        border-color: var(--line-2);
        background: var(--panel-2);
        box-shadow: var(--lift), inset 4px 0 0 var(--meta);
        padding-left: 21px;
    }

    .alert.money {
        border-color: color-mix(in srgb, var(--money) 60%, transparent);
        background: var(--alert-money-bg);
        box-shadow: var(--lift), inset 4px 0 0 var(--money);
        padding-left: 21px;
    }

    /*
     * IN FLIGHT. Something is running right now and the page is waiting for it.
     *
     * `--run` is the status ramp for in-progress, and until now it existed only
     * as `.badge.run` and as a progress-bar fill — so the first surface that
     * needed to say "this is happening" in alert form asked for `.alert.run`
     * and got the NEUTRAL treatment, silently. class-audit reported it as a
     * COMBO the moment it was written, which is the `.panel.money` defect
     * caught during the work instead of a phase later.
     *
     * It earns its place rather than being added for tidiness: the character
     * sheet button holds the request for about 144 seconds at the measured rate
     * and had nothing but a greyed-out button to say so. A greyed button says
     * "not now"; this says "this is running and it is billing".
     */
    .alert.run {
        border-color: color-mix(in srgb, var(--run) 55%, transparent);
        background: var(--alert-run-bg);
        box-shadow: var(--lift), inset 4px 0 0 var(--run);
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

    .money .figure { color: var(--money-ink); }

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

    /* -- The alarm band ----------------------------------------------------
     *
     * The loudest thing this console can draw, and the only surface in it that
     * is a saturated FLOOD rather than a wash on a panel.
     *
     * It replaces three stacked alert boxes on the dashboard. That is a gain in
     * prominence and not a reduction, which is worth being precise about
     * because this file's one rule forbids the reverse:
     *
     *   - the three boxes lived INSIDE `main`, inset by its padding, competing
     *     with the panels beside them for the same width and the same tint
     *     vocabulary. The band is full-bleed and sits directly under the
     *     chrome, so it is the first thing on the page and touches both edges;
     *   - the boxes were a 13% wash of `--fail` on the panel colour — about
     *     1.2x from an ordinary panel. The band is a saturated red ground with
     *     near-white text, which is an order of magnitude further away;
     *   - the shared 60 words of explanation appeared once per queue. Here they
     *     appear once, and the per-queue facts sit beside them in columns.
     *
     * Nothing is dropped: every queue is still named, every pending count is
     * still printed, every command is still pasteable, and the health table
     * comes with it.
     *
     * `.band.warn` is the ABSENT case. Absent is a warning rather than an
     * alarm — the job queues and waits, nothing is lost, nothing happens — so
     * it keeps the band's placement and prominence and drops to amber. The two
     * are never merged: stranded means the pipeline has stopped now, absent
     * means nobody is listening to an empty queue, and they want different
     * reactions.
     */
    .band {
        background: linear-gradient(100deg, var(--alarm-from) 0%, var(--alarm-to) 78%);
        color: var(--alarm-ink);
        border-bottom: 1px solid var(--alarm-edge);
    }

    .band.warn {
        background: linear-gradient(100deg, var(--alarm-warn-from) 0%, var(--alarm-warn-to) 78%);
        color: var(--alarm-warn-ink);
        border-bottom-color: var(--alarm-warn-edge);
    }

    .band .inner {
        display: grid;
        /* Two columns, not three. The middle one held a full repeat of the
           message per queue; collapsing that away is what took this band from
           ~200px to a few lines. */
        grid-template-columns: minmax(0, 1.5fr) minmax(0, 1fr);
        gap: var(--s-10);
        padding: var(--s-6) var(--s-8) var(--s-7);
        align-items: start;
    }

    .band h2 {
        font-size: 17px;
        font-weight: 600;
        letter-spacing: -.015em;
        color: inherit;
        margin: 0 0 var(--s-2);
        padding: 0;
        border: none;
        display: flex;
        align-items: center;
        gap: var(--s-4);
    }

    .band h3 { font-size: 15px; font-weight: 600; margin: 0 0 var(--s-2); color: inherit; }
    .band p { margin: 0; font-size: 13px; opacity: .93; max-width: 62ch; }
    .band .small { font-size: 11.5px; opacity: .82; }
    .band a { color: inherit; text-decoration: underline; }

    /* The pasteable fix, in a well so it reads as a thing to copy rather than
       as more prose. */
    .band .cmd {
        display: block;
        margin-top: var(--s-3);
        padding: 7px 10px;
        border-radius: 6px;
        background: var(--well);
        box-shadow: inset 0 0 0 1px var(--well-ink);
        font-family: ui-monospace, "Cascadia Mono", Consolas, monospace;
        font-size: 13px;
        line-height: 1.3;
        color: #fff;
        overflow-x: auto;
    }

    /* The health table, on the band's own ground rather than a panel's. */
    .band table { background: none; font-size: 12.5px; }
    .band th { color: inherit; opacity: .7; position: static; background: none; }
    .band th, .band td { border-color: var(--well-ink); }
    .band td { font-family: ui-monospace, "Cascadia Mono", Consolas, monospace; }
    .band tbody tr:hover td { background: rgba(255, 255, 255, .05); }

    .band .state {
        display: inline-block;
        padding: 1px 7px;
        border-radius: 4px;
        background: var(--well);
        box-shadow: inset 0 0 0 1px var(--well-ink);
        font-family: inherit;
        font-size: 9.5px;
        font-weight: 700;
        letter-spacing: .08em;
        text-transform: uppercase;
    }

    /*
     * Full-bleed out of `main`'s padding, so the band touches both edges of the
     * content column the way it does in the design.
     *
     * The alternative — putting the band in the layout, between the header and
     * `main` — would have put it outside the livewire root, which means it
     * would not appear until the next full page load. An alarm that waits for a
     * navigation to show up is not an alarm, so it lives inside the polling
     * component and reaches the edges this way instead.
     */
    .bleed {
        margin: calc(var(--s-6) * -1) calc(var(--s-8) * -1) var(--s-6);
    }

    /* -- Cards -------------------------------------------------------------
     *
     * The dashboard's surface. A `.panel` is a box with padding; a `.card` is a
     * box with a titled head and full-bleed rows under it, which is what a
     * summary column actually needs.
     */
    .card {
        background: var(--panel);
        border-radius: 9px;
        box-shadow: var(--lift), 0 0 0 1px var(--line-2);
        min-width: 0;
    }

    .card > .head {
        display: flex;
        align-items: center;
        gap: var(--s-3);
        padding: var(--s-4) var(--s-5);
        border-bottom: 1px solid var(--line);
        flex-wrap: wrap;
    }

    .card > .head h2 {
        margin: 0;
        padding: 0;
        border: none;
        font-size: 10.5px;
        font-weight: 600;
        letter-spacing: .12em;
        text-transform: uppercase;
        color: var(--meta);
    }

    .card > .head .note {
        margin-left: auto;
        font-family: ui-monospace, "Cascadia Mono", Consolas, monospace;
        font-size: 11.5px;
        color: var(--meta);
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .card .row-item {
        padding: var(--s-5);
        border-bottom: 1px solid var(--line-2);
    }

    .card .row-item:last-child { border-bottom: none; }

    /* The row a decision is standing on. Gold edge and gold wash, the same
       vocabulary `.panel.money` uses, because it is the same meaning. */
    .card .row-item.money {
        border-left: 3px solid var(--money);
        background: var(--gate-current-bg);
    }

    .card .row-item.bad {
        border-left: 4px solid var(--fail);
        background: var(--alert-err-bg);
    }

    .card table { font-size: 12.5px; }
    .card > table th { position: static; }

    /* -- Dashboard grid ---------------------------------------------------- */

    /* -- The dashboard grid, which is two layouts ---------------------------
     *
     * BUSY is the one this page was designed for: three columns, the decisions
     * on the left, whatever is moving in the wide middle, money on the right.
     *
     * QUIET is what is true most of the time, and it is not the busy layout
     * with empty boxes in it. Empty containers held at busy size do not read as
     * calm, they read as scattered — and worse, they compete for attention with
     * the one section that can be acted on. So the columns collapse: the
     * actionable section takes the room the other two were using, and what is
     * genuinely not happening shrinks to a line.
     *
     * Placement is explicit rather than left to auto-flow, because the column
     * count changes between the two and auto-placement would wrap the money
     * rail underneath the decisions instead of beside them.
     */
    .dash {
        display: grid;
        grid-template-columns: minmax(0, 1.02fr) minmax(0, 1.42fr) minmax(0, .66fr);
        gap: var(--s-5);
        align-items: start;
    }

    .dash > .needs-col { grid-column: 1; grid-row: 1; min-width: 0; }
    .dash > .flow { grid-column: 2; grid-row: 1; min-width: 0; display: flex; flex-direction: column; gap: var(--s-5); }
    .dash > .rail { grid-column: 3; grid-row: 1; }

    .rail { display: flex; flex-direction: column; gap: var(--s-5); min-width: 0; }

    /*
     * THE TEMPLATE FOLLOWS THE COLUMNS THAT ARE ACTUALLY THERE.
     *
     * `.dash` is a fixed three-track template with conditionally-populated
     * children, which is the shape this console has now had to fix on the
     * dashboard, on Gate 1, on Gate 2 and on Gate 4. It never produced a void
     * here — measured, not assumed — but only because every column happened to
     * carry an unconditional wrapper. That is an accident, and one conditional
     * around one card would end it.
     *
     * The columns are `x-gate-group`s now, so a column with nothing in it
     * renders no element. Placement stays explicit — auto-flow would wrap the
     * money rail underneath the decisions instead of beside them — so the
     * template is restated for the case where the middle column is gone,
     * matching `.dash.quiet`, which is the same two-column arrangement arrived
     * at from the other direction.
     */
    .dash:not(:has(> .flow)) { grid-template-columns: minmax(0, 2.5fr) minmax(0, 1fr); }
    .dash:not(:has(> .flow)) > .rail { grid-column: 2; grid-row: 1; }

    /*
     * Nothing running, nothing broken. Two columns: the decisions get most of
     * the width and all of the first screenful, the money rail keeps its place
     * on the right because it is always relevant, and the strip describing what
     * is NOT happening goes underneath where it costs one line.
     */
    .dash.quiet { grid-template-columns: minmax(0, 2.5fr) minmax(0, 1fr); }
    .dash.quiet > .needs-col { grid-column: 1; grid-row: 1; }
    .dash.quiet > .rail { grid-column: 2; grid-row: 1 / span 2; }
    .dash.quiet > .flow { grid-column: 1; grid-row: 2; }

    /*
     * The one line that replaces two cards.
     *
     * Deliberately not a `.card`: a card is a container with a heading, and a
     * heading over nothing is the thing being fixed. This is a rule with a
     * sentence on it.
     */
    .strip {
        display: flex;
        align-items: center;
        gap: var(--s-4);
        flex-wrap: wrap;
        padding: var(--s-4) var(--s-5);
        border-radius: 9px;
        border: 1px dashed var(--line);
        color: var(--meta);
        font-size: 12.5px;
    }

    .strip .badge { flex: none; }
    .strip > details.why { margin-top: 0; margin-left: auto; }
    .strip > details.why[open] { margin-left: 0; width: 100%; }

    @media (max-width: 1500px) {
        .dash,
        .dash.quiet { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); }
        .dash > .needs-col, .dash.quiet > .needs-col { grid-column: 1; grid-row: 1; }
        .dash > .flow, .dash.quiet > .flow { grid-column: 2; grid-row: 1; }
        .dash > .rail, .dash.quiet > .rail {
            grid-column: 1 / -1;
            grid-row: 2;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        }
        /* Quiet at this width: the strip is a line, so it can sit under the
           decisions rather than beside them. */
        .dash.quiet > .flow { grid-column: 1 / -1; grid-row: 2; }
        .dash.quiet > .rail { grid-row: 3; }
    }

    @media (max-width: 1000px) {
        .dash,
        .dash.quiet { grid-template-columns: minmax(0, 1fr); }
        .dash > .needs-col, .dash > .flow, .dash > .rail,
        .dash.quiet > .needs-col, .dash.quiet > .flow, .dash.quiet > .rail {
            grid-column: 1;
            grid-row: auto;
        }
        .band .inner { grid-template-columns: minmax(0, 1fr); gap: var(--s-6); }
    }

    /*
     * The spend control. Amber, with what it commits inside the label — the
     * figure is part of the decision, not a footnote beside it.
     *
     * It NAVIGATES. It does not dispatch, and it must not: approving a gate is
     * an editorial judgement made in front of the thing being judged, and
     * spending is a second, separate press on that same page. A dashboard that
     * could commit money from a summary row would be the generate-and-upload
     * button assembled out of smaller parts.
     */
    .spend {
        flex: none;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 1px;
        padding: 9px 15px;
        border-radius: 8px;
        border: 0;
        background: linear-gradient(180deg, var(--money), color-mix(in srgb, var(--money) 82%, #000));
        color: var(--gate-ink);
        cursor: pointer;
        box-shadow: var(--lift);
        text-align: center;
    }

    .spend:hover { filter: brightness(1.07); text-decoration: none; color: var(--gate-ink); }
    .spend .what { font-size: 13.5px; font-weight: 700; line-height: 1.2; }
    .spend .howmuch { font-family: ui-monospace, "Cascadia Mono", Consolas, monospace; font-size: 11px; line-height: 1.2; opacity: .78; }

    /* The stage bars on an in-flight story. */
    .stages { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--s-2) var(--s-9); }
    .stages .stage { display: grid; grid-template-columns: 104px 1fr 84px; align-items: center; gap: var(--s-4); }
    .stages .stage .nm { font-family: ui-monospace, "Cascadia Mono", Consolas, monospace; font-size: 11.5px; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .stages .stage .n { font-family: ui-monospace, "Cascadia Mono", Consolas, monospace; font-size: 11.5px; color: var(--meta); text-align: right; }

    @media (max-width: 1500px) {
        .stages { grid-template-columns: minmax(0, 1fr); }
    }

    /* The scene grid, sized to fill whatever column it lands in. */
    .cells { display: grid; grid-template-columns: repeat(auto-fill, minmax(13px, 1fr)); gap: 3px; }
    .cells .cell { width: auto; height: 13px; }

    /* A title inside a card row: the story, at reading size. */
    .title {
        display: block;
        font-size: 15px;
        font-weight: 500;
        letter-spacing: -.012em;
        color: var(--text);
    }

    .title:hover { color: var(--accent-ink); }

    /* The uppercase micro-heading used inside a card row, where a `.head` would
       be too much furniture. Same treatment as `label`, without the form
       semantics. */
    .label-inline {
        font-size: 10.5px;
        font-weight: 600;
        letter-spacing: .12em;
        text-transform: uppercase;
        color: var(--meta);
    }

    /* Content beside a control that must not shrink. */
    .split { display: flex; gap: var(--s-6); align-items: center; flex-wrap: nowrap; }
    .minw { min-width: 0; }
    .row.tight { gap: var(--s-3); }
    .row.wide-gap { gap: var(--s-7); }
    .scrollx { overflow-x: auto; max-width: 100%; }
    .band .warnicon { flex: none; }
    .bar .moneyfill { background: var(--money); }

    /*
     * The sentence under the scene grid. Amber-inked when the claim is
     * abandoned, because that is the case where the grid is reporting work in
     * progress that stopped hours ago — a cell that says "running" with nothing
     * running is the false-success pattern drawn as a square.
     */
    .claimnote { color: var(--meta); }
    .claimnote.abandoned { color: var(--fail-ink); font-weight: 550; }

    .figure.sm { font-size: 19px; margin-top: var(--s-2); }

    /*
     * The collapsed reasoning on a calm provider card.
     *
     * Native `<details>`, so it costs no script and works when nothing else on
     * the page does — the same reason this stylesheet is inlined. Deliberately
     * quiet: it is an explanation of a state that is fine, and the cards that
     * are NOT fine print the same text in the open instead.
     */
    details.why { margin-top: var(--s-2); }

    details.why > summary {
        cursor: pointer;
        list-style: none;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        font-size: 11px;
        color: var(--meta);
        text-transform: uppercase;
        letter-spacing: .08em;
        font-weight: 650;
    }

    details.why > summary::-webkit-details-marker { display: none; }
    details.why > summary::after { content: '›'; transition: transform .12s ease; font-size: 13px; }
    details.why[open] > summary::after { transform: rotate(90deg); }
    details.why > summary:hover { color: var(--accent-ink); }

    /* The rail's cards are narrow; a number should never be pushed off by a
       badge beside it. */
    .card .row-item .badge { flex: none; }

    /* The band's message column, which is the wide one. */
    .band .msg { min-width: 0; }

    /*
     * WHEN a reading was taken.
     *
     * Quiet while it is current and LOUD once it is not, because those are two
     * different facts: a fresh reading needs no comment, and a reading taken
     * before the workers were restarted is a panel reporting a machine that no
     * longer exists. The renders pages stop refreshing themselves whenever
     * nothing is running — which is exactly when workers get restarted — so
     * this is not a rare corner.
     */
    .reading {
        font-family: ui-monospace, "Cascadia Mono", Consolas, monospace;
        font-size: 11.5px;
        color: var(--meta);
    }

    .reading.stale {
        color: var(--warn-ink);
        font-weight: 650;
        padding: var(--s-2) var(--s-4);
        border-radius: var(--radius-sm);
        background: var(--warnfill-bg);
        box-shadow: inset 3px 0 0 var(--warn);
        display: inline-block;
    }

    .figures { display: grid; grid-template-columns: 78px 1fr 64px; align-items: center; gap: var(--s-3); padding: 3px 0; }
    .figures .nm { font-family: ui-monospace, "Cascadia Mono", Consolas, monospace; font-size: 11.5px; color: var(--muted); }
    .figures .amt { font-family: ui-monospace, "Cascadia Mono", Consolas, monospace; font-size: 11.5px; color: var(--muted); text-align: right; }

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

    /* -- Gate 2 ------------------------------------------------------------
     *
     * The densest screen in the console, and the only one where money is
     * authorised under a list the operator scrolls. Everything here exists to
     * keep the three decisions — what is wrong, who has a face, what it costs —
     * above the 168 rows rather than buried under them.
     */

    /*
     * The decisions, side by side.
     *
     * Stacked, they pushed the spend panel below the fold on any story with
     * more than a couple of advisories, so the screen that authorises spending
     * opened on a column of amber. Collapses to one column early: three
     * columns of prose at 1100px is worse than a stack.
     *
     * THE ROW COUNTS ITS CHILDREN, NOT ITS DESIGN.
     *
     * `repeat(3, ...)` is a track count written from the busy case, and Gate 1
     * proved what that costs: three advisory groups, two of them with findings,
     * and the third still cut a 1fr track — so the row opened on an empty column
     * that shoved the two real ones right. Nothing failed, nothing was missing,
     * and the page was ragged.
     *
     * Implicit tracks instead: one column per child that actually renders, each
     * an equal fraction, so the row FILLS whatever width it is given whether it
     * holds two groups or four. `x-gate-group` is the other half — a group
     * with nothing in it renders no child, so it gets no track. Weighted tracks
     * are separately wrong here and were tried: they left the panels at their
     * own widths with voids between them at 1750px.
     */
    .gatecols {
        display: grid;
        /* money, cast, advisories — in that order, which is document order
           too. See the blade: the decisions must lead on a narrow screen, not
           only in the grid. */
        grid-auto-flow: column;
        grid-auto-columns: minmax(0, 1fr);
        gap: 12px;
        align-items: start;
        margin-bottom: 16px;
    }

    @media (max-width: 1180px) { .gatecols { grid-auto-flow: row; grid-auto-columns: auto; } }

    /* The grid supplies the gap; the children stop supplying their own. */
    .gatecols > * { margin-bottom: 0; }

    /*
     * The advisory cluster.
     *
     * A HEADER over the existing alerts, and deliberately not a panel that
     * swallows them. The mock drew one amber-edged box with plain rows inside,
     * which would take the accent edge, the tint and the shadow off every
     * individual warning — quieting fourteen advisories in order to tidy them.
     * The rule for this stylesheet is that the fix for a column of identical
     * amber boxes is to close the gaps, never to quieten any of them, so each
     * one keeps its `.alert.warn` treatment and only gains a heading saying how
     * many there are.
     */
    .advisories > .head {
        display: flex;
        align-items: center;
        gap: 9px;
        margin-bottom: 9px;
    }

    /*
     * ONE DECLARATION, TWO SURFACES, and that is deliberate rather than tidy.
     *
     * The cluster heading sits ABOVE a set of alerts; `.alerthead` sits INSIDE
     * one, which is what Gate 1's three advisory cards need — each is its own
     * alert with its own subject, so there is nothing for a shared heading to
     * be shared across. Same micro-label at the same size in the same place in
     * the reading order, so it is one block with two selectors. Two blocks is
     * how a retry prompt came to disagree with the guard it restated.
     */
    .advisories > .head h2,
    .alerthead h2 {
        margin: 0;
        font-size: 10.5px;
        letter-spacing: .12em;
        text-transform: uppercase;
        color: var(--warn-ink);
    }

    /*
     * The ink follows the ALERT's own kind, so it cannot drift from it.
     *
     * A refusal heading in warning amber would be the console's rule about
     * volume broken in the quietest possible way — nothing fails, the box is
     * still red, and only the sentence naming what is wrong is the wrong
     * colour. Writing it as `.fail-ink` at the call site would have made that a
     * thing somebody remembers per card.
     */
    .alert.fail > .alerthead h2,
    .alert.err > .alerthead h2 { color: var(--fail-ink); }

    /*
     * Beside the heading, NOT pushed to the far edge.
     *
     * `margin-left: auto` put it at the right-hand end of a column whose
     * container paints nothing, so the number floated in the gap between two
     * panels and read as belonging to neither. A count is part of the label it
     * counts.
     */
    .advisories > .head .count,
    .alerthead .count {
        font-family: ui-monospace, "Cascadia Mono", Consolas, monospace;
        font-size: 10px;
        font-weight: 700;
        padding: 2px 7px;
        border-radius: var(--radius-sm);
        color: var(--warn-ink);
        background: color-mix(in srgb, var(--warn) 14%, var(--panel-2));
        border: 1px solid color-mix(in srgb, var(--warn) 50%, transparent);
    }

    /*
     * An alert's own header row: the subject, and how many of it there are.
     *
     * Beside the heading rather than at the far edge, for the reason the
     * cluster's count already records — `margin-left: auto` put the number at
     * the right-hand end of a column whose container paints nothing, so it
     * floated in the gap between two panels and read as belonging to neither. A
     * count is part of the label it counts.
     */
    .alerthead {
        display: flex;
        align-items: center;
        gap: 9px;
        flex-wrap: wrap;
        padding-bottom: 9px;
        margin-bottom: 10px;
        border-bottom: 1px solid var(--line);
    }

    .alerthead .ico { flex: none; }

    /* Inside a column the measure cap is the column, not 96ch. */
    .gatecols .alert { max-width: none; }

    /*
     * FILL THE WIDTH — a modifier on the alert, never a rule scoped to a parent.
     *
     * `.alert` caps its measure at 96ch, deliberately: a four-line refusal
     * running the full width gets skimmed. The only thing lifting that cap was
     * `.gatecols .alert`, scoped to the decision row — which the quiet layout
     * DELETES. So on a story past Gate 2 the locked banner, the failure alert
     * and every advisory silently snapped back to 96ch and rendered at about a
     * third of a 1770px viewport, while the strip and the scene table beside
     * them, which carry no cap, stayed full width. Nothing failed; the page just
     * went ragged.
     *
     * The lesson is the rule's shape rather than its value: a rule that
     * neutralises a global cap must not be scoped to a container the layout is
     * allowed to remove. This one lives on the element that needs it, so it
     * survives any arrangement.
     *
     * Used where the content is STRUCTURED rather than prose — the failure
     * alert carries a table with a long error column, the locked banner is a
     * two-column grid — so the measure argument does not apply to it.
     */
    .alert.wide { max-width: none; }

    /*
     * `wide` is about the SURFACE, not the line.
     *
     * Lifting the cap made the locked banner one sentence of plain prose
     * running the full 1770px, which is the exact thing the 96ch cap exists to
     * prevent — a four-line refusal at full width gets skimmed, and skimmed is
     * indistinguishable from unread. The banner was widened because it holds a
     * two-column grid, not because its prose wanted the room.
     *
     * So the prose inside a wide alert carries its own measure. The table in
     * the failure alert and the rates grid in the banner are deliberately NOT
     * capped: they are structured content, where the width is the point.
     */
    .alert.wide > .small { max-width: 84ch; }
    .lockedgate > div:first-child { max-width: 84ch; }

    /*
     * `.measure` IS THE CAP, so it lives on the element that asks for it.
     *
     * It was `.alert.wide > .measure` — a direct child of a wide alert — and
     * Gate 3 wrote it on prose inside a `.panel`, and on a `.grow` one level
     * down inside a wide alert. Three of the four usages matched nothing:
     * class-audit answered CONTEXT, which is its BENIGN verdict, and CONTEXT is
     * benign only while the ancestor is really there.
     *
     * Exactly `.warnfill` again — a class the markup asks for, the stylesheet
     * does not answer, and nothing goes red — and exactly the lesson `.alert.wide`
     * was extracted for: a rule that neutralises or imposes a global cap must not
     * be scoped to a container the layout is allowed to change.
     */
    .measure { max-width: 84ch; }

    /*
     * The advisory list, using the width without lengthening the line.
     *
     * Seventeen advisories stacked in one 96ch column is a thin ribbon down the
     * left of a wide screen; seventeen at full width is seventeen 180-character
     * lines, which is what the cap exists to prevent. Columns give both: the
     * block fills the width, and each advisory keeps a readable measure.
     *
     * Each one still carries its own accent edge, tint and shadow. The rule
     * about a run of amber boxes is that the gaps close, never that any of them
     * gets quieter — and side by side they cluster the same way.
     */
    .advisories.wide {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(430px, 1fr));
        gap: 0 12px;
        align-items: start;
    }

    .advisories.wide > .head { grid-column: 1 / -1; }
    .advisories.wide > .alert { max-width: none; margin-top: 0; }

    /*
     * The locked banner, two-up.
     *
     * The mock painted this in the alarm gradient. It is not an alarm: Gate 2
     * approved is a story going correctly, and spending the console's one
     * saturated flood on it would leave a stopped pipeline with nothing louder
     * to say. So it keeps `.alert.warn` — already louder than the panel beside
     * it, and measured as such by theme-audit — and takes only the mock's
     * layout: what this is, next to what editing it would cost.
     */
    .lockedgate { display: grid; grid-template-columns: minmax(0, 1.3fr) minmax(0, 1fr); gap: 0; }

    .lockedgate > .cost {
        padding-left: 17px;
        margin-left: 17px;
        border-left: 1px solid color-mix(in srgb, var(--warn) 40%, transparent);
    }

    @media (max-width: 900px) {
        .lockedgate { grid-template-columns: 1fr; }

        .lockedgate > .cost {
            padding: 12px 0 0;
            margin: 12px 0 0;
            border-left: none;
            border-top: 1px solid color-mix(in srgb, var(--warn) 40%, transparent);
        }
    }

    .lockedgate .rates { display: grid; grid-template-columns: auto minmax(0, 1fr); gap: 5px 11px; }
    .lockedgate .rates .amt { font-family: ui-monospace, "Cascadia Mono", Consolas, monospace; white-space: nowrap; }

    /*
     * Gate 1: the pair the operator writes together.
     *
     * Premise and cast age are one decision taken twice — what the story is,
     * and who is in it — and the design has them side by side. Stacked in one
     * panel they were two short textareas with a column of whitespace beside
     * them on any real screen.
     */
    .twoup {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0 22px;
        align-items: start;
    }

    @media (max-width: 1080px) { .twoup { grid-template-columns: 1fr; gap: 0; } }

    /*
     * A panel header bar that closes with a rule.
     *
     * `.panelhead` is the label row inside a padded panel and Gate 3 has four
     * of them; the design's premise, cast-age and spine panels want the same
     * row separated from the body by a line, in a `.panel.flush`. That is a
     * MODIFIER on the header rather than a new rule under `.panel.flush`,
     * because Gate 3's four are flush too and adding a border under the
     * container would have drawn a line on four settled panels that never asked
     * for one. Same shape as `.alert.wide` and `.measure`: the element carries
     * what the element wants, so no arrangement can grant or revoke it.
     */
    .panelhead.ruled {
        padding-bottom: 10px;
        border-bottom: 1px solid var(--line);
        margin-bottom: 0;
    }

    /* A flush panel gives no padding, so the header supplies its own — and
       states the bottom explicitly rather than letting the shorthand quietly
       overwrite the 10px above. */
    .panel.flush .panelhead.ruled { padding: 11px 15px 10px; }

    /*
     * A section heading with its explanation on the same baseline.
     *
     * `h2` here is a 32px-top-margin, top-bordered page heading — right for
     * "Acts" as a landmark, wrong for a heading whose whole job is to be read
     * with the sentence beside it. This was two hand-written blocks with an
     * inline `style="margin-top:-6px"` pulling the paragraph back under the
     * heading, which is a workaround for the arrangement rather than the
     * arrangement.
     */
    .sectionhead {
        display: flex;
        align-items: baseline;
        gap: 11px;
        flex-wrap: wrap;
        margin-bottom: 10px;
    }

    .sectionhead h2 {
        margin: 0;
        padding: 0;
        border: 0;
        font-size: 16px;
        font-weight: 560;
        letter-spacing: -.015em;
        white-space: nowrap;
    }

    .sectionhead p {
        margin: 0;
        flex: 1;
        min-width: 22ch;
        max-width: 132ch;
        font-size: 12.5px;
        color: var(--muted);
    }

    /* Inside a panel's own ruled header the section head supplies no margin. */
    .panelhead.ruled > .sectionhead { margin-bottom: 0; width: 100%; }

    /*
     * The locale hits: which act, which term, and the sentence around it.
     *
     * A fixed first column so the act numbers form a readable edge rather than
     * being buried at the head of a wrapped list item. It was a `<ul>`, where
     * "Act 3 — torch …swept the beam of a torch across the garage…" is one
     * run-on line and the operator's eye has to find the term inside it twice.
     */
    .localehits { display: flex; flex-direction: column; gap: 7px; }

    .localehits > .hit {
        display: grid;
        grid-template-columns: 56px minmax(0, 1fr);
        gap: 8px;
        align-items: baseline;
    }

    .localehits .at {
        font-family: ui-monospace, "Cascadia Mono", Consolas, monospace;
        font-size: 11.5px;
        color: var(--meta);
    }

    .localehits code { color: var(--warn-ink); }

    /*
     * Meta text that is a DEADLINE rather than a description.
     *
     * "last free place to state it" is the only note on the premise pair that
     * carries a consequence — after Gate 2 dispatches, changing the cast age
     * means reopening this gate and re-extracting every description the scene
     * prompts were built from. In `--muted` beside three other muted notes it
     * reads as one more explanation. It is the `-ink` twin, so it clears
     * contrast on white; `--warn` itself measures 1.8:1 there.
     */
    .warnnote { color: var(--warn-ink); }

    /*
     * -- Gate 1: the outline -----------------------------------------------
     *
     * The spine and the acts are both GRIDS, and for the same reason the scene
     * table is a table: this page is read in one pass before a single approve
     * decision, and a seven-item column on a 1770px screen is three screens of
     * scrolling beside a column of whitespace. The design has both two-up.
     */
    .spinegrid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0 14px;
        align-items: start;
    }

    .actgrid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
        align-items: start;
        margin-bottom: 16px;
    }

    @media (max-width: 1180px) {
        .spinegrid,
        .actgrid { grid-template-columns: minmax(0, 1fr); }

        .spinegrid { gap: 0; }
    }

    /* The grid supplies the gap; the cards stop supplying their own. */
    .actgrid > .actcard { margin-bottom: 0; }

    /*
     * The act's phase, on the card's edge as well as in its badge.
     *
     * The direction of an act is the one thing the script generator branches
     * on, and while scanning seven cards a 3px edge answers it without reading
     * anything. Two modifiers rather than four: escalation is the ordinary case
     * and takes no edge, and search and refusal are one movement from the
     * scanner's point of view — the ground is being lost by the antagonist.
     */
    .actcard { border-left: 3px solid transparent; }
    .actcard.leaving { border-left-color: var(--money); }
    .actcard.turning { border-left-color: var(--ok); }

    .actcard > .alerthead { padding: 0 0 9px; }

    /*
     * A field whose CONTENT is flagged, marked on the field.
     *
     * The state was carried by a badge beside the label and nothing else, so a
     * missing spine field looked exactly like a filled one until the badge was
     * read — on a two-column grid of seven, which is the arrangement that makes
     * scanning the point. The badge stays; this is the same fact at the size
     * the eye actually catches.
     */
    .field.problem > textarea,
    .field.problem > input { border-color: color-mix(in srgb, var(--fail) 45%, transparent); }

    .field.flagged > textarea,
    .field.flagged > input { border-color: color-mix(in srgb, var(--warn) 45%, transparent); }

    /*
     * THE GATE DECISION, KEPT ON SCREEN.
     *
     * Gate 1 is one approve press about a document three screens long, and the
     * button was at the bottom of the third screen. Sticky is not decoration
     * here: the operator reads the outline and decides, and a decision that
     * requires scrolling back past the thing being decided is a decision taken
     * from memory.
     *
     * It is rendered ONLY where the decision exists — see the blade. A sticky
     * bar with nothing in it would be `.dash.quiet`'s empty container nailed to
     * the bottom of the viewport, which is worse than the row it replaced.
     */
    .gatebar {
        position: sticky;
        bottom: 0;
        z-index: 4;
        display: flex;
        align-items: center;
        gap: 14px;
        flex-wrap: wrap;
        margin: 16px calc(var(--s-8) * -1) 0;
        padding: 12px var(--s-8);
        background: color-mix(in srgb, var(--bg-2) 94%, transparent);
        backdrop-filter: blur(8px);
        border-top: 1px solid var(--line);
    }

    .gatebar > .note {
        margin-left: auto;
        max-width: 52ch;
        text-align: right;
        font-size: 12px;
        color: var(--warn-ink);
    }

    /*
     * A refused save, beside the control that was pressed.
     *
     * Rendered by the refused-save component on every gate page that validates — inside
     * Gate 1's sticky bar, above Gate 2's inline Save, above Gate 4's Save
     * sheet. Where the parent is a flex row of controls the refusal takes the
     * whole row so it reads before the buttons rather than beside one of them;
     * elsewhere flex-basis does nothing and the alert is just an alert. It is
     * an ordinary `.alert.err` — the same red every refusal in the console
     * wears — and on the blurred bar it is the loudest thing there, which is
     * the point.
     *
     * ON THE ELEMENT, NOT UNDER A CONTAINER. This was `.gatebar > .refused`
     * for one page and would have answered CONTEXT on the other two — the
     * `.measure` lesson: a rule the layout may move out from under.
     */
    .alert.refused {
        flex-basis: 100%;
        margin-bottom: 4px;
    }

    @media (max-width: 760px) {
        .gatebar { position: static; margin-left: 0; margin-right: 0; padding-left: 0; padding-right: 0; }
        .gatebar > .note { margin-left: 0; text-align: left; }
    }


    /* -- Gate 3: the preview ----------------------------------------------
     *
     * The player and the numbers on the left, the chapters and the decision on
     * the right. Two columns rather than the gate row's implicit tracks,
     * because these two are not peers: the left column is what you look at for
     * ten minutes and the right is what you do afterwards.
     */
    .previewcols {
        display: grid;
        grid-template-columns: minmax(0, 1.62fr) minmax(0, 1fr);
        gap: 16px;
        align-items: start;
    }

    @media (max-width: 1180px) { .previewcols { grid-template-columns: 1fr; } }

    .previewcols > .col { display: flex; flex-direction: column; gap: 16px; min-width: 0; }

    /* The grid supplies the gap; the panels stop supplying their own. */
    .previewcols > .col > .panel,
    .previewcols > .col > .alert { margin-bottom: 0; }

    /*
     * The act boundaries, as their own rail under the player.
     *
     * The mock draws these ON the video scrubber. Nothing can: a native
     * `<video controls>` scrubber is the browser's, and swapping in a custom
     * player to gain seven tick marks would put this gate's one job — watching
     * the file — behind a pile of JavaScript that can fail. Same information,
     * same source, directly under the thing it describes.
     */
    .actsrail {
        position: relative;
        height: 13px;
        margin: 0 2px;
        border-radius: 0 0 var(--radius) var(--radius);
        background: linear-gradient(var(--panel-2), transparent);
    }

    .actsrail .tick {
        position: absolute;
        top: 0;
        width: 2px;
        height: 9px;
        background: var(--money);
        border-radius: 1px;
    }

    /*
     * The render's numbers.
     *
     * `.big` is a readout, not a heading: the operator is checking a handful of
     * figures against what they expected to sit through, and a 21px monospace
     * number is read in one glance where a table row is not.
     */
    .facts {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 14px;
        align-items: start;
    }

    .facts .span2 { grid-column: span 2; }

    @media (max-width: 1080px) {
        .facts { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .facts .span2 { grid-column: span 2; }
    }

    .big { font-size: 21px; line-height: 1; letter-spacing: -.02em; font-weight: 500; }

    /*
     * The target window, on an axis derived from the story's own window.
     *
     * THE MOCK HARDCODES THE SCALE — 30 min at 20% and 40 min at 67%, which is
     * about 25.7 to 47 minutes. That is fine for the story it was drawn against
     * and puts `sample-story` at 2:42 somewhere near MINUS 108%, off the
     * element entirely, on the one story that is parked at `rendered`
     * permanently. So the axis comes from the story and the mark is clamped in
     * PHP; this only paints it.
     *
     * A clamped mark cannot say how far outside it is, which is why the
     * distance is printed beside it: 22 seconds under and 27 minutes under are
     * the same picture and very different facts.
     */
    .windowbar { position: relative; height: 20px; margin: 2px 0 6px; }

    .windowbar .axis {
        position: absolute;
        left: 0; right: 0; top: 7px;
        height: 6px;
        border-radius: 3px;
        background: var(--idle);
        box-shadow: var(--inset-bar);
    }

    .windowbar .zone {
        position: absolute;
        top: 7px;
        height: 6px;
        border-radius: 3px;
        background: color-mix(in srgb, var(--ok) 40%, transparent);
    }

    .windowbar .at {
        position: absolute;
        top: 1px;
        width: 2px;
        height: 18px;
        border-radius: 1px;
        background: var(--meta);
    }

    .windowbar .at.ok { background: var(--ok); }
    .windowbar .at.warn { background: var(--warn); }

    /*
     * A panel's own header row: a label, a note, and a badge pushed right.
     *
     * Gate 3 has four of these and they were four hand-written `.row`s with
     * their own margins. One rule, so a fifth cannot be a fifth arrangement.
     */
    .panelhead {
        display: flex;
        align-items: center;
        gap: 9px;
        margin-bottom: 11px;
    }

    .panelhead h2 {
        margin: 0;
        font-size: 10.5px;
        letter-spacing: .12em;
        text-transform: uppercase;
        color: var(--meta);
    }

    .panelhead .right { margin-left: auto; }

    /* A flush panel's own prose needs the padding the panel gave up. */
    .panel.flush .panelhead { padding: 12px 15px 0; margin-bottom: 9px; }
    .pad { padding: 11px 15px; margin: 0; }

    /*
     * The gate call itself.
     *
     * The one panel on this page that is a decision rather than a reading, and
     * it is marked as one: the accent edge is what you PRESS, which is the same
     * split the rest of the console keeps. It is not an alarm and does not take
     * a status colour — approving a render is a story going correctly.
     */
    .gatecall {
        border-color: color-mix(in srgb, var(--accent) 34%, transparent);
        border-top: 3px solid var(--accent);
        background: linear-gradient(180deg, color-mix(in srgb, var(--accent) 9%, var(--panel)), var(--panel));
        box-shadow: var(--lift-lg);
    }

    .gatecall > strong { display: block; font-size: 15.5px; font-weight: 660; margin-bottom: 6px; }


    /* -- Gate 4: the publish sheet ----------------------------------------- */

    /*
     * The title against its target and its hard limit.
     *
     * Same shape as Gate 3's window bar and the same reason: the mock draws it
     * for a title inside both marks, and a title over 100 characters is the
     * case the hard limit exists FOR. The fill is clamped in PHP; this paints
     * it, and the overage is stated in words beside it because a clamped bar
     * reports 101 characters and 200 characters identically.
     */
    .meter { position: relative; height: 8px; margin: 9px 0 6px; }

    .meter .track {
        position: absolute;
        left: 0; right: 0; top: 1px;
        height: 6px;
        border-radius: 3px;
        background: var(--idle);
        box-shadow: var(--inset-bar);
    }

    .meter .used {
        position: absolute;
        left: 0; top: 1px;
        height: 6px;
        border-radius: 3px;
        background: var(--ok);
    }

    .meter .used.warn { background: var(--warn); }
    .meter .used.fail { background: var(--fail); }

    /* The target, which is a preference — the hard limit is the rail's end. */
    .meter .cap {
        position: absolute;
        top: -2px;
        width: 2px;
        height: 12px;
        border-radius: 1px;
        background: var(--meta);
    }

    /*
     * The tags, each one told whether it is inside the budget.
     *
     * A total that is over, with no indication of WHICH entries are past the
     * line, is a number nobody can act on. "Enforce it, do not silently
     * truncate" is the spec's own wording, and the point of enforcing it here
     * is that dropping a tag becomes a decision instead of an accident at
     * upload.
     */
    .tags { display: flex; flex-wrap: wrap; gap: 6px; }

    .tagrow {
        display: inline-flex;
        align-items: baseline;
        gap: 7px;
        padding: 3px 9px;
        border-radius: var(--radius-sm);
        background: var(--panel-2);
        border: 1px solid var(--line-2);
        font-size: 12.5px;
    }

    .tagrow .mono { color: var(--meta); font-size: 10.5px; }

    /*
     * A tag past the budget. Loud, and deliberately not a dimming: this is the
     * one on the page that will be LOST, and a quieter treatment for the thing
     * being dropped is the direction this stylesheet never allows.
     */
    .tagrow:has(.over) {
        border-color: color-mix(in srgb, var(--fail) 55%, transparent);
        background: var(--alert-err-bg);
    }

    .tagrow .over {
        font-size: 9.5px;
        font-weight: 700;
        letter-spacing: .07em;
        text-transform: uppercase;
        color: var(--fail-ink);
    }

    /* -- The scene table --------------------------------------------------- */

    /*
     * A grid, and deliberately NOT a horizontal scroll region.
     *
     * It was one — `min-width: 1120px` inside `overflow-x: auto` — and two
     * things were wrong with that, both invisible at the mock's width.
     *
     * The narration and the frame are the pair the operator compares, and a
     * scrollable row puts them at opposite ends of it: you read the narration,
     * scroll right, and the sentence you were comparing against has gone. They
     * are adjacent columns here and both are always on screen.
     *
     * And a loud marker must never be scrollable out of view. `read-only` sat
     * in the LAST column, so the one fact saying this row cannot be edited was
     * the first thing to disappear. Rather than pinning columns, the scroll
     * region is gone: every marker lives in `.meta`, and below 1080px the row
     * stacks. The bad outcome is unreachable rather than checked for.
     */
    .scenetable .thead,
    .scenetable .scenerow {
        display: grid;
        grid-template-columns: 34px 92px 132px minmax(0, 1fr) minmax(0, 1.05fr) auto;
        gap: 0;
    }

    .scenetable .thead {
        padding: 7px 13px;
        background: var(--panel-2);
        border-bottom: 1px solid var(--line);
    }

    .scenetable .thead span {
        font-size: 9.5px;
        font-weight: 600;
        letter-spacing: .11em;
        text-transform: uppercase;
        color: var(--meta);
    }

    .scenetable .thead .acts { text-align: right; display: block; }

    .scenetable .scenerow {
        padding: 9px 13px;
        border-bottom: 1px solid var(--line-2);
        align-items: start;
    }

    .scenetable .scenerow:last-child { border-bottom: none; }
    .scenetable .scenerow:hover { background: var(--panel-2); }

    .scenetable .scenerow > .seq {
        font-family: ui-monospace, "Cascadia Mono", Consolas, monospace;
        color: var(--meta);
        font-size: 12.5px;
        padding-right: 8px;
    }

    /*
     * Denser than the Gate 4 chooser, where the still is judged as a picture.
     * Here it identifies a row, so it is sized to the row.
     *
     * A MODIFIER, not a rule scoped to `.scenetable`, and the grid is why. The
     * second track is 92px wide; `.still`'s own default is 136px. Scoped to the
     * wrapper, renaming or removing `.scenetable` would leave every still 44px
     * wider than the column holding it — the same shape as the measure cap that
     * reverted when `.gatecols` went away, except this one overflows the
     * layout rather than narrowing it. Found by tools/scoped-override-audit.php
     * sweeping for exactly this after the first instance.
     */
    .still.dense { width: 92px; height: 52px; margin-right: 12px; }

    .scenetable .meta { display: flex; flex-wrap: wrap; align-content: flex-start; gap: 4px; padding-right: 12px; }

    .scenetable .meta .dur {
        width: 100%;
        font-family: ui-monospace, "Cascadia Mono", Consolas, monospace;
        font-size: 11.5px;
        color: var(--meta);
    }

    .scenetable .narration { margin: 0; padding-right: 14px; font-size: 13px; line-height: 1.5; }

    .scenetable .prompt {
        margin: 0;
        padding-right: 12px;
        min-width: 0;
        font-family: ui-monospace, "Cascadia Mono", Consolas, monospace;
        font-size: 11.5px;
        line-height: 1.55;
        color: var(--muted);
        white-space: pre-wrap;
        word-break: break-word;
    }

    .scenetable .chips { display: flex; flex-wrap: wrap; align-items: center; gap: 4px; margin-top: 5px; }
    .scenetable .acts { display: flex; justify-content: flex-end; align-items: flex-start; gap: 4px; }

    .scenetable .readonly {
        display: flex;
        align-items: center;
        gap: 6px;
        color: var(--meta);
        font-family: ui-monospace, "Cascadia Mono", Consolas, monospace;
        font-size: 11.5px;
        white-space: nowrap;
    }

    /* The row's own edit form spans the whole grid rather than fighting it. */
    .scenetable .editing { grid-column: 1 / -1; }

    /*
     * Below this the six columns stop being readable, so the row STACKS rather
     * than scrolling. This is what replaces the horizontal scroll: nothing is
     * ever off to the right, so no marker can be scrolled away from.
     */
    @media (max-width: 1080px) {
        .scenetable .thead { display: none; }

        .scenetable .scenerow {
            grid-template-columns: 34px minmax(0, 1fr);
            gap: 4px 0;
        }

        .scenetable .scenerow > .still,
        .scenetable .scenerow > div,
        .scenetable .scenerow > p { grid-column: 2; padding-right: 0; }

        .scenetable .scenerow > .seq { grid-column: 1; grid-row: 1; }
        .scenetable .acts { justify-content: flex-start; }
    }

    /* -- The style block --------------------------------------------------- */

    /*
     * Said once, because it is written once.
     *
     * `scenes.image_prompt` stores the ASSEMBLED prompt — the frame, then the
     * verbatim cast block, then the art style and the constraints. Printing all
     * of it per row repeated four hundred identical words 168 times and buried
     * the one section that differs between scenes. Accent rather than a status
     * colour: nothing is wrong here, it is a fact about how a prompt is built.
     */
    .styleblock {
        display: flex;
        align-items: flex-start;
        gap: 11px;
        padding: 10px 13px;
        border-bottom: 1px solid var(--line);
        background: color-mix(in srgb, var(--accent) 7%, var(--panel));
    }

    .styleblock > .grow { flex: 1; min-width: 0; }

    .styleblock .full {
        margin: 8px 0 0;
        padding: 9px 11px;
        border-radius: var(--radius-sm);
        background: var(--panel-2);
        border: 1px solid var(--line);
        font-family: ui-monospace, "Cascadia Mono", Consolas, monospace;
        font-size: 11.5px;
        line-height: 1.6;
        color: var(--muted);
        white-space: pre-wrap;
        max-height: 260px;
        overflow: auto;
    }

    /* -- The scene-table toolbar ------------------------------------------- */

    .scenehead {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
        padding: 10px 13px;
        border-bottom: 1px solid var(--line);
    }

    .scenehead h2 {
        margin: 0;
        font-size: 10.5px;
        letter-spacing: .12em;
        text-transform: uppercase;
        color: var(--meta);
    }

    /* A segmented control, built on buttons because it drives a Livewire
       property rather than a link. */
    .seg {
        display: inline-flex;
        align-items: center;
        gap: 2px;
        padding: 2px;
        border-radius: var(--radius);
        background: var(--panel-2);
        border: 1px solid var(--line);
    }

    .seg .lbl {
        padding: 3px 8px;
        font-size: 10px;
        font-weight: 600;
        letter-spacing: .09em;
        text-transform: uppercase;
        color: var(--meta);
    }

    .seg button {
        padding: 4px 10px;
        border: 0;
        border-radius: var(--radius-sm);
        background: transparent;
        color: var(--muted);
        font-size: 12px;
        font-weight: 600;
        box-shadow: none;
    }

    .seg button:hover { color: var(--text); background: var(--panel); }

    .seg button.on {
        background: color-mix(in srgb, var(--accent) 18%, transparent);
        box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--accent) 40%, transparent);
        color: var(--accent);
    }

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
