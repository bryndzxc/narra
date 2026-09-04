<?php

namespace App\Exceptions;

use App\Support\RunFingerprint;
use RuntimeException;

/**
 * A spend that was stopped before anything was queued.
 *
 * Its own type because the caller has to be able to tell it apart from a
 * failure: nothing has been dispatched, nothing has been billed, and no scene
 * has been touched, so the operator's next move is to fix the machine and press
 * the button again rather than to work out what state a half-run left behind.
 *
 * **Refusals, not warnings, and that distinction is the point.** Every defence
 * that failed in this project's four false-success incidents was downstream of
 * the fault, and the ones that were upstream were advisory — a printed line
 * about restarting workers that an operator reads once and then stops seeing.
 * A preflight that can be scrolled past is a preflight that is not there.
 */
class DispatchRefusedException extends RuntimeException
{
    /**
     * A worker on the target queue booted before the current code or config.
     *
     * The check runs at dispatch, in the process that has fresh code, because
     * that is the only process in the system that can be trusted to know what
     * "current" is. Asking a stale worker whether it is stale is asking the
     * fault to report itself.
     *
     * @param  array<int, array{pid: int, booted_at: float, digest: string, fingerprint: array<string, scalar|null>}>  $stale
     * @param  array<string, scalar|null>  $current
     */
    public static function staleWorkers(string $queue, array $stale, array $current): self
    {
        $lines = [];

        foreach ($stale as $worker) {
            $diff = RunFingerprint::diff($current, $worker['fingerprint']);

            $lines[] = sprintf(
                "  pid %d, running since %s, fingerprint %s\n%s",
                $worker['pid'],
                date('Y-m-d H:i:s', (int) $worker['booted_at']),
                $worker['digest'],
                $diff === []
                    ? '    (no field differs — the code marker alone moved)'
                    : implode("\n", array_map(
                        fn (string $key, array $pair): string => sprintf(
                            '    %-18s this process: %-24s that worker: %s',
                            $key,
                            json_encode($pair['expected']),
                            json_encode($pair['actual']),
                        ),
                        array_keys($diff),
                        $diff,
                    )),
            );
        }

        // "ask for", not "pay for": this is thrown for the render queue too,
        // where nothing bills. A refusal that misdescribes the stakes is one an
        // operator learns to discount on the queue where it is wrong.
        return new self(sprintf(
            'REFUSED — %d worker(s) on the "%s" queue booted before the current code or config, so '
            ."they would not do what you are about to ask for.\n\n%s\n\nThis process's fingerprint is "
            ."%s.\n\nA `queue:work` process bootstraps Laravel ONCE and holds its config and its code "
            .'in memory for its whole life, so a .env edit, a migration or a deploy does not reach a '
            ."running one.\nThat is not a theoretical risk here: a worker booted before the narration "
            .'speed setting, the narration_speed column and the pace guard existed synthesised 117 '
            .'scenes at speed 1.0 instead of 0.9, recorded no speed provenance for any of them, and '
            .'cost 15,308 credits. Every guard that should have caught it lived downstream of the '
            ."worker that was stale.\n\nNothing has been queued and nothing has been billed. To "
            ."proceed:\n\n    php artisan queue:restart\n\nthen CONFIRM the processes have actually "
            .'exited — `queue:restart` asks them to stop after the current job, it does not kill them '
            .'— start them again, and re-run this.',
            count($stale),
            $queue,
            implode("\n\n", $lines),
            RunFingerprint::digest($current),
        ));
    }

    /**
     * The word-timing stage cannot run, and it is cheaper to say so now.
     *
     * Alignment is free, which is exactly why this refusal is worth making: the
     * stage that fails costs nothing, but it runs SECOND, after the paid
     * narration it aligns. Discovering the aligner is broken at that point means
     * the money is already gone. 181 scenes learned this one job at a time.
     *
     * @param  array{interpreter: string, error: ?string}  $probe
     */
    public static function alignerUnavailable(array $probe, int $pending): self
    {
        return new self(sprintf(
            'REFUSED — the word-timing stage cannot run, and it would run AFTER the narration you are '
            ."about to pay for.\n\n  WHISPERX_PYTHON : %s\n\n  %s\n\n"
            .'%d scene(s) in this run need word timings. Alignment itself is free, which is precisely '
            .'why this is worth stopping for: the free stage is the one that breaks, and it breaks '
            .'second — after the paid stage it depends on. A batch that failed this way left 181 '
            ."scenes with narration bought and no timings to show for it.\n\n"
            .'Fix the interpreter and re-run, or pass --no-aligner-check to generate narration now and '
            .'align later.',
            $probe['interpreter'],
            str_replace("\n", "\n  ", (string) $probe['error']),
            $pending,
        ));
    }

    /**
     * The approved faces were drawn in a different look than the one that would
     * be generated now.
     *
     * A refusal rather than a warning, and the arithmetic is why: every still a
     * character appears in goes through the `edit` endpoint conditioned on that
     * face, so a stale sheet does not produce one wrong image — it drags all
     * 30-90 of that character's stills back toward a style the rest of the
     * video is not in. On story 9 that is $6.51 of image spend on a video in
     * two looks, and the operator cannot see it until the render.
     *
     * @param  array<int, string>  $names
     */
    public static function referencesInAnotherStyle(array $names, string $current): self
    {
        return new self(sprintf(
            'Refusing to generate: %d character(s) have a reference sheet drawn in a different art '
            ."style than the one configured now.\n\n  %s\n\nThe current style fingerprint is %s. "
            .'Every still these characters appear in is conditioned on their reference face, so '
            .'generating now would produce one video in two looks — and the stills would still be '
            ."billed.\n\nRegenerate their sheets on the characters page first (that is a spend, and "
            .'it is a small one next to the stills). If the mismatch is deliberate, pass '
            .'--no-style-check, which gives up exactly this check and nothing else.',
            count($names),
            implode("\n  ", $names),
            $current,
        ));
    }
}
