<?php

namespace App\Exceptions;

use App\Enums\FailureKind;
use RuntimeException;

/**
 * A queue worker disagrees with the process that dispatched its job about how
 * the asset should be made — provider, fingerprint or code — and refused
 * before generating or billing anything.
 *
 * Classified as StaleWorker with the queue as its fact, so the page can name
 * the restart command for THAT queue's service at display time. The message
 * holds the disagreement and nothing about how to restart: that procedure has
 * changed once already (queue:restart and a manual start, then
 * Restart-Service) and a stored sentence would have gone on naming the old one.
 */
class StaleWorkerException extends RuntimeException implements ClassifiedFailure
{
    public function __construct(string $message, private readonly ?string $queue = null)
    {
        parent::__construct($message);
    }

    public function failureKind(): FailureKind
    {
        return FailureKind::StaleWorker;
    }

    public function failureFacts(): array
    {
        return $this->queue === null ? [] : ['queue' => $this->queue];
    }
}
