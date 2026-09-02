<?php

namespace App\Services\ElevenLabs;

use App\Contracts\ImageGenerator;
use App\Contracts\ReferenceImageGenerator;
use App\Enums\CostCategory;
use App\Enums\CostUnit;
use App\Models\Character;
use App\Models\Scene;
use App\Support\Providers\CharacterReferenceImage;
use App\Support\Providers\GeneratedImage;
use App\Support\Providers\ProviderUsage;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Images from ElevenLabs' Image & Video API.
 *
 * ElevenLabs does not train image models. This endpoint fronts other people's —
 * Seedream, Gemini ("Nano Banana"), GPT-Image — behind one key and one request
 * shape. It was chosen over Leonardo, and the reason was not price.
 *
 * **Refs per call decided it.** Leonardo's Character Reference is a ControlNet
 * occupying ONE preprocessor slot per generation (id 133 on SDXL, 397 on
 * Phoenix). Several `controlnets[]` entries are several KINDS of guidance —
 * style, edge, depth, pose — not several people. A scene with two named
 * characters can therefore pin one face and must generate the other from text,
 * which is precisely the drift a reference exists to prevent. Leonardo's own
 * documentation also declines to promise a likeness for the one it does pin:
 * "not intended as a face swap feature and does not guarantee a perfect
 * replica." This API takes an `images[]` array — up to 14 on Gemini 3.1 Flash —
 * and our scenes routinely have two or three people in frame.
 *
 * Two things this class has to work around:
 *
 *  1. **Generation is asynchronous.** POST returns `{id, status: "pending"}`;
 *     the id is then polled. The poll loop is bounded in wall-clock time,
 *     because on this platform nothing else would bound it: Symfony Process
 *     timeouts do not apply to HTTP, and `queue:work --timeout` is silently
 *     ineffective without pcntl. An unbounded poll is a worker occupied forever
 *     with no error and no recovery.
 *
 *  2. **The response does not say what it cost.** The documented completed
 *     payload is id, status, content_url, content_mime_type — no credits, no
 *     dollars. So cost is computed from the declared rate card in
 *     config/providers.php, like every other provider here, and the operator is
 *     shown that figure before generating so it can be reconciled against the
 *     real usage page rather than trusted.
 *
 * NOT YET EXERCISED AGAINST A LIVE ACCOUNT. The request and response shapes
 * below are written from the published documentation; no key exists in this
 * environment to confirm them. The reference-entry shape in referenceEntry() is
 * the least-documented part and is deliberately isolated in one method so a
 * correction is one edit. Make the first real call for a single character
 * before turning a whole cast loose.
 */
class ElevenLabsImageGenerator implements ImageGenerator, ReferenceImageGenerator
{
    public function generateReference(
        Character $character,
        string $prompt,
        ?int $seed = null,
    ): GeneratedImage {
        return $this->create(
            prompt: $prompt,
            operation: 'generate_character_reference',
            // A sheet is generated AT Gate 2, before it has been crossed. The
            // guard that permits that is keyed on this category and on nothing
            // else. See CostCategory::Reference.
            category: CostCategory::Reference,
            seed: $seed,
            references: [],
            options: [
                'aspect_ratio' => '1:1',
                'resolution' => '1K',
            ],
        );
    }

    /**
     * @param  array<int, CharacterReferenceImage>  $references
     */
    public function generate(
        Scene $scene,
        string $prompt,
        ?int $seed = null,
        array $references = [],
    ): GeneratedImage {
        if (count($references) > $this->maxReferences()) {
            // Refused rather than truncated. Sending fifteen references to a
            // model that reads fourteen does not fail — it draws the fifteenth
            // character from text, produces a plausible picture, and hides the
            // one defect this whole feature exists to prevent.
            throw new RuntimeException(sprintf(
                'Scene %d carries %d character references and %s accepts %d. Sending them anyway '
                .'would silently generate the surplus characters from text, which is the drift '
                .'references exist to prevent. Split the frame or reduce its cast.',
                $scene->sequence,
                count($references),
                (string) config('providers.elevenlabs.image_model'),
                $this->maxReferences(),
            ));
        }

        return $this->create(
            prompt: $prompt,
            operation: 'generate_image',
            category: CostCategory::Asset,
            seed: $seed,
            references: $references,
            options: [
                'aspect_ratio' => '16:9',
                'resolution' => '2K',
            ],
        );
    }

    /**
     * Upload a reference once and cite it by id thereafter.
     *
     * A character in 60 scenes is 60 image calls that each need their face. At
     * roughly a megabyte a reference that is 60 uploads of identical bytes,
     * every render, for nothing. The assets API stores it until deleted.
     */
    public function storeReference(Character $character, string $bytes, string $mimeType): ?string
    {
        $response = $this->client()
            ->attach('file', $bytes, $this->assetName($character, $mimeType), ['Content-Type' => $mimeType])
            ->post($this->url('/assets'), [
                'name' => $this->assetName($character, $mimeType),
            ]);

        if ($response->failed()) {
            // Null, not an exception. An upload that fails costs the caller a
            // fallback to inline bytes, which is slower and works. Turning a
            // performance optimisation into a hard failure would take the whole
            // scene stage down over a cache miss.
            report(new RuntimeException(sprintf(
                'ElevenLabs asset upload failed for character "%s" (HTTP %d): %s. Falling back to '
                .'inline reference bytes.',
                $character->name,
                $response->status(),
                mb_substr($response->body(), 0, 300),
            )));

            return null;
        }

        $id = $response->json('asset_id') ?? $response->json('id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function providerName(): string
    {
        return 'elevenlabs';
    }

    public function isSimulated(): bool
    {
        return false;
    }

    public function modelName(): ?string
    {
        return (string) config('providers.elevenlabs.image_model');
    }

    /** One model fronts both here, unlike fal's two endpoints. */
    public function referenceModelName(): ?string
    {
        return (string) config('providers.elevenlabs.image_model');
    }

    public function supportsSeed(): bool
    {
        // Honestly false for the default model. Gemini image models take no
        // meaningful seed, and a provider that accepted one and ignored it
        // would let the consistency mechanism look like it had two legs when it
        // has one. The reference image is the leg that carries the weight here;
        // saying so is better than discovering it at scene 90.
        return false;
    }

    public function maxReferences(): int
    {
        return (int) config('providers.elevenlabs.max_references', 14);
    }

    /**
     * Submit, poll, download.
     *
     * @param  array<int, CharacterReferenceImage>  $references
     * @param  array<string, mixed>  $options
     */
    private function create(
        string $prompt,
        string $operation,
        CostCategory $category,
        ?int $seed,
        array $references,
        array $options,
    ): GeneratedImage {
        $payload = array_merge($options, [
            'model_id' => (string) config('providers.elevenlabs.image_model'),
            'prompt' => $prompt,
        ]);

        if ($references !== []) {
            $payload['images'] = array_map(
                fn (CharacterReferenceImage $r): array => $this->referenceEntry($r),
                array_values($references)
            );
        }

        if ($seed !== null && $this->supportsSeed()) {
            $payload['seed'] = $seed;
        }

        $submitted = $this->client()->post($this->url('/flows/image'), $payload);

        if ($submitted->failed()) {
            throw new RuntimeException(sprintf(
                'ElevenLabs refused the %s request (HTTP %d): %s',
                $operation,
                $submitted->status(),
                mb_substr($submitted->body(), 0, 500),
            ));
        }

        $id = (string) $submitted->json('id');

        if ($id === '') {
            throw new RuntimeException(
                'ElevenLabs accepted the generation but returned no id, so there is nothing to '
                .'poll. Body: '.mb_substr($submitted->body(), 0, 500)
            );
        }

        $completed = $this->poll($id, $operation);

        $bytes = $this->download((string) $completed['content_url']);

        return new GeneratedImage(
            bytes: $bytes,
            mimeType: (string) ($completed['content_mime_type'] ?? 'image/png'),
            width: (int) ($completed['width'] ?? 0),
            height: (int) ($completed['height'] ?? 0),
            // Echoed only if it was honoured. Reporting a seed the provider
            // ignored would put a number in the database that means nothing and
            // reads as a guarantee.
            seed: $this->supportsSeed() ? $seed : null,
            usage: new ProviderUsage(
                provider: 'elevenlabs',
                operation: $operation,
                category: $category,
                quantity: 1.0,
                unit: CostUnit::Images,
                usdCost: $this->priceOfOneImage(),
                detail: [
                    'model' => (string) config('providers.elevenlabs.image_model'),
                    'generation_id' => $id,
                    'references' => count($references),
                    // Carried so a row can be re-priced later if the declared
                    // rate turns out wrong. A computed dollar figure whose
                    // inputs are lost cannot be audited.
                    'credits_charged' => (float) config('providers.elevenlabs.pricing.credits_per_image'),
                ],
            ),
        );
    }

    /**
     * How one reference image is expressed in the request.
     *
     * The least-documented corner of this integration and therefore the one
     * place it is most likely to be wrong. The published guide names three
     * interchangeable reference types — an uploaded `asset` cited by id, a
     * previous `generation` cited by id, and `inline_base64` for one-time use
     * capped at 25 MB decoded — and shows them through the Python SDK's typed
     * wrappers rather than as raw JSON. What is below is the shape those
     * wrappers imply.
     *
     * Kept in one method so that if the wire format differs, the fix is one
     * edit rather than a hunt through a request builder.
     *
     * @return array<string, string>
     */
    private function referenceEntry(CharacterReferenceImage $reference): array
    {
        if ($reference->providerReference !== null) {
            return [
                'type' => 'asset',
                'asset_id' => $reference->providerReference,
            ];
        }

        return [
            'type' => 'inline_base64',
            'mime_type' => $reference->mimeType,
            'data' => $reference->base64(),
        ];
    }

    /**
     * Wait for the generation, but not forever.
     *
     * @return array<string, mixed>
     */
    private function poll(string $id, string $operation): array
    {
        $interval = (float) config('providers.elevenlabs.poll_interval_seconds', 2.0);
        $deadline = microtime(true) + (float) config('providers.elevenlabs.poll_timeout_seconds', 300);

        while (true) {
            $response = $this->client()->get($this->url("/flows/image/{$id}"));

            if ($response->failed()) {
                throw new RuntimeException(sprintf(
                    'Polling ElevenLabs generation %s failed (HTTP %d): %s',
                    $id,
                    $response->status(),
                    mb_substr($response->body(), 0, 300),
                ));
            }

            $body = (array) $response->json();
            $status = (string) ($body['status'] ?? '');

            if ($status === 'completed') {
                if (! is_string($body['content_url'] ?? null) || $body['content_url'] === '') {
                    throw new RuntimeException(
                        "ElevenLabs reported generation {$id} complete but gave no content_url."
                    );
                }

                return $body;
            }

            if ($status === 'failed') {
                throw new RuntimeException(sprintf(
                    'ElevenLabs %s generation %s failed: %s',
                    $operation,
                    $id,
                    (string) ($body['error'] ?? 'no reason given'),
                ));
            }

            if (microtime(true) >= $deadline) {
                // A bounded give-up rather than a stall. The generation may
                // still complete on their side and may still be billed; that is
                // said out loud rather than left for the ledger to imply.
                throw new RuntimeException(sprintf(
                    'ElevenLabs generation %s was still "%s" after %ds and has been abandoned. It '
                    .'may still complete and may still be billed — check the usage page before '
                    .'retrying, since a retry is a second charge.',
                    $id,
                    $status === '' ? 'unknown' : $status,
                    (int) config('providers.elevenlabs.poll_timeout_seconds', 300),
                ));
            }

            usleep((int) round($interval * 1000000));
        }
    }

    /** The finished image lives on a signed URL, not behind the API key. */
    private function download(string $url): string
    {
        $response = Http::timeout((int) config('providers.elevenlabs.timeout_seconds', 120))
            ->connectTimeout(15)
            ->retry((int) config('providers.elevenlabs.max_retries', 2), 1000, throw: false)
            ->get($url);

        if ($response->failed() || $response->body() === '') {
            throw new RuntimeException(sprintf(
                'Downloading the finished image failed (HTTP %d). The generation was billed '
                .'regardless.',
                $response->status(),
            ));
        }

        return $response->body();
    }

    /**
     * What one image costs, from the declared rate card.
     *
     * Computed rather than read off the response because the response does not
     * carry it — see the class docblock. Two separate numbers rather than one
     * dollar figure so a plan change and a model change are different edits.
     */
    private function priceOfOneImage(): float
    {
        return (float) config('providers.elevenlabs.pricing.credits_per_image')
            * (float) config('providers.elevenlabs.pricing.usd_per_credit');
    }

    private function client(): PendingRequest
    {
        $key = (string) config('providers.elevenlabs.api_key');

        if (trim($key) === '') {
            throw new RuntimeException(
                'ELEVENLABS_API_KEY is not set. Set it in .env, or leave '
                .'PROVIDER_IMAGE_GENERATOR=fake to run the pipeline without billing.'
            );
        }

        return Http::withHeaders([
            // Both, deliberately. The Image & Video guide documents a Bearer
            // token; the rest of the ElevenLabs API has always authenticated
            // with `xi-api-key`. Sending one and being wrong is a 401 on the
            // first paid call of a new integration.
            'Authorization' => 'Bearer '.$key,
            'xi-api-key' => $key,
        ])
            // An explicit timeout, and it is the only real one on this
            // platform. `queue:work --timeout` is enforced with a pcntl alarm
            // and pcntl does not exist in Windows PHP, so without this a
            // stalled request occupies a worker indefinitely with no error.
            ->timeout((int) config('providers.elevenlabs.timeout_seconds', 120))
            ->connectTimeout(15)
            ->acceptJson();
    }

    private function url(string $path): string
    {
        return rtrim((string) config('providers.elevenlabs.base_url'), '/').'/'.ltrim($path, '/');
    }

    /**
     * A slugged filename. Character names reach a remote filesystem here and
     * are treated as hostile input, same as everywhere else in this app.
     */
    private function assetName(Character $character, string $mimeType): string
    {
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($character->name));
        $slug = trim($slug, '-');

        return sprintf(
            'ref-%d-%s.%s',
            $character->id,
            mb_substr($slug === '' ? 'character' : $slug, 0, 40),
            $mimeType === 'image/jpeg' ? 'jpg' : 'png',
        );
    }
}
