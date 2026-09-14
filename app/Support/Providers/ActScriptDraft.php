<?php

namespace App\Support\Providers;

/**
 * One act's narration script, plus what the next act needs to know about it.
 *
 * `summary` is the mechanism that makes chunked generation coherent. A single
 * API call cannot hold 7,000 words of narrative, so each act is written with
 * the outline plus a running summary of the acts before it. That summary has to
 * come back from the same call that wrote the act — asking a second call to
 * summarise what the first just wrote doubles the cost and adds a place for the
 * two to disagree.
 *
 * `rehookLine` is the act's opening line, engineered to carry a viewer across
 * the boundary. At 30-40 minutes the 15-second opening hook is not enough on
 * its own; every act needs one, and `acts.is_rehook_written` tracks whether it
 * happened so Gate 1 can surface an act that has none.
 *
 * `chapters` is the act as the writer returned it: two or three chapters,
 * each with a title and its own re-hook, whose texts joined ARE `script`.
 * The act stays the unit the script is written in; the chapter is the unit
 * it is watched in. See config/chapters.php for the measurement. Empty on a
 * draft from a writer that predates chapters, which GenerateActScripts
 * refuses — an act with no chapter boundaries is the shape being replaced.
 */
final class ActScriptDraft
{
    /**
     * @param  array<int, ChapterDraft>  $chapters
     */
    public function __construct(
        public readonly int $sequence,
        public readonly string $script,
        public readonly string $summary,
        public readonly string $rehookLine,
        public readonly ProviderUsage $usage,
        public readonly array $chapters = [],
    ) {}

    public function wordCount(): int
    {
        return str_word_count(strip_tags($this->script));
    }

    /** Everything a locale check has to read. */
    public function proseForInspection(): string
    {
        $chapterText = implode("\n", array_map(
            fn (ChapterDraft $chapter): string => $chapter->title."\n".$chapter->rehookLine,
            $this->chapters,
        ));

        return $this->script."\n".$this->summary."\n".$this->rehookLine."\n".$chapterText;
    }
}
