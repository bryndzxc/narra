<?php

namespace App\Contracts;

/**
 * Who actually ran, asked of the object that ran.
 *
 * This exists because of a specific failure, and the shape of it is the reason
 * the answer cannot come from config.
 *
 * `PROVIDER_IMAGE_GENERATOR` was absent from `.env`, so the container resolved
 * `FakeImageGenerator`. Meanwhile the cost projection read
 * `config('providers.image_generator')` and the character sheet wrote
 * `config('providers.elevenlabs.image_model')` into its rows. Config and
 * container are two different sources of truth for one question, and the moment
 * they disagreed the app reported a provider that had never been called: 186
 * flat-fill PNGs were generated, $8.12 was written to the ledger, and every
 * screen and row involved named a vendor that was never contacted.
 *
 * A rate card that asks config is asking what SHOULD have run. Only the
 * instance knows what DID. So every provider names itself, and the name on the
 * confirmation screen, the rate used to quote it, the model recorded against
 * the artefact and the provider written to `cost_entries` all come from the
 * same object.
 *
 * `isSimulated()` is separate from the name rather than derived from it
 * (`=== 'fake'`), because the property that matters is "this produced no real
 * artefact and contacted nobody", and a future stub, replay or record/playback
 * provider would have that property under a different name. It is what the
 * money guard keys on.
 */
interface ProviderIdentity
{
    /**
     * The vendor this instance actually calls: `fal`, `elevenlabs`, `fake`.
     *
     * Matches what the implementation writes into `ProviderUsage::$provider`,
     * and must — that field is what lands in `cost_entries.provider`, and a
     * quote that named one vendor while the ledger named another is the exact
     * confusion this interface removes.
     */
    public function providerName(): string;

    /**
     * Whether this produces a stand-in rather than a real artefact.
     *
     * True means: nothing left this machine, nothing was billed, and the output
     * is a placeholder — a flat-fill PNG, a silent WAV. A simulated provider
     * MUST report a zero cost, and RecordProviderCost refuses the row if it
     * does not. "Realistic-looking" fake costs were a deliberate feature once,
     * to make a fixture run produce a plausible breakdown; they made
     * `cost_entries` unable to answer the one question it exists for.
     */
    public function isSimulated(): bool;

    /**
     * The specific model doing the work, where the vendor fronts several.
     *
     * Null where the concept does not apply. Recorded against the artefact so
     * that "which model drew this face" survives a config change — the reason
     * 36 reference rows claimed `gemini-3.1-flash-image` while Seedream had
     * drawn every one of them.
     */
    public function modelName(): ?string;
}
