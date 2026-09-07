<?php

// The counterpart, one byte away from the file beside it. Correct \b,
// plus a tab, CRLF line endings and em dashes — every byte this tool is
// deliberately NOT looking for. A tool that flagged UTF-8 or whitespace
// would be unrunnable in this codebase on its first day.
function matchesCueCorrectly(string $text, string $cue): bool
{
	return (bool) preg_match('/\b'.preg_quote($cue, '/').'\b/u', $text);
}
