<?php

namespace Tests\Feature;

use App\Enums\StoryStatus;
use App\Models\Act;
use App\Models\CostEntry;
use App\Models\Scene;
use App\Models\Story;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The reasons the suite runs on MySQL rather than SQLite.
 *
 * Each test here is a behaviour that differs between the two engines and that
 * this schema actually depends on. Under SQLite every one of them passes for
 * the wrong reason — enums become plain text, decimals become floats, foreign
 * keys are advisory unless a pragma is set — so the suite would stay green
 * while production broke. Running them against MySQL 8 is what makes the rest
 * of the suite's greenness mean something.
 */
class MysqlBehaviourTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_suite_is_actually_running_on_mysql(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());

        // And never against the development database.
        $this->assertSame('narra_test', DB::connection()->getDatabaseName());
    }

    public function test_money_keeps_four_decimal_places_and_does_not_drift(): void
    {
        $story = Story::factory()->paidAssetsUnlocked()->create();

        // Three hundredths of a cent each. In a float column this is where the
        // error would start; in decimal(10,4) it is exact.
        CostEntry::factory()->for($story)->count(3)->create(['usd_cost' => 0.0003]);

        $story->refresh();

        $this->assertSame('0.0009', $story->total_cost_usd);
        $this->assertSame('0.0009', (string) $story->costEntries()->sum('usd_cost'));

        // 250 images at 4 cents — a realistic video, summed in the database.
        $bulk = Story::factory()->paidAssetsUnlocked()->create();
        CostEntry::factory()->for($bulk)->count(250)->image(0.0400)->create();

        $this->assertSame('10.0000', $bulk->refresh()->total_cost_usd);
    }

    public function test_a_fraction_below_the_stored_scale_does_not_silently_vanish_into_a_float(): void
    {
        $story = Story::factory()->paidAssetsUnlocked()->create();

        // decimal(10,4) rounds at the fourth place. The point is that it rounds
        // predictably, at a known place, rather than accumulating binary error.
        CostEntry::factory()->for($story)->create(['usd_cost' => 0.00005]);

        $this->assertSame('0.0001', (string) $story->costEntries()->sum('usd_cost'));
    }

    public function test_an_enum_column_rejects_a_value_that_is_not_in_it(): void
    {
        // Bypassing the model on purpose: the question is what the COLUMN does
        // when something writes to it directly - a raw query, a future import
        // script, a hand-run UPDATE. MySQL refuses; SQLite would store it and
        // the PHP enum cast would then blow up somewhere far away.
        $this->expectException(QueryException::class);

        DB::table('stories')->insert([
            'title' => 'Direct write',
            'status' => 'definitely_not_a_status',
            'format' => 'single',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_status_column_holds_every_case_the_enum_defines(): void
    {
        // The other half of the previous test: the column must accept all of
        // them. A case added to the PHP enum without a migration would fail
        // here rather than at the first real transition.
        $story = Story::factory()->create();

        foreach (StoryStatus::cases() as $status) {
            DB::table('stories')->where('id', $story->id)->update(['status' => $status->value]);

            $this->assertSame($status, $story->fresh()->status);
        }
    }

    public function test_foreign_keys_are_enforced_without_being_asked(): void
    {
        $story = Story::factory()->create();

        $this->expectException(QueryException::class);

        Scene::factory()->create([
            'story_id' => $story->id,
            'act_id' => 999999,
        ]);
    }

    public function test_a_referenced_row_cannot_be_deleted_out_from_under_a_null_on_delete_constraint(): void
    {
        // The publish sheet's thumbnail_scene_id is nullOnDelete rather than
        // cascade, so losing the recommended still must not take the sheet with
        // it. That distinction only exists if the constraint is real.
        $story = Story::factory()->rendered()->create();
        $act = Act::factory()->for($story)->create();
        $scene = Scene::factory()->forAct($act)->create();

        $metadata = $story->youtubeMetadata()->create(['thumbnail_scene_id' => $scene->id]);

        $scene->delete();

        $this->assertNull($metadata->fresh()->thumbnail_scene_id);
    }

    public function test_utf8mb4_survives_the_round_trip(): void
    {
        // Titles and thumbnail text carry em dashes, curly quotes and the
        // occasional emoji. utf8 (three-byte) would truncate at the emoji.
        $title = 'The Lighthouse Keeper — "she never came back" 🕯️';

        $story = Story::factory()->create(['title' => $title]);

        $this->assertSame($title, $story->fresh()->title);
    }
}
