<?php

use App\Enums\RenderJobStatus;
use App\Enums\RenderStage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per pipeline stage run. The operator's only window into the queue.
 *
 * Horizon is not available here and never will be: it hard-requires `pcntl` and
 * `posix`, which do not exist in Windows PHP. Bus::batch() still works — it is
 * core Laravel — but its dashboard does not, so the Phase 1 batch page is built
 * from this table joined to `job_batches`. That page needs to answer three
 * questions at 200 scenes: how far along, how many failed, and which scenes.
 * Hence `batch_id` and `scene_id`, which are additions to the schema sketch,
 * made because the sketch's own batch-page requirement cannot be met without
 * them.
 *
 * `updated_at` is load-bearing. `queue:work --timeout` is enforced with a pcntl
 * alarm and is therefore silently ineffective on this platform: a hung FFmpeg
 * call occupies a worker forever, with no error and no recovery. A long-running
 * job touching this row periodically is the only signal that distinguishes
 * "still working" from "hung" — see RenderJob::isStale().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('render_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();

            // Set on the fan-out stages, so a failed batch names the scenes that
            // failed rather than just a count.
            $table->foreignId('scene_id')->nullable()->constrained()->nullOnDelete();

            $table->enum('stage', array_column(RenderStage::cases(), 'value'));

            $table->enum('status', array_column(RenderJobStatus::cases(), 'value'))
                ->default(RenderJobStatus::Queued->value);

            // Bus::batch() id, for joining to job_batches on the progress page.
            $table->string('batch_id', 36)->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->string('output_path')->nullable();
            $table->longText('log')->nullable();
            $table->text('error')->nullable();

            $table->timestamps();

            $table->index(['story_id', 'stage']);
            $table->index('batch_id');

            // The stale-heartbeat query: running jobs, oldest touch first.
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('render_jobs');
    }
};
