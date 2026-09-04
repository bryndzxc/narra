<?php

namespace App\Support;

use App\Exceptions\LocaleViolationException;

/**
 * Checks generated prose against a locale profile's denylist.
 *
 * There is more than one profile now — `en-US` and `en-CN` — and the guard did
 * not have to change for the second one, which is the point of the lists being
 * data. What DID have to change is the wording below: a term is not "wrong"
 * absolutely, it is wrong for a setting. "Thanksgiving" fails a story set in
 * China and is required in one set in Ohio.
 *
 * Runs on every act the moment it comes back, before anything is written to the
 * database and long before an operator sees it at Gate 1. That placement is the
 * point: an act that leaked idiom should cost one re-run, not a review cycle.
 *
 * Two lists, because they fail differently.
 *
 *   `denylist` — unambiguous for that profile. "barangay", "ay naku", "colour"
 *   in either; "dollars" and "sheriff" in a story set in China. These fail the
 *   stage, because there is no reading of that story where they are correct.
 *
 *   `warnlist` — locale-wrong but legitimately ambiguous. "mum" is a flower,
 *   "flat" is a tyre, "chips" are a side. Failing on these would make the guard
 *   something an operator learns to disable, and a disabled guard catches
 *   nothing. They surface at Gate 1 instead.
 *
 * Matching is word-boundary and case-insensitive, and every term is
 * preg_quote'd — the config is a list of words, not a list of patterns, so a
 * hyphen or an apostrophe in an entry must be safe to write literally.
 */
class LocaleGuard
{
    /** Characters of surrounding text kept with each hit, so the report is readable. */
    private const CONTEXT_CHARS = 40;

    /**
     * Fail the stage if the text carries any denied term.
     *
     * @throws LocaleViolationException
     */
    public function assert(string $text, string $localeProfile, string $stage): void
    {
        $hits = $this->hits($text, $this->listFor($localeProfile, 'denylist'));

        if ($hits !== []) {
            throw new LocaleViolationException($localeProfile, $stage, $hits);
        }
    }

    /**
     * Ambiguous terms, for Gate 1 to display. Never throws.
     *
     * @return array<int, array{term: string, context: string}>
     */
    public function warnings(string $text, string $localeProfile): array
    {
        return $this->hits($text, $this->listFor($localeProfile, 'warnlist'));
    }

    /**
     * Every profile that exists, as key => label, for an operator to pick from.
     *
     * Here rather than read straight out of config at the call site, because
     * the alternative is a page that hardcodes its own list of settings. The
     * moment a third profile is added, the config would have it and the picker
     * would not — and the story would go on being generated for the wrong
     * country, silently, since a profile nobody can select is indistinguishable
     * from one that does not exist.
     *
     * @return array<string, string>
     */
    public function profiles(): array
    {
        $labels = [];

        foreach ((array) config('locale.profiles', []) as $key => $profile) {
            $labels[(string) $key] = (string) ($profile['label'] ?? $key);
        }

        return $labels;
    }

    /** The positive instruction injected into every generation call. */
    public function guidanceFor(string $localeProfile): string
    {
        $profile = config("locale.profiles.{$localeProfile}");

        if ($profile === null) {
            throw new \InvalidArgumentException(
                "Unknown locale profile '{$localeProfile}'. Profiles live in config/locale.php; a story "
                .'whose locale_profile names one that does not exist would silently generate with no '
                .'locale guidance at all, which is the failure this guard exists to prevent.'
            );
        }

        return trim((string) ($profile['guidance'] ?? ''));
    }

    /**
     * Every match, with surrounding context.
     *
     * All of them, not the first — an act with six leaks should report six, so
     * one re-run fixes the prompt rather than six re-runs fixing it one term at
     * a time.
     *
     * @param  array<int, string>  $terms
     * @return array<int, array{term: string, context: string}>
     */
    private function hits(string $text, array $terms): array
    {
        if ($terms === [] || trim($text) === '') {
            return [];
        }

        $found = [];

        foreach ($terms as $term) {
            // \b does not do what is wanted at the edge of a multi-word term
            // ending in punctuation, so the boundary is asserted explicitly
            // against a non-word character or the string edge.
            $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($term, '/').'(?![\p{L}\p{N}])/iu';

            if (preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            $found[] = [
                'term' => $term,
                'context' => $this->contextAround($text, (int) $match[0][1], strlen($match[0][0])),
            ];
        }

        return $found;
    }

    private function contextAround(string $text, int $offset, int $length): string
    {
        $start = max(0, $offset - self::CONTEXT_CHARS);
        $slice = substr($text, $start, $length + self::CONTEXT_CHARS * 2);

        // The offsets come from a byte-oriented match, so a slice can land
        // mid-codepoint. Scrubbing keeps the exception message printable
        // rather than mangling the terminal it is written to.
        return trim(preg_replace('/\s+/u', ' ', mb_scrub($slice)) ?? '');
    }

    /**
     * @return array<int, string>
     */
    private function listFor(string $localeProfile, string $key): array
    {
        $profile = config("locale.profiles.{$localeProfile}");

        if ($profile === null) {
            throw new \InvalidArgumentException(
                "Unknown locale profile '{$localeProfile}'. Profiles live in config/locale.php."
            );
        }

        return $profile[$key] ?? [];
    }
}
