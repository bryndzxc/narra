<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * This worker is running different code or config than the process that queued
 * the job.
 *
 * Its own type for the same reason NarrationPaceException has one: SceneAssetJob
 * cancels the whole batch on this and only this. The batch policy everywhere
 * else — three failures out of 186 flag for retry and do not fail the video — is
 * right because those failures are independent. This one is not. A stale worker
 * is wrong about every job it will ever pick up, and the damage is not the
 * failures, it is the successes: if a second, current worker shares the queue,
 * some scenes are generated the new way and some the old, and the story ends up
 * internally inconsistent in a way no per-scene check can see.
 *
 * That is exactly what 117 scenes at speed 1.0 beside 69 at 0.9 looked like.
 */
class StaleWorkerException extends RuntimeException {}
