<?php

namespace App\Enums;

/**
 * State of one `render_jobs` row.
 *
 * `Running` carries an obligation: a long job must keep touching its row's
 * `updated_at` as a heartbeat. On Windows there is no `pcntl`, so
 * `queue:work --timeout` is silently ineffective and a hung FFmpeg or a stalled
 * HTTP call occupies a worker forever with no error. A row that has been
 * `Running` with a stale heartbeat is the only signal that this has happened,
 * which is why RenderJob::isStale() exists.
 */
enum RenderJobStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /** Superseded by a re-run, or abandoned when the operator went back a gate. */
    case Cancelled = 'cancelled';

    public function isFinished(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Cancelled], true);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
