<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every PHP enum against the column that has to hold it, read from the LIVE
 * database.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS CANNOT BE A TEST
 * ---------------------------------------------------------------------------
 *
 * This is the one check in the project that a test structurally cannot perform,
 * and understanding why is the entire reason the command exists.
 *
 * Eleven columns build their MySQL ENUM from the PHP enum at migration time:
 *
 *     $table->enum('unit', array_column(CostUnit::cases(), 'value'));
 *
 * `RefreshDatabase` re-runs the migrations, so the test database's column is
 * generated FROM THE ENUM UNDER TEST. It cannot disagree with it. A test
 * asserting "the column can hold every case" would pass on every machine, on
 * every branch, forever, including the day production could not write a row —
 * which is exactly what happened: 943 tests green while `narra` and `narra_test`
 * had different columns.
 *
 *     narra       enum('input_tokens','output_tokens',...)   <- built Aug 28
 *     narra_test  enum('total_tokens','output_tokens',...)   <- built just now
 *
 * That is rule 3 in CLAUDE.md — keep one number that we did not compute. The
 * live column is that number. Everything else in this area is derived from the
 * enum and therefore agrees with it by construction.
 *
 * ---------------------------------------------------------------------------
 * WHAT IT REPORTS, AND WHICH DIRECTION IS SEVERE
 * ---------------------------------------------------------------------------
 *
 *   CODE AHEAD    the enum has a case the column cannot hold. **This is the
 *                 severe one and the only thing that sets a non-zero exit.**
 *                 Writing it does not raise an error on its own — MySQL emits
 *                 warning 1265 and truncates to '' — so under a non-strict
 *                 connection it would corrupt the row silently rather than
 *                 failing loudly. Here it throws, which is the good outcome, and
 *                 it still cost a billed call with no ledger row.
 *
 *   COLUMN AHEAD  the column holds a value the enum dropped. Reported, never
 *                 fatal: it is how a retired case looks, it breaks nothing on
 *                 write, and rows may still legitimately carry it — 107 rows
 *                 hold `output_tokens`, which has no writer and must stay.
 *
 * The severity split is deliberate. A severe category that fills with findings
 * nobody can act on is a severe category that stops being read, which this
 * project has already argued about an alarm band and about `class-audit`.
 *
 * ---------------------------------------------------------------------------
 * WHEN TO RUN IT
 * ---------------------------------------------------------------------------
 *
 * After adding or removing an enum case, and at the end of a phase beside the
 * four static audits. It needs a live connection, which is why it is a command
 * rather than a `tools/` script — and it reads `information_schema` rather than
 * the migration files, because the migration is the thing being distrusted.
 */
class SchemaEnumDrift extends Command
{
    protected $signature = 'schema:enum-drift {--json : machine-readable output}';

    protected $description = 'Compare every PHP enum against the live column that stores it';

    /**
     * Column to enum, written out by hand.
     *
     * DELIBERATELY NOT DISCOVERED. Scanning models for `$casts` would derive
     * this list from the same code the check distrusts, which is the defect one
     * level up — the check would go quiet about a column whose cast somebody
     * removed. A new enum column is added here in the same change that adds it
     * to a migration, and `test_every_enum_column_is_covered` fails if the
     * database grows one this list does not name.
     *
     * @var array<int, array{0: string, 1: string, 2: class-string}>
     */
    public const COLUMNS = [
        ['stories', 'format', \App\Enums\StoryFormat::class],
        ['stories', 'status', \App\Enums\StoryStatus::class],
        ['scenes', 'motion_preset', \App\Enums\MotionPreset::class],
        ['scenes', 'status', \App\Enums\SceneStatus::class],
        ['audio_tracks', 'status', \App\Enums\AssetStatus::class],
        ['scene_audio', 'status', \App\Enums\AssetStatus::class],
        ['render_jobs', 'stage', \App\Enums\RenderStage::class],
        ['render_jobs', 'status', \App\Enums\RenderJobStatus::class],
        ['cost_entries', 'unit', \App\Enums\CostUnit::class],
        ['cost_entries', 'category', \App\Enums\CostCategory::class],
        ['youtube_metadata', 'status', \App\Enums\MetadataStatus::class],
        ['character_references', 'status', \App\Enums\AssetStatus::class],
    ];

    public function handle(): int
    {
        $findings = [];
        $rows = [];

        foreach (self::COLUMNS as [$table, $column, $enum]) {
            $values = $this->columnValues($table, $column);

            if ($values === null) {
                // An unreadable column is a finding, never a pass. The same rule
                // as an unreadable queue depth being shown as unreadable rather
                // than as zero: a check that could not run did not run.
                $rows[] = [$table, $column, 'UNREADABLE', '', ''];
                $findings[] = sprintf('%s.%s could not be read from information_schema.', $table, $column);

                continue;
            }

            ['ahead' => $ahead, 'behind' => $behind] = self::compare(
                $values,
                array_column($enum::cases(), 'value'),
            );

            $rows[] = [
                $table,
                $column,
                $ahead !== [] ? 'CODE AHEAD' : ($behind !== [] ? 'column ahead' : 'in step'),
                $ahead === [] ? '' : implode(', ', $ahead),
                $behind === [] ? '' : implode(', ', $behind),
            ];

            if ($ahead !== []) {
                $findings[] = sprintf(
                    '%s.%s cannot hold: %s. %s has the case(s); the column does not. '
                    .'Writing one truncates the row — add a migration with a LITERAL value list.',
                    $table,
                    $column,
                    implode(', ', $ahead),
                    class_basename($enum),
                );
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(['rows' => $rows, 'findings' => $findings], JSON_PRETTY_PRINT));

            return $findings === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->newLine();
        $this->line(sprintf('  Live database: <options=bold>%s</>', DB::getDatabaseName()));
        $this->newLine();

        $this->table(
            ['table', 'column', 'state', 'code ahead of column', 'column ahead of code'],
            $rows,
        );

        if ($findings === []) {
            $this->newLine();
            $this->info('  Every enum case fits the column that stores it.');

            return self::SUCCESS;
        }

        $this->newLine();

        foreach ($findings as $finding) {
            $this->error('  '.$finding);
        }

        $this->newLine();
        $this->line('  A truncated write is a billed call with no ledger row. See');
        $this->line('  database/migrations/2026_09_05_140000_add_total_tokens_to_cost_entries.php.');

        return self::FAILURE;
    }

    /**
     * The two directions a column and its enum can disagree in.
     *
     * A pure function so it can be pointed at a known answer. The command's own
     * live reading cannot be — it is the thing that must not be mocked — so the
     * halves are separated: this is drilled with arrays, and `columnValues()` is
     * checked against the real schema.
     *
     * @param  array<int, string>  $inColumn
     * @param  array<int, string>  $inCode
     * @return array{ahead: array<int, string>, behind: array<int, string>}
     */
    public static function compare(array $inColumn, array $inCode): array
    {
        return [
            // The enum can produce it and the column cannot store it. Severe.
            'ahead' => array_values(array_diff($inCode, $inColumn)),
            // The column allows it and the enum no longer produces it. Reported
            // and never fatal — that is what a retired case looks like.
            'behind' => array_values(array_diff($inColumn, $inCode)),
        ];
    }

    /**
     * The values a live ENUM column actually accepts.
     *
     * @return array<int, string>|null null when the column cannot be read, which
     *                                 is reported rather than treated as empty
     */
    private function columnValues(string $table, string $column): ?array
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return null;
        }

        $row = DB::selectOne(
            'select column_type as ct from information_schema.columns '
            .'where table_schema = ? and table_name = ? and column_name = ?',
            [DB::getDatabaseName(), $table, $column],
        );

        // Aliased to `ct` on purpose. MySQL returns this column's name in a case
        // that varies by server configuration, and the first version of this
        // probe read `$row->column_type`, got null on every row, and reported
        // all twelve columns as drifting — a detector that reports everything,
        // which is worth less than one that reports nothing because it gets
        // acted on. The alias makes the property name ours.
        $type = $row->ct ?? null;

        if (! is_string($type) || ! str_starts_with(strtolower($type), 'enum(')) {
            return null;
        }

        preg_match_all("/'((?:[^']|'')*)'/", $type, $matches);

        return array_map(
            static fn (string $value): string => str_replace("''", "'", $value),
            $matches[1],
        );
    }
}
