<?php

namespace App\Services\Fal;

use App\Contracts\ImageGenerator;
use App\Contracts\ReferenceImageGenerator;
use App\Enums\CostCategory;
use App\Enums\CostUnit;
use App\Enums\FailureKind;
use App\Exceptions\PipelineFailure;
use App\Models\Character;
use App\Models\Scene;
use App\Support\Providers\CharacterReferenceImage;
use App\Support\Providers\GeneratedImage;
use App\Support\Providers\ProviderUsage;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Seedream 5.0 Lite, called direct on fal.ai.
 *
 * Direct rather than through ElevenLabs, and that is the point of the class.
 * ElevenLabs resells this exact model but gates image generation behind a Pro
 * plan at $99/mo; fal sells it pay-as-you-go at a published $0.035 an image
 * with no floor. At a few videos a month the floor was the entire decision.
 *
 * Two endpoints because they take different inputs. A reference sheet has no
 * input image, so it goes to `text-to-image`. A scene still is conditioned on
 * the approved faces, so it goes to `edit` with `image_urls` — up to 10, which
 * is well past the two or three named characters a frame ever carries.
 *
 * **This provider cannot honour a seed.** Neither endpoint accepts one; the
 * response reports the seed it happened to use, and that is all. `supportsSeed`
 * says so rather than accepting a number and dropping it, because the whole
 * consistency mechanism is supposed to have two legs and on this provider it
 * has one. The reference image carries all of it.
 */
class FalSeedreamImageGenerator implements ImageGenerator, ReferenceImageGenerator
{
    public function generateReference(
        Character $character,
        string $prompt,
        ?int $seed = null,
    ): GeneratedImage {
        return $this->run(
            model: (string) config('providers.fal.text_to_image_model'),
            payload: [
                'prompt' => $prompt,
                'image_size' => [
                    'width' => (int) config('characters.width', 1024),
                    'height' => (int) config('characters.height', 1024),
                ],
            ],
            operation: 'generate_character_reference',
            // A sheet is generated AT Gate 2, before it has been crossed. See
            // CostCategory::Reference for why that is a sharpening of the money
            // line rather than a hole in it.
            category: CostCategory::Reference,
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
            throw new RuntimeException(sprintf(
                'Scene %d carries %d references and this model reads %d. The surplus would be '
                .'silently ignored and those characters generated from text.',
                $scene->sequence,
                count($references),
                $this->maxReferences(),
            ));
        }

        $size = [
            'width' => (int) config('render.video.width', 1920),
            'height' => (int) config('render.video.height', 1080),
        ];

        // Two endpoints, chosen by whether anyone is in the frame.
        //
        // `edit` is conditioned on the approved faces and is the path for any
        // scene with a cast. It REQUIRES an input image, so it cannot serve the
        // frames that legitimately have nobody in them — establishing shots,
        // objects, empty rooms, which are a quarter of a real story. Those go
        // to text-to-image, which is what it is for.
        //
        // This used to throw on an empty reference set, on the reasoning that a
        // faceless frame must never be generated from text. That reasoning is
        // right and the guard was in the wrong place: this class cannot tell
        // "nobody is in this frame" from "somebody is and their face was
        // dropped", because both arrive as an empty array. The distinction
        // needs the scene's cast, so the invariant lives in GenerateSceneImage,
        // which has it — and in ResolveSceneReferences, which returns every
        // face or throws, and never a partial set.
        if ($references === []) {
            return $this->run(
                model: (string) config('providers.fal.text_to_image_model'),
                payload: ['prompt' => $prompt, 'image_size' => $size],
                operation: 'generate_image',
                category: CostCategory::Asset,
            );
        }

        return $this->run(
            model: (string) config('providers.fal.edit_model'),
            payload: [
                'prompt' => $prompt,
                'image_urls' => array_map(
                    fn (CharacterReferenceImage $r): string => $this->dataUri($r),
                    array_values($references)
                ),
                'image_size' => $size,
            ],
            operation: 'generate_image',
            category: CostCategory::Asset,
        );
    }

    /**
     * fal has no persistent asset store worth using here.
     *
     * References travel as data URIs on each call. That is a real cost — a
     * megabyte of PNG re-uploaded per still — and it is accepted rather than
     * worked around because fal's storage API would need its own lifecycle
     * (upload, expiry, cleanup) to save bandwidth this project is not short of.
     * Null is the honest answer, and callers already treat it as normal.
     */
    public function storeReference(Character $character, string $bytes, string $mimeType): ?string
    {
        return null;
    }

    public function providerName(): string
    {
        return 'fal';
    }

    public function isSimulated(): bool
    {
        return false;
    }

    /**
     * The edit endpoint, not text-to-image: a scene still is conditioned on the
     * approved faces, so it is the model that runs 150-250 times per video.
     */
    public function modelName(): ?string
    {
        return (string) config('providers.fal.edit_model');
    }

    /** Text-to-image: a candidate face has no input image to edit. */
    public function referenceModelName(): ?string
    {
        return (string) config('providers.fal.text_to_image_model');
    }

    public function supportsSeed(): bool
    {
        return false;
    }

    public function maxReferences(): int
    {
        return (int) config('providers.fal.max_references', 10);
    }

    /**
     * One call, priced.
     *
     * @param  array<string, mixed>  $payload
     */
    private function run(string $model, array $payload, string $operation, CostCategory $category): GeneratedImage
    {
        $response = $this->client()->post($this->url($model), $payload + [
            'num_images' => 1,
            'enable_safety_checker' => true,
        ]);

        if ($response->failed()) {
            // A content-checker refusal and every other failure used to be one
            // indistinguishable RuntimeException, so the retry button offered to
            // resend a prompt that is refused deterministically. The body names
            // the refusal; the kind carries it to the page.
            throw new PipelineFailure(
                sprintf(
                    'fal refused the %s request against %s (HTTP %d): %s',
                    $operation,
                    $model,
                    $response->status(),
                    mb_substr($response->body(), 0, 500),
                ),
                $response->status() === 422 && str_contains($response->body(), 'content_policy_violation')
                    ? FailureKind::ContentRefused
                    : FailureKind::Unclassified,
            );
        }

        $image = $response->json('images.0');

        if (! is_array($image) || ! is_string($image['url'] ?? null)) {
            throw new RuntimeException(sprintf(
                'fal returned no image for %s. Body: %s',
                $operation,
                mb_substr($response->body(), 0, 500),
            ));
        }

        $bytes = $this->download($image['url']);

        // Read from the bytes, not from the response. fal returns `width` and
        // `height` as null and its `content_type` is not something to build a
        // filename on — this endpoint answers a 1024x1024 PNG request with a
        // 1920x1920 JPEG. Trusting either field wrote `.png` files full of
        // JPEG and stored 0x0 dimensions on every reference row.
        $probed = @getimagesizefromstring($bytes);

        if ($probed === false) {
            // A still that will not decode is the specific input FFmpeg does
            // not fail on: `-loop 1` loops forever emitting no frames. Catching
            // it here is the difference between an error and a stalled render.
            throw new RuntimeException(sprintf(
                'fal returned %d bytes for %s that do not decode as an image. FFmpeg does not fail '
                .'on an undecodable still — it loops forever emitting no frames — so this is '
                .'refused here rather than at render time.',
                strlen($bytes),
                $operation,
            ));
        }

        return new GeneratedImage(
            bytes: $bytes,
            mimeType: (string) ($probed['mime'] ?? 'image/png'),
            width: (int) $probed[0],
            height: (int) $probed[1],
            // Reported, never echoed from the request. The endpoints take no
            // seed input, so this is the number the model happened to use — a
            // record, not a control. Storing it as though it pinned anything
            // would make the consistency mechanism look like it had two legs.
            seed: is_int($response->json('seed')) ? $response->json('seed') : null,
            usage: new ProviderUsage(
                provider: 'fal',
                operation: $operation,
                category: $category,
                quantity: 1.0,
                unit: CostUnit::Images,
                usdCost: (float) config('providers.fal.usd_per_image'),
                // The endpoint that actually served this call, not the one
                // config names today. Both are recorded: `model` is the field
                // the ledger reads, `detail` keeps the full response context.
                model: $model,
                detail: [
                    'model' => $model,
                    'references' => count($payload['image_urls'] ?? []),
                    'reported_seed' => $response->json('seed'),
                    'seed_was_controllable' => false,
                ],
            ),
        );
    }

    /**
     * A reference as an inline data URI.
     *
     * fal accepts a hosted URL or a data URI in `image_urls`. Data URI, because
     * the alternative is publishing a character's face to a URL this app would
     * then have to secure and expire — and every other disk in this project is
     * deliberately not public.
     */
    private function dataUri(CharacterReferenceImage $reference): string
    {
        return sprintf('data:%s;base64,%s', $reference->mimeType, $reference->base64());
    }

    private function download(string $url): string
    {
        $response = Http::timeout((int) config('providers.fal.timeout_seconds', 180))
            ->connectTimeout(15)
            ->retry((int) config('providers.fal.max_retries', 2), 1000, throw: false)
            ->get($url);

        if ($response->failed() || $response->body() === '') {
            throw new RuntimeException(sprintf(
                'Downloading the finished image failed (HTTP %d). The generation was billed regardless.',
                $response->status(),
            ));
        }

        return $response->body();
    }

    private function client(): PendingRequest
    {
        $key = (string) config('providers.fal.api_key');

        if (trim($key) === '') {
            throw new RuntimeException(
                'FAL_API_KEY is not set, so the Seedream provider cannot be built. Set it in .env, '
                .'or leave PROVIDER_REFERENCE_IMAGE_GENERATOR=fake to run without billing.'
            );
        }

        return Http::withHeaders(['Authorization' => 'Key '.$key])
            // The only real timeout on this platform. `queue:work --timeout` is
            // enforced with a pcntl alarm and pcntl does not exist in Windows
            // PHP, so without this a stalled request holds a worker forever.
            ->timeout((int) config('providers.fal.timeout_seconds', 180))
            ->connectTimeout(15)
            ->acceptJson();
    }

    private function url(string $model): string
    {
        return rtrim((string) config('providers.fal.base_url'), '/').'/'.ltrim($model, '/');
    }
}
