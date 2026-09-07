@props(['queue', 'command' => null])

{{--
    The fix for one queue: the command that restarts its worker, or a plain
    statement that there is none.

    ONE COMPONENT because the alternative was one literal per surface, which is
    how the two copies this replaces came to disagree — the dashboard's invented
    a service name for any queue not in its map and the panel's did not.

    `Restart-Service`, not `nssm start`, for two reasons either of which settles
    it: `nssm` is not on PATH on this machine — the installer resolves it
    through Get-Command, a vendored copy and a download, and this printed a bare
    `nssm`, so the command failed at the moment somebody was trying to unstick a
    stopped pipeline — and `start` is the wrong verb for a service that is
    RUNNING and taking nothing, which is a state this panel now names.

    See App\Support\WorkerServices, which owns the mapping.
--}}
@if ($command === null)
    {{--
        No command, on purpose, and said rather than guessed.

        The old fallback was 'Narra'.ucfirst($queue), which is right for the
        three queues that are mapped and confidently wrong for any other — a
        pasteable instruction naming a service that does not exist. The state
        above is still reported in full; only the fix is withheld, because there
        is not one to give.
    --}}
    <div class="small mt-2">
        No worker service is mapped to &ldquo;{{ $queue }}&rdquo;, so there is no restart command to
        offer here. See <code>docs/queue-workers.md</code> for the command to run it by hand.
    </div>
@else
    <div class="mono small mt-2">{{ $command }}</div>
    <div class="small muted">Needs an elevated PowerShell prompt, the same as installing them did.</div>
@endif
