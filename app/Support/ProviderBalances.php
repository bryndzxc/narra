<?php

namespace App\Support;

use App\Contracts\ImageGenerator;
use App\Contracts\ProviderIdentity;
use App\Contracts\ScriptWriter;
use App\Contracts\SpeechSynthesizer;
use App\Contracts\Transcriber;
use App\Services\ElevenLabs\ElevenLabsSpeechSynthesizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Every provider the pipeline uses, and what each one can say about itself.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS ASKS THE CONTAINER AND NOT CONFIG
 * ---------------------------------------------------------------------------
 *
 * Because that distinction has already cost this project $8.12 of phantom
 * spend and a full afternoon. `PROVIDER_IMAGE_GENERATOR` was absent from
 * `.env`, the container resolved the fake, the cost projection read
 * `config('providers.image_generator')`, and every screen named a vendor that
 * had never been contacted while 186 flat fills were written to disk. Config
 * says what SHOULD run; only the resolved instance knows what DID.
 *
 * So each role is resolved and asked its own `providerName()`, exactly as
 * `ProviderIdentity` requires — and a role served by a stand-in reports itself
 * as a stand-in rather than reporting the vendor it was standing in for. A
 * dashboard that showed an ElevenLabs allowance while a fake was configured
 * would be the same defect wearing a nicer typeface.
 *
 * ---------------------------------------------------------------------------
 * WHY THE READ IS CACHED, AND WHY A FAILURE IS CACHED TOO
 * ---------------------------------------------------------------------------
 *
 * The dashboard is a page an operator leaves open. Asking a vendor's HTTP API
 * on every render — with a meta refresh on the render pages — would turn a
 * status panel into a rate-limit problem. Five minutes is short enough that a
 * balance is current for a decision and long enough that the page is cheap.
 *
 * A failed read is cached on the same terms. The alternative is retrying a
 * broken credential every few seconds and, worse, a panel that flickers
 * between "unreadable" and a stale success — and an intermittent alarm is one
 * that gets ignored.
 */
final class ProviderBalances
{
    private const CACHE_KEY = 'narra.provider-balances';

    private const TTL_SECONDS = 300;

    /**
     * @return array<int, ProviderBalance>
     */
    public static function all(): array
    {
        return [
            self::narration(),
            self::images(),
            self::text(),
            self::transcription(),
        ];
    }

    /** Drop the cached reads — used after a credential change. */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY.'.narration');
    }

    /**
     * The one provider here that publishes a real, decision-relevant balance.
     *
     * It is decision-relevant because the plan has NO OVERAGE: passing the
     * limit does not cost more, it stops generating part way through a story
     * and leaves the allowance spent. A cost estimate cannot ask this — on a
     * subscription the marginal answer is $0.00 either way — so "does this
     * fit" needs a number only the vendor has. See SpeechQuota.
     */
    private static function narration(): ProviderBalance
    {
        $speech = app(SpeechSynthesizer::class);
        $name = $speech->providerName();

        if ($speech->isSimulated()) {
            return ProviderBalance::simulated($name, 'narration');
        }

        /*
         * `quota()` is not on the SpeechSynthesizer contract — it is specific
         * to the one vendor that has the endpoint, and putting it on the
         * interface would force every future provider and every fake to invent
         * an answer to a question it cannot be asked. `NarrationPreflight`
         * reaches it through the same instanceof for the same reason.
         */
        if (! $speech instanceof ElevenLabsSpeechSynthesizer) {
            return ProviderBalance::noEndpoint(
                $name,
                'narration',
                self::spendToDate($name),
                'This provider publishes no allowance endpoint. The figure below is what has been '
                .'spent, taken from this app\'s own ledger — it is not a balance.',
            );
        }

        $reading = Cache::remember(
            self::CACHE_KEY.'.narration',
            self::TTL_SECONDS,
            static function () use ($speech): array {
                try {
                    $quota = $speech->quota();
                } catch (Throwable $e) {
                    // A vendor being down must not take the console with it.
                    // Reported as unreadable, which is a warning — never as a
                    // zero and never as a pass.
                    return ['readable' => false, 'why' => 'the balance call failed: '.$e->getMessage()];
                }

                if (! $quota->readable) {
                    return ['readable' => false, 'why' => (string) $quota->unreadableReason];
                }

                return [
                    'readable' => true,
                    'remaining' => (int) $quota->remaining(),
                    'limit' => (int) $quota->limit,
                    'tier' => $quota->tier,
                    'can_extend' => $quota->canExtend === true,
                ];
            },
        );

        if ($reading['readable'] !== true) {
            return ProviderBalance::unreadable(
                $name,
                'narration',
                'The remaining balance could not be read, which is not the same as it being fine — '
                .(string) $reading['why'],
            );
        }

        return ProviderBalance::balance(
            provider: $name,
            role: 'narration',
            remaining: (int) $reading['remaining'],
            limit: (int) $reading['limit'],
            tier: is_string($reading['tier'] ?? null) ? $reading['tier'] : null,
            unit: 'credits',
            headline: $reading['can_extend'] === true
                ? 'Overage is enabled: passing the limit bills rather than stopping.'
                : 'NO overage on this plan. Generation STOPS at the limit rather than billing past '
                  .'it, so a run that does not fit leaves a story half-narrated with the allowance '
                  .'already spent.',
        );
    }

    private static function images(): ProviderBalance
    {
        $images = app(ImageGenerator::class);
        $name = $images->providerName();

        if ($images->isSimulated()) {
            return ProviderBalance::simulated($name, 'stills');
        }

        return ProviderBalance::noEndpoint(
            $name,
            'stills',
            self::spendToDate($name),
            'The balance endpoint here is admin-scoped and refuses this app\'s key with a 403. The '
            .'figure below is spend from this app\'s own ledger — it is not a balance, and nothing '
            .'here knows what is left.',
        );
    }

    /**
     * The text role, and the one place here that cannot simply ask.
     *
     * `ScriptWriter` is the only provider contract in this app that does NOT
     * extend `ProviderIdentity` — every other one does, so every other one can
     * be asked its own name and whether it is a stand-in. Neither
     * `ClaudeScriptWriter` nor `FakeScriptWriter` implements those methods, so
     * calling them is a fatal error rather than a wrong answer.
     *
     * That gap is worth closing, and closing it is not this change's business:
     * it means widening a provider contract, which is a pipeline edit made
     * while doing a restyle. So this asks whether the resolved instance can
     * answer, and says plainly when it cannot.
     *
     * What it does NOT do is fall back to `config('providers.script_writer')`.
     * That is the exact substitution behind the $8.12 of phantom spend — config
     * says what SHOULD have run, and the whole reason `ProviderIdentity` exists
     * is that the two can disagree. An unknown name is reported as unknown.
     */
    private static function text(): ProviderBalance
    {
        $writer = app(ScriptWriter::class);

        if (! $writer instanceof ProviderIdentity) {
            return ProviderBalance::unnameable(
                class_basename($writer),
                'scripts, scenes, publish sheet',
                'The ScriptWriter contract does not extend ProviderIdentity, so the resolved instance '
                .'cannot be asked what it is or whether it bills. The class that actually resolved is '
                .'named above; it is deliberately NOT looked up in config, because config says what '
                .'should have run and only the instance knows what did.',
            );
        }

        $name = $writer->providerName();

        if ($writer->isSimulated()) {
            return ProviderBalance::simulated($name, 'scripts, scenes, publish sheet');
        }

        return ProviderBalance::noEndpoint(
            $name,
            'scripts, scenes, publish sheet',
            self::spendToDate($name),
            'This vendor publishes no balance endpoint at all — only spend exists. The figure below '
            .'is what this app has recorded spending, and it is not a balance.',
        );
    }

    private static function transcription(): ProviderBalance
    {
        $transcriber = app(Transcriber::class);
        $name = $transcriber->providerName();

        if ($transcriber->isSimulated()) {
            return ProviderBalance::simulated($name, 'word timings');
        }

        // WhisperX runs on this machine. There is no account to have a balance.
        return ProviderBalance::local($name, 'word timings');
    }

    /**
     * What this app has recorded paying a provider, ever.
     *
     * Simulated rows are excluded: a stand-in run writes a $0.00 row so the
     * ledger stays complete, and counting those calls toward a spend figure
     * would be the tagging mistake that let phantom spend read as a bill.
     */
    private static function spendToDate(string $provider): float
    {
        return (float) DB::table('cost_entries')
            ->where('provider', $provider)
            ->where('simulated', false)
            ->sum('usd_cost');
    }
}
