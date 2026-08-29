<?php

/*
|--------------------------------------------------------------------------
| Locale profiles
|--------------------------------------------------------------------------
|
| The channel is operated from the Philippines for a United States audience.
| That gap is not a stylistic preference — it is the thing most likely to make
| a story read as inauthentic to the people watching it, and it does not
| announce itself. A model asked for "a story about a school dance" from a
| Manila-shaped prompt will reach for a barangay fiesta as readily as a prom.
|
| So this is data rather than prose in a prompt string. `stories.locale_profile`
| names one of these, the generator injects `guidance` into every call, and
| `denylist` is checked against every act that comes back — failing the job
| loudly rather than passing the leak through to Gate 1, where an operator
| reviewing 7,000 words will not reliably catch a single "sari-sari store".
|
| Word-boundary matched and case-insensitive. Entries are regex-quoted before
| use, so an apostrophe or a hyphen in a term is safe to write literally.
|
*/

return [

    'default' => 'en-US',

    'profiles' => [

        'en-US' => [
            'label' => 'United States',

            /*
            | Injected into every generation call. Positive instruction — a
            | denylist alone teaches a model what not to say without telling it
            | what to say instead, and the result reads as neutral nowhere.
            */
            'guidance' => <<<'TEXT'
                Write for a United States audience, in American English.

                - US settings, US given names and surnames, US geography.
                - The US school system: high school, senior year, prom, homecoming,
                  college dorms, spring break.
                - US holidays and institutions: Thanksgiving, the Fourth of July,
                  the DMV, county sheriffs, the ER, 911.
                - Imperial units throughout: miles, feet, pounds, Fahrenheit.
                - US dollars.
                - American spelling: color, realize, traveled, gray, apologize.

                Do not use non-American idiom, honorifics or vocabulary of any kind.
                This story is read aloud by an American narrator to American
                listeners, and anything that reads as translated-from-elsewhere
                breaks it.
                TEXT,

            /*
            | Filipino idiom, honorifics, and institutions. The specific leak
            | this project is exposed to, listed because a generic "no
            | non-American idiom" instruction does not reliably prevent it.
            */
            /*
            | Filipino idiom, honorifics, and institutions - the specific leak
            | this project is exposed to, since a generic "no non-American
            | idiom" instruction does not reliably prevent it.
            |
            | THE RULE FOR THIS LIST: a term belongs here only if it has no
            | plausible English reading. Matching is case-insensitive and
            | word-boundary, and a hit FAILS a paid stage - so a term with an
            | English homograph does not cost an operator a warning, it costs
            | them the act.
            |
            | Learned the hard way: "ate" (older sister) was in this list and
            | failed a generated act on the past tense of "eat". Terms like
            | that live in the warnlist below, where they surface at Gate 1
            | without discarding work that was already paid for.
            */
            'denylist' => [
                // Interjections and discourse particles
                'ay naku', 'hay naku', 'naku', 'sayang', 'diba', 'di ba',
                'grabe', 'talaga', 'kaya naman',

                // Honorifics and address with no English homograph.
                // 'ate', 'po', 'lola', 'lolo', 'tita' and 'tito' are NOT here:
                // each is an ordinary English word or a common Western given
                // name. They are in the warnlist.
                'opo', 'kuya', 'nanay', 'tatay', 'inay', 'itay',

                // Places and institutions
                'barangay', 'sari-sari store', 'sari sari store', 'palengke',
                'jeepney', 'tricycle driver', 'sitio', 'purok', 'poblacion',
                'bayanihan',

                // Food and objects that read as untranslated
                'merienda', 'pandesal', 'adobo', 'sinigang', 'balut', 'tsinelas',
                'banig', 'kalesa',

                // Currency. 'pesos' is NOT here - a US story can legitimately
                // mention Mexican pesos.
                'piso', 'centavo', 'sentimo',

                // Common Tagalog verbs and nouns that survive into English drafts
                'kababayan', 'utang na loob', 'gigil', 'kilig',
                'pasalubong', 'harana', 'walang anuman',

                // British/Commonwealth spellings and idiom, which read as
                // not-American just as clearly and leak in from training data.
                // 'grey' and 'mobile phone' are NOT here: 'Grey' is a common
                // American surname and 'mobile phone' is understood in US
                // English even though 'cell phone' is standard.
                'colour', 'favourite', 'realise', 'realised', 'travelled',
                'apologise', 'apologised', 'kerb', 'lorry', 'petrol',
                'car park', 'maths', 'aeroplane', 'whilst', 'flat mate',
                'autumn term',
            ],

            /*
            | Wrong for the locale, but with a legitimate English reading. These
            | surface at Gate 1 as warnings rather than failing a stage.
            |
            | The reason the split exists: a guard that fails a $0.10 act on the
            | word "ate" is a guard an operator turns off, and a guard that is
            | off catches nothing. Everything ambiguous is reported, not enforced.
            */
            'warnlist' => [
                // Tagalog terms that are also ordinary English words or names
                'ate', 'po', 'aba', 'lola', 'lolo', 'tita', 'tito', 'tampo',
                'barrio', 'pesos',

                // British readings with common American ones
                'mum', 'grey', 'mobile phone', 'flat', 'holiday', 'football',
                'chips', 'biscuit', 'pavement', 'rubbish', 'queue',
            ],

        ],

    ],

];
