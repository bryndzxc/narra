<?php

namespace App\Services\Claude;

use Anthropic\Messages\RawContentBlockDeltaEvent;
use Anthropic\Messages\RawMessageDeltaEvent;
use Anthropic\Messages\RawMessageStartEvent;
use Anthropic\Messages\TextDelta;
use Traversable;

/**
 * A completed message, assembled from a stream of events.
 *
 * The PHP SDK's `createStream()` returns an SSE iterator with no
 * `finalMessage()` helper — unlike the Python and TypeScript SDKs, which do
 * have one. So the assembly lives here, once, rather than being open-coded at
 * every call site.
 *
 * Streaming is not optional for this workload. An act on a thinking model runs
 * for minutes, which is long enough to outrun a non-streaming HTTP timeout, and
 * this platform has no queue-level timeout to fall back on — `queue:work
 * --timeout` needs pcntl and Windows PHP has none.
 *
 * The token counts arrive split across two events and both halves are needed to
 * price the call:
 *
 *   message_start  — input tokens, cache reads, cache writes
 *   message_delta  — final output tokens, plus stop_reason
 *
 * Reading only one of them is the easy mistake: taking output tokens from
 * message_start gives zero, and taking input tokens from message_delta misses
 * the cache split that makes a five-act story cheap.
 */
final class StreamedMessage
{
    private function __construct(
        public readonly string $text,
        public readonly ?string $stopReason,
        public readonly ?string $stopReasonCategory,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $cacheWriteTokens,
        public readonly int $cacheReadTokens,
    ) {}

    /**
     * @param  Traversable<mixed>  $stream
     */
    public static function consume(Traversable $stream): self
    {
        $text = '';
        $stopReason = null;
        $category = null;
        $input = 0;
        $output = 0;
        $cacheWrite = 0;
        $cacheRead = 0;

        foreach ($stream as $event) {
            if ($event instanceof RawMessageStartEvent) {
                $usage = $event->message->usage;

                $input = (int) $usage->inputTokens;
                $output = (int) $usage->outputTokens;
                $cacheWrite = (int) ($usage->cacheCreationInputTokens ?? 0);
                $cacheRead = (int) ($usage->cacheReadInputTokens ?? 0);

                continue;
            }

            if ($event instanceof RawContentBlockDeltaEvent) {
                // Text only. Thinking deltas are billed as output tokens and
                // counted above, but the raw chain of thought is never part of
                // the answer and appending it here would put it in the script.
                if ($event->delta instanceof TextDelta) {
                    $text .= $event->delta->text;
                }

                continue;
            }

            if ($event instanceof RawMessageDeltaEvent) {
                $stopReason = $event->delta->stopReason;
                $category = $event->delta->stopDetails?->category;

                // The authoritative output count: message_start reports zero
                // for it because nothing has been generated yet.
                $output = (int) $event->usage->outputTokens;

                // These are usually only on message_start, but the delta
                // carries them when the server revises them (a cache write that
                // turned into a read). Nulls are ignored rather than zeroing
                // what message_start already reported.
                $input = $event->usage->inputTokens ?? $input;
                $cacheWrite = $event->usage->cacheCreationInputTokens ?? $cacheWrite;
                $cacheRead = $event->usage->cacheReadInputTokens ?? $cacheRead;
            }
        }

        return new self(
            text: $text,
            stopReason: $stopReason,
            stopReasonCategory: $category,
            inputTokens: $input,
            outputTokens: $output,
            cacheWriteTokens: (int) $cacheWrite,
            cacheReadTokens: (int) $cacheRead,
        );
    }

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens + $this->cacheWriteTokens + $this->cacheReadTokens;
    }
}
