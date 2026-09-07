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

    /**
     * The story has no narrator, and narration is part of this dispatch.
     *
     * ---------------------------------------------------------------------
     * THE INSTANCE, AND WHY IT IS A REFUSAL RATHER THAN A WARNING
     * ---------------------------------------------------------------------
     *
     * Story 23. `voice_id` was null, the operator dispatched, and the missing
     * column became **257 identical failure rows** — one per scene, each one a
     * worker picking up a job, loading a story, and re-discovering a fact that
     * was knowable from one column before anything was queued. Nothing was
     * billed, because the refusal in `GenerateSceneNarration` sits above the
     * `synthesize()` call and that guard is correct. What it cost instead was a
     * batch that could never fire its completion callback, on a story with 256
     * stills already bought.
     *
     * It is a POSITIVE reading in the sense this codebase uses for the
     * stale/unknown split: the column is empty, the stage provably cannot
     * complete, and no reading of the situation makes it fine. Same standing as
     * a stale reference sheet — and unlike an unmeasured pace pair, which
     * claims nothing and therefore warns.
     */
    public static function storyHasNoVoice(string $slug, int $scenes): self
    {
        return new self(sprintf(
            'REFUSED — this story has no narrator, and %d scene(s) in this run need narration.'
            ."\n\n  stories.voice_id is null for %s\n\n"
            .'A channel keeps one consistent narrator across every video, which is why the voice is '
            ."stored on the story rather than read from config when a scene is synthesized.\n\n"
            ."  php artisan voices:list                              # the account's real voices\n"
            ."  php artisan voices:list --set=%s --voice=<voice_id>  # assign one\n\n"
            .'This used to be discovered one scene at a time. The per-job guard is right and it is '
            .'downstream, so a null voice became one failure row per scene instead of one refusal at '
            .'the button — nothing billed, and a batch that could not complete.',
            $scenes,
            $slug,
            $slug,
        ));
    }

    /**
     * The voice on the story is not a voice on the account.
     *
     * Worse than a null voice rather than better, which is the whole reason it
     * is a separate check. A null voice fails loudly and for free. An id that is
     * merely wrong reaches the vendor and comes back 422 — per scene, three
     * times each under `--tries=3` — and reads like a provider outage rather
     * than a typo.
     *
     * The account's own list is the authority, which makes this one of the few
     * checks here reading a number the app did not compute. `voices:list --set`
     * validates against the same list, so a voice assigned through the supported
     * path cannot trip this; what it catches is a config default, a fork, or a
     * hand-edited row.
     *
     * @param  array<int, array{id: string, name: string, locale: string}>  $voices
     */
    public static function voiceNotOnAccount(string $voiceId, string $provider, array $voices): self
    {
        $listed = array_map(
            static fn (array $v): string => sprintf('%-24s %s', $v['id'], $v['name']),
            array_slice($voices, 0, 12),
        );

        return new self(sprintf(
            'REFUSED — "%s" is not a voice on the %s account.'
            ."\n\n"
            .'A wrong voice id is worse than a missing one. A missing one refuses here for free; this '
            .'one would reach the vendor and come back 422 on every scene, three times each under '
            ."--tries=3, reading like an outage rather than a typo.\n\nOn the account%s:\n\n  %s\n\n"
            ."  php artisan voices:list --set=<story> --voice=<voice_id>\n\n"
            .'If this came from `providers.default_voice_id`, fix it there too — a default pointing at '
            .'a voice that does not exist lays the same trap on every new story.',
            $voiceId,
            $provider,
            count($voices) > 12 ? sprintf(' (%d total, first 12)', count($voices)) : '',
            implode("\n  ", $listed),
        ));
    }

    /**
     * The narration in this run does not fit the remaining allowance.
     *
     * **The distinctive risk of this vendor, and the one a cost estimate
     * structurally cannot see.** On a plan without overage ElevenLabs does not
     * bill past the allowance — it STOPS generating. So the failure is not an
     * unexpected charge; it is a half-narrated story with the allowance spent
     * either way: scenes 1-170 done, 171 onward refused, and nothing left to
     * retry them with.
     *
     * A cost estimate answers "what will this cost", and on a subscription the
     * marginal answer is $0.00 whichever way it goes. Only the vendor's own
     * counter can answer "does this fit", which is why it is worth a network
     * call at the button.
     */
    public static function narrationWillNotFit(
        float $credits,
        int $remaining,
        int $scenes,
        string $summary,
    ): self {
        return new self(sprintf(
            'REFUSED — this run needs %s narration credits and %s remain. Short by %s.'
            ."\n\n  %s\n\n"
            .'%d scene(s) need narration. On a plan without overage, generation STOPS at the limit '
            .'rather than billing past it — so dispatching now spends what is left and still leaves '
            ."the story unfinished, with nothing to retry the remainder with.\n\n"
            .'Buy a top-up, upgrade the plan, or switch ELEVENLABS_TTS_MODEL to a flash/turbo model at '
            .'half the credit cost. That last one is a real quality decision on long-form narration '
            .'and it is yours, not this guard\'s.',
            number_format($credits),
            number_format((float) $remaining),
            number_format(ceil($credits - $remaining)),
            $summary,
            $scenes,
        ));
    }
}
