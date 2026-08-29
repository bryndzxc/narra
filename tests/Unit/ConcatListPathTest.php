<?php

namespace Tests\Unit;

use App\Services\Ffmpeg;
use PHPUnit\Framework\TestCase;

/**
 * A concat list is NOT a filter graph, and the two need different treatment
 * even though both carry Windows paths.
 *
 * CLAUDE.md says the filter-path escaping "applies to paths inside clips.txt
 * for the concat demuxer" as well. Measured against ffmpeg N-119331, that is
 * true only of the forward slashes: the escaped drive colon makes the demuxer
 * fail with "Impossible to open 'E\\:/...'". These tests pin the distinction so
 * nobody unifies the two methods on the strength of that sentence.
 */
class ConcatListPathTest extends TestCase
{
    public function test_a_concat_list_path_keeps_its_drive_colon_unescaped(): void
    {
        $this->assertSame(
            'E:/narra/storage/app/renders/clips/scene-001.mp4',
            Ffmpeg::concatListPath('E:/narra/storage/app/renders/clips/scene-001.mp4')
        );
    }

    public function test_a_concat_list_path_still_normalises_separators(): void
    {
        $this->assertSame(
            'E:/narra/storage/scene-001.mp4',
            Ffmpeg::concatListPath('E:\\narra\\storage\\scene-001.mp4')
        );
    }

    public function test_concat_and_filter_escaping_deliberately_differ(): void
    {
        $path = 'E:/narra/storage/subs.ass';

        $this->assertNotSame(
            Ffmpeg::concatListPath($path),
            Ffmpeg::escapeFilterPath($path, windows: true),
            'A concat list and a filter graph need different escaping. Do not unify these.'
        );
    }

    public function test_single_quotes_are_escaped_for_the_demuxer(): void
    {
        // The demuxer ends a quoted path at the first ', so a literal one has to
        // close, escape and reopen.
        $this->assertSame(
            "E:/narra/miller'\\''s-diner/scene-001.mp4",
            Ffmpeg::concatListPath("E:/narra/miller's-diner/scene-001.mp4")
        );
    }
}
