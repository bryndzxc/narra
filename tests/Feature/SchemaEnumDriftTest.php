<?php

namespace Tests\Feature;

use App\Console\Commands\SchemaEnumDrift;
use App\Enums\CostUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The enum-drift check, pointed at answers established without it.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS FILE CAN AND CANNOT ASSERT, WHICH IS THE POINT
 * ---------------------------------------------------------------------------
 *
 * The defect this command exists for is **structurally invisible to any test in
 * this suite**, and pretending otherwise would be worse than having no test.
 * `RefreshDatabase` rebuilds `narra_test` from the migrations, and eleven of
 * those migrations build their ENUM from `SomeEnum::cases()` — so the test
 * database's columns are generated from the enums under test and cannot
 * disagree with them. On the day production could not write `total_tokens`,
 * `narra_test` could, because it had been built minutes earlier.
 *
 * So there is deliberately NO test here asserting "every enum fits its column".
 * It would pass on every machine forever, including today, and would read as
 * coverage of the one thing nothing covers. `php artisan schema:enum-drift` on
 * the live database is that check, and it is a command precisely because it
 * needs the number we did not compute.
 *
 * What IS assertable, and is:
 *
 *   1. the COMPARISON, both directions, against known arrays;
 *   2. the READER, against the real schema — including that an unreadable
 *      column comes back as null and never as an empty list;
 *   3. the COVERAGE of the hand-written column list, which is a question about
 *      the database rather than about the enums, so a generated schema answers
 *      it honestly.
 *
 * Point 2 is not hypothetical. The first version of this probe read
 * `$row->column_type`, got null from MySQL on every row, and reported all
 * twelve columns as drifting — a detector that reports everything, which this
 * project has already argued is worse than one that reports nothing because the
 * severe category is the one that gets acted on.
 */
class SchemaEnumDriftTest extends TestCase
{
    use RefreshDatabase;

    // -- The comparison ------------------------------------------------------

    /** RED: the shipped defect, as arrays. */
    public function test_a_case_the_column_cannot_hold_is_reported_as_ahead(): void
    {
        $result = SchemaEnumDrift::compare(
            // The live `narra` column on the morning of 2026-09-05.
            ['input_tokens', 'output_tokens', 'characters', 'images', 'audio_seconds', 'requests'],
            ['total_tokens', 'output_tokens', 'characters', 'images', 'audio_seconds', 'requests'],
        );

        $this->assertSame(['total_tokens'], $result['ahead']);
        $this->assertSame(['input_tokens'], $result['behind']);
    }

    /**
     * GREEN, and as close to RED as it can be made.
     *
     * A rule that reported everything would satisfy the case above. This is one
     * value different and must be silent in both directions.
     */
    public function test_a_column_that_matches_its_enum_is_silent(): void
    {
        $values = ['total_tokens', 'output_tokens', 'characters', 'images', 'audio_seconds', 'requests'];

        $this->assertSame(
            ['ahead' => [], 'behind' => []],
            SchemaEnumDrift::compare($values, $values),
        );
    }

    /**
     * A retired case is reported and is NOT severe.
     *
     * `output_tokens` has no writer and 107 rows, and dropping it would make
     * historic rows fail to cast. If this direction were fatal the command would
     * be red about a column that is working correctly, and a severe category
     * full of findings nobody can act on stops being read.
     */
    public function test_a_retired_case_is_reported_without_being_ahead(): void
    {
        $result = SchemaEnumDrift::compare(['a', 'b'], ['a']);

        $this->assertSame([], $result['ahead']);
        $this->assertSame(['b'], $result['behind']);
    }

    // -- The reader ----------------------------------------------------------

    /** It reads the values a real ENUM column actually declares. */
    public function test_the_reader_returns_the_real_column_values(): void
    {
        $values = $this->read('cost_entries', 'unit');

        $this->assertIsArray($values);
        $this->assertContains(CostUnit::TotalTokens->value, $values);
        $this->assertContains(CostUnit::OutputTokens->value, $values);
    }

    /**
     * A column it cannot read comes back NULL, never an empty list.
     *
     * The difference is the whole behaviour of the command. `[]` would make
     * every enum case look like it was ahead of its column, which is the
     * all-twelve-columns-drifting output the first version produced — and the
     * report would have been loudest at the moment the instrument broke.
     */
    public function test_an_unreadable_column_is_null_rather_than_empty(): void
    {
        // A varchar, not an enum.
        $this->assertNull($this->read('stories', 'title'));

        // And a column that is not there at all.
        $this->assertNull($this->read('stories', 'no_such_column'));
        $this->assertNull($this->read('no_such_table', 'status'));
    }

    // -- The coverage of the hand-written list -------------------------------

    /**
     * Every ENUM column in the database is named in `SchemaEnumDrift::COLUMNS`.
     *
     * This one IS honest against a generated schema, because it asks a question
     * about the database's shape rather than about the enums' contents: adding
     * an enum column in a migration and forgetting to list it here is a real
     * mistake that a rebuilt `narra_test` reproduces exactly.
     *
     * The list is written by hand on purpose. Discovering it from model `$casts`
     * would derive the checklist from the same code the command distrusts, so a
     * removed cast would silently shrink the check — the defect one level up.
     */
    public function test_every_enum_column_in_the_database_is_covered(): void
    {
        $declared = array_map(
            static fn (array $entry): string => $entry[0].'.'.$entry[1],
            SchemaEnumDrift::COLUMNS,
        );

        $actual = array_map(
            static fn (object $row): string => $row->t.'.'.$row->c,
            DB::select(
                'select table_name as t, column_name as c from information_schema.columns '
                ."where table_schema = ? and data_type = 'enum'",
                [DB::getDatabaseName()],
            ),
        );

        sort($declared);
        sort($actual);

        $this->assertSame(
            [],
            array_values(array_diff($actual, $declared)),
            'The database has an enum column that schema:enum-drift does not check. Add it to '
            .'SchemaEnumDrift::COLUMNS in the same change that adds the migration — an unchecked '
            .'enum column is the one that silently truncates a billed call.',
        );

        $this->assertSame(
            [],
            array_values(array_diff($declared, $actual)),
            'schema:enum-drift names a column the database does not have, so that row can only '
            .'ever report UNREADABLE.',
        );
    }

    /** @return array<int, string>|null */
    private function read(string $table, string $column): ?array
    {
        $method = new ReflectionMethod(SchemaEnumDrift::class, 'columnValues');
        $method->setAccessible(true);

        /** @var array<int, string>|null */
        return $method->invoke(app(SchemaEnumDrift::class), $table, $column);
    }
}
