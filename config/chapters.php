<?php

/*
|--------------------------------------------------------------------------
| Chapters — the unit UNDER an act
|--------------------------------------------------------------------------
|
| Measured against a working video in this niche (34:46, 46.7K subscribers):
| it announces fourteen chapters, about 2:29 each, plus epilogues. Ours had six
| acts of 5:54 to 9:44 — chapters 2.6 to 2.9 times longer, a third as many
| re-hook points, and the first one at 6:45 to 7:47 on a format whose measured
| failure is people leaving inside the first three minutes.
|
| The act is kept as the GENERATION unit. Its running summary is what keeps
| 7,000 words coherent, its phase is what the writer branches on, and the
| writer returns ~1,100 words of it whatever it is asked (fitted slope +0.30,
| so a hundred more words asked buys thirty). Fourteen acts of that natural
| length is a 75-minute video. So the chapter goes UNDER the act: each act is
| returned as two or three chapters, the act's script stays the concatenated
| text every existing consumer reads, and the act's natural length stops being
| a problem and becomes the thing that fits — six acts of ~1,100 words is
| about fifteen chapters of ~440, 2:15 each at 197 wpm.
|
| The chapter carries what the YouTube chapter needs (a title, a start time
| filled by the render) and what the re-hook cadence needs (an opening line
| per chapter rather than per act). It does NOT carry its own text: the
| boundary is a sentence index into the act script, the same unit DraftScenes
| cuts scenes in, so nothing holds a second copy of the prose.
|
*/

return [

    /*
    | How long a chapter is meant to run. Converted to a word budget at the
    | story's own frozen sizing rate by ScriptSizing::chapterTargetWords(), so
    | the act prompt carries one belief about the narration, not two.
    |
    | 133 SECONDS, AND IT MOVED FROM 150 BECAUSE THE INPUT WAS CORRECTED, NOT
    | BECAUSE A RESULT DISAGREED WITH IT. 150 was chosen from a first reading
    | of the reference that said "fourteen chapters, about 2:29 each" — an
    | eyeball figure taken before the transcript existed here. The transcript
    | was then READ, and it carries the boundaries: 1:02, 4:29, 6:34, 8:16,
    | 11:35, 14:37, 16:17, 18:18, 19:54, 22:35, 25:30, 26:25, 28:27, 31:58,
    | with the fourteenth running to 32:20. That is 1,878 seconds across
    | fourteen chapters, a mean of 134 and a median of 133.
    |
    | This is the 160 -> 197 wpm correction in miniature and it follows the
    | same rule: never move a target to match a RESULT, always follow a
    | corrected INPUT. Story 30's chapters measured 2:52 and that is a result;
    | what moved the number is that the figure it was derived from was an
    | estimate and the measurement now exists.
    |
    | What it is worth: at 199 wpm a chapter budget of 441 words divides the
    | writer's measured 1,123-word act into 3 rather than 2. At 150 it divided
    | into 2.25 and rounded down, so deriving the count honestly from the real
    | length would have changed nothing on its own — the derivation and this
    | number are two separate changes and only both together move the cadence.
    */
    'target_seconds' => (int) env('CHAPTER_TARGET_SECONDS', 133),

    /*
    | How many chapters an act may come back as. Enforced against the decoded
    | response in GenerateActScripts, after the cost row, the way the
    | outline's act count and the summary bound are — never at the schema,
    | which honours neither minItems nor maxLength.
    |
    | Two is the floor because one chapter per act is the old shape. Four is
    | the ceiling because a 1,100-word act in five chapters is 220-word
    | chapters, about a minute each, and a chapter boundary every minute is a
    | video that keeps clearing its throat.
    */
    'min_per_act' => (int) env('CHAPTER_MIN_PER_ACT', 2),
    'max_per_act' => (int) env('CHAPTER_MAX_PER_ACT', 4),

    /*
    | The shortest chapter the writer may return, in words. YouTube's own floor
    | is ten seconds (~33 words at 197 wpm) and a ten-second chapter is a
    | chapter list entry nobody can act on; 150 words is about 45 seconds,
    | which is the least a re-hook can be given to do anything.
    */
    'min_words' => (int) env('CHAPTER_MIN_WORDS', 150),

    /*
    | Whether each chapter opens by SPEAKING ITS NUMBER — "Chapter four." —
    | the way the reference video announces its chapters.
    |
    | ON, from the transcript. The reference's captions carry "chapter 1" at
    | 1:02, "chapter 2" at 4:29 and so on through "chapter 14" at 31:58 and
    | "Extra 1 — Sophia's POV" at 32:20, inline in the spoken stream ("...You
    | picked the perfect time to be awesome chapter 1 With graduation
    | approaching..."), lowercased the way the ASR lowercases everything else
    | it hears. They are spoken. Two details the prompt carries because the
    | transcript settled them: the number is spoken and the TITLE is not (the
    | reference has no titles at all), and the cold open comes BEFORE
    | "chapter 1" — 62 seconds of hook, then the announcement — so act 1's
    | first announcement follows the five beats rather than opening the video.
    |
    | Off, chapter numbers reach YouTube's chapter list only and the prose
    | carries no marker.
    */
    'announce' => (bool) env('CHAPTER_ANNOUNCE', true),

];
