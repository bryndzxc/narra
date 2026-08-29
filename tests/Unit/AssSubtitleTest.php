<?php

namespace Tests\Unit;

use App\Actions\GenerateAssSubtitles;
use App\Support\AssStyle;
use InvalidArgumentException;
use Tests\TestCase;

class AssSubtitleTest extends TestCase
{
    public function test_colour_conversion_reverses_rgb_into_bgr(): void
    {
        // The classic silent bug: ASS is &HAABBGGRR, so red and blue swap.
        // #FF0000 (red) must not come out as &H00FF0000 (which is blue).
        $this->assertSame('&H000000FF', AssStyle::colour('#FF0000'));
        $this->assertSame('&H0000FF00', AssStyle::colour('#00FF00'));
        $this->assertSame('&H00FF0000', AssStyle::colour('#0000FF'));
        $this->assertSame('&H0066D6FF', AssStyle::colour('#FFD666'));
        $this->assertSame('&H00FFFFFF', AssStyle::colour('#FFFFFF'));
    }

    public function test_colour_accepts_alpha_and_rejects_nonsense(): void
    {
        $this->assertSame('&H80000000', AssStyle::colour('#000000', 128));

        $this->expectException(InvalidArgumentException::class);
        AssStyle::colour('#FFF');
    }

    public function test_the_style_block_puts_the_highlight_in_the_primary_field(): void
    {
        // ASS sweeps FROM SecondaryColour TO PrimaryColour, so the highlight
        // belongs in PrimaryColour. Swapping these inverts the whole effect and
        // nothing errors.
        $style = new AssStyle([
            'font' => 'Arial', 'font_size' => 64, 'bold' => true,
            'primary_colour' => '#FFFFFF', 'highlight_colour' => '#FFD666',
            'outline_colour' => '#000000', 'back_colour' => '#000000',
            'outline' => 4, 'shadow' => 2, 'alignment' => 2,
            'margin_l' => 120, 'margin_r' => 120, 'margin_v' => 90,
        ], 1920, 1080);

        $fields = explode(',', trim(explode("\n", $style->stylesBlock())[2]));

        $this->assertSame('&H0066D6FF', $fields[3], 'PrimaryColour must carry the highlight.');
        $this->assertSame('&H00FFFFFF', $fields[4], 'SecondaryColour must carry the resting colour.');
    }

    public function test_timestamps_render_in_ass_format(): void
    {
        $this->assertSame('0:00:00.00', AssStyle::timestamp(0));
        $this->assertSame('0:00:14.97', AssStyle::timestamp(1497));
        $this->assertSame('0:02:42.10', AssStyle::timestamp(16210));
        $this->assertSame('1:00:00.00', AssStyle::timestamp(360000));
    }

    public function test_word_boundaries_are_monotonic_and_stay_inside_the_scene(): void
    {
        $generator = app(GenerateAssSubtitles::class);

        $words = [
            ['word' => 'one', 'start_ms' => 300, 'end_ms' => 800],
            ['word' => 'two', 'start_ms' => 800, 'end_ms' => 1310],
            ['word' => 'three', 'start_ms' => 1310, 'end_ms' => 1900],
        ];

        [$starts, $ends] = $generator->absoluteBoundaries($words, 660030, 1497, 1870, 44100);

        // A word's end is the next word's start, so chunks meet with no gap.
        $this->assertSame($ends[0], $starts[1]);
        $this->assertSame($ends[1], $starts[2]);

        foreach ($starts as $i => $start) {
            $this->assertGreaterThanOrEqual(1497, $start);
            $this->assertLessThanOrEqual(1870, $ends[$i]);
            $this->assertGreaterThanOrEqual($start, $ends[$i]);
        }
    }

    public function test_sub_centisecond_words_never_time_backwards(): void
    {
        $generator = app(GenerateAssSubtitles::class);

        // Whisper can emit words a few milliseconds long. Rounded boundaries
        // must still be non-decreasing or libass rejects the line.
        $words = [];
        for ($i = 0; $i < 12; $i++) {
            $words[] = ['word' => 'w'.$i, 'start_ms' => $i * 3, 'end_ms' => ($i + 1) * 3];
        }

        [$starts, $ends] = $generator->absoluteBoundaries($words, 0, 0, 40, 44100);

        for ($i = 1; $i < count($words); $i++) {
            $this->assertGreaterThanOrEqual($starts[$i - 1], $starts[$i]);
            $this->assertGreaterThanOrEqual($ends[$i - 1], $ends[$i]);
            $this->assertGreaterThanOrEqual(0, $ends[$i] - $starts[$i]);
        }
    }

    public function test_lead_in_becomes_a_bare_spacer_not_a_stretched_first_word(): void
    {
        $generator = app(GenerateAssSubtitles::class);
        $path = sys_get_temp_dir().'/narra-spacer-test.ass';

        $scenes = [[
            'sequence' => 1,
            'narration_text' => 'alpha beta',
            'words' => [
                ['word' => 'alpha', 'start_ms' => 300, 'end_ms' => 1000],
                ['word' => 'beta', 'start_ms' => 1000, 'end_ms' => 1600],
            ],
        ]];

        // 2000ms of padded audio: 300ms lead-in, 1300ms speech, 400ms trailing.
        $generator->handle($scenes, [1 => ['offset_samples' => 0, 'padded_samples' => 88200]], $path, 'test');

        $dialogue = '';
        foreach (file($path) as $row) {
            if (str_starts_with($row, 'Dialogue:')) {
                $dialogue = $row;
            }
        }

        // Opens with an empty {\k30} — the highlight must not start sweeping
        // through 300ms of silence.
        $this->assertStringContainsString('{\k30}{\k70}alpha', $dialogue);
        // Closes with an empty {\k40} for the trailing silence.
        $this->assertStringContainsString('beta{\k40}', $dialogue);

        @unlink($path);
    }

    public function test_words_carrying_ass_markup_are_refused_not_mangled(): void
    {
        $generator = app(GenerateAssSubtitles::class);

        $scenes = [[
            'sequence' => 1,
            'narration_text' => 'broken {\b1} text',
            'words' => [
                ['word' => 'broken', 'start_ms' => 0, 'end_ms' => 500],
                ['word' => '{\b1}', 'start_ms' => 500, 'end_ms' => 900],
                ['word' => 'text', 'start_ms' => 900, 'end_ms' => 1400],
            ],
        ]];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ASS markup');

        $generator->handle(
            $scenes,
            [1 => ['offset_samples' => 0, 'padded_samples' => 66150]],
            sys_get_temp_dir().'/narra-markup-test.ass',
            'test'
        );
    }

    /**
     * The 12-scene fixture ends on a whole centisecond by coincidence, which
     * hid a resolution mismatch in the end-of-timeline check until the
     * full-length run. Both cases are pinned here.
     */
    public function test_timeline_end_is_compared_at_centisecond_resolution(): void
    {
        $fps = 30;
        $rate = 44100;

        // 12 scenes: 4863 frames is 162.1s exactly — a whole centisecond, so
        // milliseconds and centiseconds agree and anything would pass.
        $this->assertSame(16210.0, 4863 / $fps * 100);
        $this->assertSame(16210, GenerateAssSubtitles::samplesToCentiseconds(7148610, $rate));

        // 260 scenes: 105365 frames is 3512.16667s = 351216.667cs, which ASS
        // cannot represent. The timeline must land on the nearest centisecond.
        $trueCs = 105365 / $fps * 100;
        $this->assertNotSame(round($trueCs), $trueCs, 'This case must NOT land on a whole centisecond.');

        $expected = (int) round($trueCs);
        $this->assertSame(351217, $expected);
        $this->assertSame($expected, GenerateAssSubtitles::samplesToCentiseconds(154886550, $rate));

        // The residual is sub-frame, which is why it is tolerable at all.
        $residualMs = $expected * 10 - 105365 / $fps * 1000;
        $this->assertLessThan(1000 / $fps, abs($residualMs));

        // The old millisecond comparison would have rejected a correct file.
        $this->assertNotSame((int) round(105365 / $fps * 1000), $expected * 10);
    }

    public function test_samples_convert_to_centiseconds_consistently_across_a_boundary(): void
    {
        // Scene 1 ends where scene 2 begins, so both must land on the same
        // centisecond or a gap opens between lines.
        $this->assertSame(
            GenerateAssSubtitles::samplesToCentiseconds(660030, 44100),
            GenerateAssSubtitles::samplesToCentiseconds(660030, 44100)
        );

        $this->assertSame(1497, GenerateAssSubtitles::samplesToCentiseconds(660030, 44100));
        $this->assertSame(16210, GenerateAssSubtitles::samplesToCentiseconds(7148610, 44100));
    }
}
