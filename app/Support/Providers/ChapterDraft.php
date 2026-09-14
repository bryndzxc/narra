<?php

namespace App\Support\Providers;

/**
 * One chapter of an act, as the writer returned it.
 *
 * The only DTO in this pipeline that carries prose the act script is BUILT
 * from rather than sliced out of: the act writer returns its act as chapters,
 * and `ActScriptDraft::script` is their texts joined. The text is kept here
 * only long enough for GenerateActScripts to compute where each chapter
 * starts in the joined script; what is persisted is the index, not the text.
 */
final class ChapterDraft
{
    public function __construct(
        public readonly string $title,
        public readonly string $rehookLine,
        public readonly string $text,
    ) {}

    public function wordCount(): int
    {
        return str_word_count(strip_tags($this->text));
    }
}
