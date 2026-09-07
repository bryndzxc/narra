<style>
    :root { --panel: #ffffff; --ink: #11141a; }
    :root[data-theme="dark"] { --panel: #1c1e2b; --ink: #e9e9ed; }
    @media (prefers-color-scheme: dark) {
        :root:not([data-theme="light"]) { --panel: #1c1e2b; --ink: #e9e9ed; }
    }
    .alpha { color: var(--ink); background: var(--panel); }
    .beta { color: var(--ink); background: var(--panel); }
    .gamma { border-color: var(--ink); }
</style>
