<?php

// The shipped defect, reproduced byte for byte: a word-boundary regex whose
// backslashes were eaten by a layer between the author and the file.
function matchesCue(string $text, string $cue): bool
{
    return (bool) preg_match('/'.preg_quote($cue, '/').'/u', $text);
}
