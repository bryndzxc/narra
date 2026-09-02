{{--
    One stylesheet for the whole internal tool, inlined.

    No build step on purpose: this is an operator console that has to work when
    Vite is not running, and a render-queue dashboard that fails to load because
    an asset pipeline is down is worse than a plain one that always loads.
--}}
<style>
    :root {
        --bg: #12141a;
        --panel: #1a1d25;
        --panel-2: #22262f;
        --line: #2e3340;
        --text: #e6e8ee;
        --muted: #8b93a7;
        --ok: #3fb950;
        --run: #58a6ff;
        --fail: #f85149;
        --warn: #d29922;
        --idle: #3d4250;
        --money: #e3b341;
    }

    * { box-sizing: border-box; }

    body {
        margin: 0;
        background: var(--bg);
        color: var(--text);
        font: 14px/1.55 ui-sans-serif, -apple-system, "Segoe UI", system-ui, sans-serif;
    }

    a { color: var(--run); text-decoration: none; }
    a:hover { text-decoration: underline; }

    header.top {
        border-bottom: 1px solid var(--line);
        padding: 12px 24px;
        display: flex;
        align-items: center;
        gap: 20px;
        position: sticky;
        top: 0;
        background: var(--bg);
        z-index: 5;
    }

    header.top .brand { font-weight: 700; letter-spacing: .04em; }
    header.top nav a { color: var(--muted); font-size: 13px; }
    header.top nav a.on { color: var(--text); }
    header.top .sub { color: var(--muted); font-size: 12px; }
    header.top .right { margin-left: auto; }

    main { padding: 24px; max-width: 1180px; margin: 0 auto; }

    h1 { font-size: 20px; margin: 0 0 4px; }
    h2 { font-size: 13px; margin: 26px 0 10px; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); }
    h3 { font-size: 14px; margin: 0 0 8px; }

    .panel {
        background: var(--panel);
        border: 1px solid var(--line);
        border-radius: 8px;
        padding: 16px;
        margin-bottom: 14px;
    }

    .row { display: flex; gap: 14px; align-items: center; flex-wrap: wrap; }
    .grow { flex: 1; }
    .muted { color: var(--muted); }
    .mono { font-family: ui-monospace, "Cascadia Mono", Consolas, monospace; font-size: 12px; }
    .right { margin-left: auto; }
    .small { font-size: 12px; }

    .badge {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .06em;
        border: 1px solid var(--line);
        background: var(--panel-2);
        color: var(--muted);
    }

    .badge.ok { color: var(--ok); border-color: color-mix(in srgb, var(--ok) 40%, transparent); }
    .badge.run { color: var(--run); border-color: color-mix(in srgb, var(--run) 40%, transparent); }
    .badge.fail { color: var(--fail); border-color: color-mix(in srgb, var(--fail) 40%, transparent); }
    .badge.warn { color: var(--warn); border-color: color-mix(in srgb, var(--warn) 40%, transparent); }
    .badge.money { color: var(--money); border-color: color-mix(in srgb, var(--money) 45%, transparent); }

    .bar { height: 6px; background: var(--idle); border-radius: 3px; overflow: hidden; display: flex; }
    .bar span { display: block; height: 100%; }
    .bar .done { background: var(--ok); }
    .bar .bad { background: var(--fail); }
    .bar .busy { background: var(--run); }
    .bar .warnfill { background: var(--warn); }

    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--line); vertical-align: top; }
    th { color: var(--muted); font-size: 11px; text-transform: uppercase; letter-spacing: .06em; font-weight: 600; }
    tr:last-child td { border-bottom: none; }

    .alert { border-radius: 8px; padding: 12px 16px; margin-bottom: 14px; border: 1px solid; }
    .alert.fail { border-color: color-mix(in srgb, var(--fail) 45%, transparent); background: color-mix(in srgb, var(--fail) 10%, transparent); }
    .alert.warn { border-color: color-mix(in srgb, var(--warn) 45%, transparent); background: color-mix(in srgb, var(--warn) 10%, transparent); }
    .alert.ok   { border-color: color-mix(in srgb, var(--ok) 45%, transparent);   background: color-mix(in srgb, var(--ok) 8%, transparent); }
    .alert.money { border-color: color-mix(in srgb, var(--money) 55%, transparent); background: color-mix(in srgb, var(--money) 10%, transparent); }

    /* Forms. Dense by design: this is a tool, not a landing page. */
    label { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); margin-bottom: 4px; }

    input[type=text], input[type=number], textarea, select {
        width: 100%;
        background: var(--bg);
        border: 1px solid var(--line);
        border-radius: 6px;
        color: var(--text);
        padding: 8px 10px;
        font: inherit;
    }

    textarea { resize: vertical; min-height: 70px; line-height: 1.5; }
    input:focus, textarea:focus, select:focus { outline: none; border-color: var(--run); }

    .field { margin-bottom: 14px; }

    button {
        background: var(--panel-2);
        border: 1px solid var(--line);
        color: var(--text);
        border-radius: 6px;
        padding: 7px 14px;
        font: inherit;
        font-size: 13px;
        cursor: pointer;
    }

    button:hover { border-color: var(--muted); }
    button:disabled { opacity: .45; cursor: not-allowed; }
    button.primary { background: color-mix(in srgb, var(--ok) 22%, var(--panel-2)); border-color: color-mix(in srgb, var(--ok) 50%, transparent); }
    button.danger { background: color-mix(in srgb, var(--fail) 18%, var(--panel-2)); border-color: color-mix(in srgb, var(--fail) 45%, transparent); }
    button.gate { background: color-mix(in srgb, var(--money) 20%, var(--panel-2)); border-color: color-mix(in srgb, var(--money) 55%, transparent); font-weight: 600; }
    button.tiny { padding: 3px 8px; font-size: 12px; }

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
        gap: 4px;
        margin: 16px 0;
    }

    .pagination .page {
        display: inline-flex;
        align-items: center;
        min-width: 30px;
        justify-content: center;
        padding: 4px 9px;
        font-size: 12px;
        font-family: inherit;
        line-height: 1.4;
        color: var(--text);
        background: var(--panel-2);
        border: 1px solid var(--line);
        border-radius: 6px;
        cursor: pointer;
    }

    .pagination a.page:hover,
    .pagination button.page:hover { border-color: var(--muted); }

    .pagination .page.current {
        color: var(--run);
        border-color: color-mix(in srgb, var(--run) 55%, transparent);
        background: color-mix(in srgb, var(--run) 14%, var(--panel-2));
        font-weight: 600;
        cursor: default;
    }

    .pagination .page.disabled { opacity: .4; cursor: not-allowed; }

    .pagination .page.gap { border-color: transparent; background: none; cursor: default; }

    .pagination-count { margin-left: 8px; }

    .checks label { display: flex; align-items: flex-start; gap: 8px; text-transform: none; letter-spacing: 0; font-size: 13px; color: var(--text); margin-bottom: 8px; }
    .checks input { margin-top: 3px; }

    /* Gate stepper */
    .gates { display: flex; gap: 8px; margin-bottom: 18px; flex-wrap: wrap; }

    .gates a, .gates span {
        flex: 1 1 200px;
        border: 1px solid var(--line);
        border-radius: 8px;
        padding: 10px 12px;
        background: var(--panel);
        color: var(--muted);
        display: block;
    }

    .gates .num { font-size: 11px; letter-spacing: .08em; text-transform: uppercase; }
    .gates .name { color: var(--text); font-weight: 600; }
    .gates .state { font-size: 11px; }
    .gates .passed { border-color: color-mix(in srgb, var(--ok) 40%, transparent); }
    .gates .passed .state { color: var(--ok); }
    .gates .current { border-color: color-mix(in srgb, var(--money) 60%, transparent); background: color-mix(in srgb, var(--money) 8%, transparent); }
    .gates .current .state { color: var(--money); }
    .gates .locked { opacity: .55; }

    /* Scene list */
    .scene { display: flex; gap: 14px; padding: 12px 0; border-bottom: 1px solid var(--line); }
    .scene:last-child { border-bottom: none; }
    /* Stills are 16:9 sources at whatever the image model emits — Seedream
       returns 3416x1920 — so the box is always declared here and never left to
       the intrinsic size. This rule used to be scoped `.scene .still`, which
       meant the Gate 4 thumbnail picker, whose markup has no `.scene` ancestor,
       matched nothing and painted a 3416px-wide image into the layout. */
    .still { display: block; width: 128px; height: 72px; max-width: 100%; object-fit: cover; border-radius: 4px; background: var(--panel-2); flex: none; }
    /* The Gate 4 chooser, where the operator is judging the picture itself. */
    .still.pick { width: 208px; height: 117px; }
    .scene .seq { font-family: ui-monospace, Consolas, monospace; color: var(--muted); font-size: 12px; width: 34px; flex: none; }
    .scene .body { flex: 1; min-width: 0; }
    .scene .narration { margin: 0 0 6px; }
    .scene .prompt { margin: 0; font-size: 12px; color: var(--muted); font-family: ui-monospace, Consolas, monospace; }
    .scene .actions { display: flex; flex-direction: column; gap: 4px; flex: none; }

    .grid { display: flex; flex-wrap: wrap; gap: 3px; margin-top: 10px; }
    .cell { width: 14px; height: 14px; border-radius: 3px; background: var(--idle); }
    .cell.succeeded { background: var(--ok); }
    .cell.running { background: var(--run); }
    .cell.failed { background: var(--fail); }
    .cell.cancelled { background: var(--warn); }

    .legend { display: flex; gap: 14px; margin-top: 10px; font-size: 11px; color: var(--muted); }
    .legend span::before { content: ''; display: inline-block; width: 9px; height: 9px; border-radius: 2px; margin-right: 5px; }
    .legend .l-ok::before { background: var(--ok); }
    .legend .l-run::before { background: var(--run); }
    .legend .l-fail::before { background: var(--fail); }
    .legend .l-queued::before { background: var(--idle); }

    pre.err, pre.sheet {
        margin: 6px 0 0;
        padding: 10px 12px;
        background: var(--bg);
        border: 1px solid var(--line);
        border-radius: 6px;
        white-space: pre-wrap;
        word-break: break-word;
        font-family: ui-monospace, Consolas, monospace;
        font-size: 12px;
    }

    pre.err { color: #ffb4ae; }

    video { width: 100%; border-radius: 8px; background: #000; }

    .error { color: var(--fail); font-size: 12px; margin-top: 4px; }

    footer { color: var(--muted); font-size: 12px; padding: 24px; text-align: center; }
</style>
