<?php

namespace Tests\Feature;

use App\Actions\EstimateSceneAssets;
use App\Contracts\SpeechSynthesizer;
use App\Enums\CostUnit;
use App\Models\Scene;
use App\Models\Story;
use App\Services\ElevenLabs\ElevenLabsSpeechSynthesizer;
use App\Support\AssetRateCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * One text, one price, whoever is asking.
 *
 * A credit is not a character. `eleven_multilingual_v2` on this account bills
 * 0.5 credits per character, and that one multiplier was applied in three
 * places by three pieces of code that never compared notes — so the same
 * narration had three different prices depending on which screen you read:
 *
 *   the ESTIMATE summed `mb_strlen` and quoted it as the bill. Story 21's
 *   narration: 42,017 against a real 21,193. Over by exactly 2.000x, which
 *   reported the monthly allowance as having 6,948 credits left when it had
 *   27,953 and shaped a session's spending decisions on a scarcity that was
 *   not there.
 *
 *   the LEDGER took the vendor's `character-cost` header — which has ALREADY
 *   had the multiplier applied — and applied it again. `detail.credits` came
 *   out at 46 against a quantity of 92, and `usd_cost` at half the truth.
 *
 *   the RATE CARD was right the whole time and disagreeing with both.
 *
 * The quantity column is what made it solvable: it is the header, and it
 * reconciled to the vendor's own usage counter exactly. An internal number
 * agreeing with an internal number proves nothing; that one did not come from
 * us.
 *
 * So what is asserted here is mostly not "this figure is correct" but "these
 * two figures are the same figure", because the defect was never an arithmetic
 * slip — it was one quantity computed in two places.
 */
class NarrationBillingTest extends TestCase
{
    use RefreshDatabase;

    private const HALF_PRICE_MODEL = 'eleven_flash_v2_5';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('assets');
    }

    /**
     * Bind the real synthesizer, priced at the half-credit model.
     *
     * Explicit rather than a config flick: AssetRateCard deliberately asks the
     * RESOLVED INSTANCE rather than reading config, which is what makes it
     * impossible for the sheet to quote a provider that is not the one running.
     * A test that only moved the config key would be testing the config key.
     */
    private function useHalfPriceElevenLabs(): void
    {
        config()->set('providers.speech_synthesizer', 'elevenlabs');
        config()->set('providers.elevenlabs.tts.model', self::HALF_PRICE_MODEL);

        $this->app->bind(
            SpeechSynthesizer::class,
            fn (): ElevenLabsSpeechSynthesizer => app(ElevenLabsSpeechSynthesizer::class),
        );
    }

    // -- One text, one price -------------------------------------------------

    public function test_the_estimate_and_the_ledger_price_a_text_identically(): void
    {
        // THE regression test, and the shape of it matters more than the
        // numbers: it compares the two routes to a price rather than checking
        // either against a constant. A constant can be updated to match a bug;
        // an identity between the estimate's route and the recorder's cannot.
        config()->set('providers.elevenlabs.tts.model', self::HALF_PRICE_MODEL);

        $synthesizer = app(ElevenLabsSpeechSynthesizer::class);
        $text = str_repeat('The receipts were in my name. ', 40);

        $usdPerCredit = (float) config('providers.elevenlabs.tts.pricing.usd_per_credit');

        // What the recorder will bill: credits x the credit price.
        $ledger = $synthesizer->creditsFor($text) * $usdPerCredit;

        // What the estimate quotes: characters x the per-1k character rate.
        $estimate = mb_strlen($text) / 1000 * $synthesizer->usdPerThousandCharacters();

        $this->assertEqualsWithDelta($ledger, $estimate, 0.000001);
    }

    public function test_a_credit_is_not_a_character_on_a_half_price_model(): void
    {
        config()->set('providers.elevenlabs.tts.model', self::HALF_PRICE_MODEL);

        $synthesizer = app(ElevenLabsSpeechSynthesizer::class);
        $text = str_repeat('a', 400);

        $this->assertSame(0.5, $synthesizer->creditsPerCharacter());
        $this->assertSame(200.0, $synthesizer->creditsFor($text));
    }

    // -- The estimate quotes what will be charged ----------------------------

    public function test_the_estimate_quotes_billable_units_not_characters(): void
    {
        $this->useHalfPriceElevenLabs();

        $story = $this->storyNeedingNarration('Eight hundred characters of it.');

        $estimate = app(EstimateSceneAssets::class)->handle($story);

        $characters = $estimate->speechCharacters;

        $this->assertGreaterThan(0, $characters);

        // Half, because the model charges half a credit per character. The old
        // behaviour quoted `$characters` here and was over by exactly 2x.
        $this->assertSame($characters / 2, $estimate->speechBillableUnits);
        $this->assertFalse($estimate->speechBillsInCharacters());
    }

    public function test_the_billable_figure_reaches_the_operator(): void
    {
        // Computed and shown nowhere is the defect one level up, and this
        // project has closed it enough times to test for it.
        $this->useHalfPriceElevenLabs();

        $story = $this->storyNeedingNarration('Some narration for the sheet.');

        $summary = implode(' ', app(EstimateSceneAssets::class)->handle($story)->summary());

        $this->assertStringContainsString('billable units', $summary);
    }

    public function test_a_provider_that_bills_per_character_says_one_number_not_two(): void
    {
        // The counterpart. "42,017 characters, 42,017 billable" is noise, and a
        // sheet that prints noise gets skimmed.
        $this->useHalfPriceElevenLabs();
        config()->set('providers.elevenlabs.tts.model', 'eleven_v3');

        $story = $this->storyNeedingNarration('One credit per character here.');

        $estimate = app(EstimateSceneAssets::class)->handle($story);

        $this->assertTrue($estimate->speechBillsInCharacters());
        $this->assertStringNotContainsString(
            'billable units',
            implode(' ', $estimate->summary()),
        );
    }

    public function test_the_rate_card_asks_the_provider_rather_than_counting_characters(): void
    {
        $this->useHalfPriceElevenLabs();

        $text = str_repeat('b', 300);

        $this->assertSame(
            app(ElevenLabsSpeechSynthesizer::class)->creditsFor($text),
            app(AssetRateCard::class)->speechBillableUnitsFor($text),
        );
    }

    // -- The header is already the bill --------------------------------------

    public function test_the_vendors_header_is_the_credit_count_and_is_not_multiplied_again(): void
    {
        // Measured on story 21, every row: 183 characters sent, 92 in the
        // header. The header has had the model's multiplier applied already,
        // and applying it a second time halved both `detail.credits` and
        // `usd_cost` against a quantity that was right.
        config()->set('providers.elevenlabs.tts.model', self::HALF_PRICE_MODEL);

        $this->fakeSpeech(['character-cost' => '92']);

        $usage = app(ElevenLabsSpeechSynthesizer::class)
            ->synthesize($this->scene(), str_repeat('c', 183), 'voice-abc')
            ->usage;

        $this->assertSame(92.0, $usage->quantity);
        $this->assertSame(CostUnit::Characters, $usage->unit);

        // 92, not 46. The header IS the credits.
        $this->assertSame(92.0, $usage->detail['credits']);
        $this->assertSame('vendor header', $usage->detail['credits_from']);

        $this->assertEqualsWithDelta(
            92 * (float) config('providers.elevenlabs.tts.pricing.usd_per_credit'),
            $usage->usdCost,
            0.000001,
        );
    }

    public function test_our_own_count_still_gets_the_multiplier_when_no_header_comes_back(): void
    {
        // The other branch, and the reason this is not simply "stop
        // multiplying". Our number is a character count and does need the
        // multiplier; the vendor's does not. Which one produced the figure is
        // recorded, so a reconciliation knows what it is looking at.
        config()->set('providers.elevenlabs.tts.model', self::HALF_PRICE_MODEL);

        $this->fakeSpeech();

        $usage = app(ElevenLabsSpeechSynthesizer::class)
            ->synthesize($this->scene(), str_repeat('d', 200), 'voice-abc')
            ->usage;

        $this->assertSame(100.0, $usage->quantity);
        $this->assertSame(100.0, $usage->detail['credits']);
        $this->assertSame(200, $usage->detail['characters_sent']);
        $this->assertNull($usage->detail['characters_charged_header']);
        $this->assertSame('characters sent x multiplier', $usage->detail['credits_from']);
    }

    public function test_a_recorded_call_and_its_estimate_agree_on_the_bill(): void
    {
        // End to end, across the seam the whole class is about: quote a text,
        // synthesize the same text, and compare the two USD figures.
        $this->useHalfPriceElevenLabs();

        // No trailing space, deliberately. The synthesizer trims what it sends
        // and the estimate quotes the stored text, so a trailing space is half
        // a credit of disagreement per scene — ~0.6% over a 270-scene story,
        // in the over-quoting direction, and not what this test is about. It is
        // noted rather than hidden: it is a real if tiny second source of
        // truth, and the note is here so the next person sees it as known.
        $text = trim(str_repeat('The receipts were in my name. ', 12));

        $story = $this->storyNeedingNarration($text);

        $estimate = app(EstimateSceneAssets::class)->handle($story);

        // No header, so the recorder uses its own count — the same count the
        // estimate used, which is what makes them comparable at all.
        $this->fakeSpeech();

        $usage = app(ElevenLabsSpeechSynthesizer::class)
            ->synthesize($story->scenes()->first(), $text, 'voice-abc')
            ->usage;

        $this->assertEqualsWithDelta($estimate->speechBillableUnits, $usage->quantity, 0.001);
        $this->assertEqualsWithDelta($estimate->usdNarration(), $usage->usdCost, 0.0001);
    }

    // -- Fixtures ------------------------------------------------------------

    private function storyNeedingNarration(string $text): Story
    {
        $story = Story::factory()->paidAssetsUnlocked()->create();

        Scene::factory()->for($story)->create([
            'sequence' => 1,
            'narration_text' => $text,
            'image_path' => null,
        ]);

        return $story->refresh();
    }

    private function scene(): Scene
    {
        return Scene::factory()->for(Story::factory()->paidAssetsUnlocked())->create(['sequence' => 1]);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function fakeSpeech(array $headers = []): void
    {
        // A short but real PCM body: the synthesizer probes what came back, so
        // an empty response is a different test from this one.
        Http::fake([
            '*/text-to-speech/*' => Http::response(str_repeat("\x00\x00", 2400), 200, $headers),
        ]);
    }
}
