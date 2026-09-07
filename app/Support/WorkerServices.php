<?php

namespace App\Support;

/**
 * The Windows service behind each queue, and the command that restarts it.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS A CLASS AND NOT TWO LITERALS IN TWO BLADES
 * ---------------------------------------------------------------------------
 *
 * It was two literals in two blades. `worker-health.blade.php` and
 * `dashboard.blade.php` each carried their own copy of the same three-row map,
 * and the dashboard's copy additionally invented a name for anything not in it:
 *
 *     'Narra'.ucfirst($queue)
 *
 * That fallback is the worst of the available options, and not because it is
 * usually wrong. It is usually RIGHT — the three services really are named
 * `NarraText`, `NarraAssets`, `NarraRender` — which is exactly what makes it
 * dangerous: rename a queue in config, or add a fourth, and the panel prints a
 * confident pasteable command naming a service that does not exist. A plausible
 * name that happens to be right today is a guess wearing the clothes of a fact,
 * and this project has paid for that shape before, in `updated_at` standing in
 * for a publication date.
 *
 * So an unmapped queue gets NO command. The state is still reported in full;
 * only the fix is withheld, because there is no fix to offer.
 *
 * ---------------------------------------------------------------------------
 * `Restart-Service`, NOT `nssm start`
 * ---------------------------------------------------------------------------
 *
 * Two independent reasons, and either alone would settle it.
 *
 * **`nssm` is not on PATH here.** `scripts/install-worker-services.ps1` knows
 * this — it resolves `nssm` through `Get-Command`, then a vendored
 * `tools\nssm\nssm.exe`, then a download. The panel did neither and printed a
 * bare `nssm start NarraText`, which fails with a command-not-found at the
 * moment an operator is trying to unstick a stopped pipeline. An instruction
 * that does not run is worse than none: it spends the reader's attention and
 * returns an error about the wrong thing.
 *
 * **`start` is the wrong verb for half the states that need it.** A worker that
 * is polling and taking nothing is a service that is RUNNING; `nssm start`
 * reports it already running and changes nothing. `Restart-Service` is correct
 * in both directions — it starts a stopped service and restarts a wedged one —
 * and it is a PowerShell builtin, so there is no PATH question at all.
 *
 * It needs an elevated prompt, the same as the installer does, and the callers
 * say so rather than letting an access-denied be the discovery.
 *
 * ---------------------------------------------------------------------------
 * The one copy that is NOT here
 * ---------------------------------------------------------------------------
 *
 * `scripts/install-worker-services.ps1` names the same three services, in
 * PowerShell, because it is the thing that creates them and cannot read a PHP
 * class. That copy is the definition; this one describes it. If a service is
 * ever renamed, both move — which is a real second source and is written down
 * here rather than left to be discovered.
 */
final class WorkerServices
{
    /**
     * Queue ROLE => the service `install-worker-services.ps1` registers for it.
     *
     * Keyed by role rather than by queue name, because the queue names are
     * configurable (`RENDER_QUEUE`, `ASSETS_QUEUE`, `TEXT_QUEUE`) and the
     * service names are not. Resolving through config in one direction keeps a
     * renamed queue matching the service it actually belongs to instead of
     * falling off the map.
     *
     * The installer suffixes additional workers — `NarraAssets2` and up — and
     * keeps the bare name for the first. Restarting the first is the documented
     * fix and the one this map names; a machine running several assets workers
     * has more than one command to run and the panel does not pretend
     * otherwise.
     */
    private const SERVICES = [
        'text' => 'NarraText',
        'assets' => 'NarraAssets',
        'render' => 'NarraRender',
    ];

    /** The service behind `$queue`, or null when nothing is mapped to it. */
    public static function forQueue(string $queue): ?string
    {
        foreach (self::SERVICES as $role => $service) {
            if ((string) config("render.queues.{$role}") === $queue) {
                return $service;
            }
        }

        return null;
    }

    /**
     * The command that restarts `$queue`'s worker, or null when there is none
     * to give.
     *
     * Null rather than a best guess. A page with no command says the state and
     * stops; a page with an invented one sends an operator to a service that
     * does not exist while the pipeline is stopped.
     */
    public static function restartCommand(string $queue): ?string
    {
        $service = self::forQueue($queue);

        return $service === null ? null : 'Restart-Service '.$service;
    }
}
