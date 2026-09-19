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
 *
 * `pointOfView` is empty for the narrator, which is every chapter but one: the
 * last chapter of the refusal act, on a story whose outline carries an
 * antagonist_regret, is told by the antagonist and holds her cast name here.
 * Last, so every positional caller is unchanged.
 */
final class ChapterDraft
{
    public function __construct(
        public readonly string $title,
        public readonly string $rehookLine,
        public readonly string $text,
        public readonly string $pointOfView = '',
    ) {}

    public function wordCount(): int
    {
        return str_word_count(strip_tags($this->text));
    }

    public function isPointOfView(): bool
    {
        return trim($this->pointOfView) !== '';
    }
}
