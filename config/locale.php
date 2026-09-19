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
| A second profile, `en-CN`, sets the story in China and keeps the narration in
| American English — the translated-Chinese-web-novel register a large part of
| this niche runs on. Both exist at once and neither replaces the other: they
| are meant to be run against comparable premises and compared.
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

/*
| Two of the lists below are shared by every profile, because they are not about
| the SETTING at all — they are about the operator and the narrator, and neither
| of those changes when the story moves.
|
| The channel is run from the Philippines, so Filipino idiom is a leak in every
| profile. The narration is read aloud by an American voice to American
| listeners in every profile, so a British spelling is wrong in every profile
| too. A second setting must not mean a second copy of either list: they would
| drift, and the newer profile would quietly stop catching what the older one
| catches.
*/

$operatorLeak = [
    // Interjections and discourse particles
    'ay naku', 'hay naku', 'naku', 'sayang', 'diba', 'di ba',
    'grabe', 'talaga', 'kaya naman',

    // Honorifics and address with no English homograph. 'ate', 'po', 'lola',
    // 'lolo', 'tita' and 'tito' are NOT here: each is an ordinary English word
    // or a common Western given name. They are in the warn list.
    'opo', 'kuya', 'nanay', 'tatay', 'inay', 'itay',

    // Places and institutions. 'tricycle driver' is NOT here: a county town in
    // China runs on three-wheelers, so it is denied in en-US and warned in
    // en-CN, per profile below.
    'barangay', 'sari-sari store', 'sari sari store', 'palengke',
    'jeepney', 'sitio', 'purok', 'poblacion',
    'bayanihan',

    // Food and objects that read as untranslated. 'merienda' and 'adobo' are
    // NOT here: both are ordinary Mexican and Spanish words a US story can use,
    // so they are warned in en-US and denied in en-CN, per profile below.
    'pandesal', 'sinigang', 'balut', 'tsinelas',
    'banig', 'kalesa',

    // Currency. 'pesos' is NOT here - a US story can legitimately mention
    // Mexican pesos - and 'centavo' is not either, for the same reason.
    'piso', 'sentimo',

    // Common Tagalog verbs and nouns that survive into English drafts
    'kababayan', 'utang na loob', 'gigil', 'kilig',
    'pasalubong', 'harana', 'walang anuman',
];

/*
| The same leak, in terms that are also ordinary English words or names. These
| warn rather than fail. "ate" (older sister) was in the deny list once and
| failed a generated act on the past tense of "eat".
*/

$operatorLeakWarn = [
    'ate', 'po', 'aba', 'lola', 'lolo', 'tita', 'tito', 'tampo',
    'barrio', 'pesos',
];

/*
| British and Commonwealth readings. Wrong in every profile, because the
| narrator is American whatever country the story is set in. This is the half of
| the old en-US list that is about the NARRATOR rather than the setting, which
| is why it survives into a profile set in China.
|
| EACH TERM CARRIES ITS AMERICAN WORD, and the writer is given the pairs
| (LocaleGuard::americanWordsLine(), appended to every profile's guidance).
| Until 2026-09-19 this was a bare list: the guard knew "car park" was wrong
| and nothing told the writer "parking lot" was right. It cost three refusals
| and about $0.90 — story 36's act 2, then story 38's act 4 scene drafting
| twice, all from the one phrase in act prose — while "parking lot" sat in
| fourteen act scripts already. A term added here without its word does not
| reach the writer at all, and PromptLocaleTest says so.
*/

$notAmericanEnglish = [
    // 'grey' and 'mobile phone' are NOT here: 'Grey' is a common American
    // surname and 'mobile phone' is understood in US English even though 'cell
    // phone' is standard.
    'colour' => 'color',
    'favourite' => 'favorite',
    'realise' => 'realize',
    'realised' => 'realized',
    'travelled' => 'traveled',
    'apologise' => 'apologize',
    'apologised' => 'apologized',
    'kerb' => 'curb',
    'lorry' => 'truck',
    'petrol' => 'gas',
    'car park' => 'parking lot',
    'maths' => 'math',
    'aeroplane' => 'airplane',
    'whilst' => 'while',
    'flat mate' => 'roommate',
    'autumn term' => 'fall semester',
];

$notAmericanEnglishWarn = [
    'mum', 'grey', 'mobile phone', 'flat', 'holiday', 'football',
    'chips', 'biscuit', 'pavement', 'rubbish', 'queue',
];

return [

    'default' => 'en-US',

    // The British term => the American word the writer is told to use.
    // Shared by every profile, like the denylist entries it is keyed on.
    'american_words' => $notAmericanEnglish,

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
            | Both lists that used to be written out here are now shared, at the
            | top of this file — the Filipino terms because the operator sits in
            | Manila whatever the story is about, and the British spellings
            | because the narrator is American whatever the story is about.
            |
            | THE RULE FOR THIS LIST, unchanged and applying to every profile: a
            | term belongs on a denylist only if it has no plausible reading in
            | that setting. Matching is case-insensitive and word-boundary, and a
            | hit FAILS a paid stage - so a term with a homograph does not cost
            | an operator a warning, it costs them the act.
            |
            | Learned the hard way: "ate" (older sister) was on the deny list and
            | failed a generated act on the past tense of "eat". Terms like that
            | live in the warn list, where they surface at Gate 1 without
            | discarding work that was already paid for.
            */
            'denylist' => array_merge($operatorLeak, array_keys($notAmericanEnglish), [
                // Nothing about AMERICA is in here, and that is the finding
                // rather than an omission: for a story set in the United States
                // every term this list ever held was about the OPERATOR's idiom
                // or the NARRATOR's spelling. What is here is operator idiom
                // whose plausibility depends on the setting, so it cannot live
                // in the shared list (2026-09-14 sweep): no US reading, but a
                // real one in a Chinese county town.
                'tricycle driver',
            ]),

            /*
            | Wrong for the locale, but with a legitimate English reading. These
            | surface at Gate 1 as warnings rather than failing a stage.
            |
            | The reason the split exists: a guard that fails a $0.10 act on the
            | word "ate" is a guard an operator turns off, and a guard that is
            | off catches nothing. Everything ambiguous is reported, not enforced.
            */
            'warnlist' => array_merge($operatorLeakWarn, $notAmericanEnglishWarn, [
                // Filipino words that are ALSO ordinary Mexican or Spanish
                // words, so a US story has a reading for each. Denied in en-CN.
                'merienda', 'adobo', 'centavo',
            ]),

        ],

        /*
        |----------------------------------------------------------------------
        | en-CN — Chinese setting, American narration
        |----------------------------------------------------------------------
        |
        | A large part of this niche is translated Chinese web novels, and the
        | register this channel writes in came from there. It also suits the art
        | style better than American suburbia does.
        |
        | THE DISTINCTION THAT SHAPES BOTH LISTS BELOW, and the one that is easy
        | to get wrong: this profile changes the SETTING, not the language. The
        | narration is still plain American English read aloud by an American
        | voice to American listeners. So American VOCABULARY is correct here —
        | sidewalk, apartment, elevator, gotten — and it is American
        | INSTITUTIONS, HOLIDAYS, UNITS and CURRENCY that are the leak. A guard
        | that failed an act for saying "sidewalk" would be enforcing a rule
        | nobody wrote.
        |
        | It mirrors en-US in one respect only: en-US denies the idiom of where
        | the operator sits, and this denies the idiom of where the audience
        | sits. Both are the same failure — a story that reads as assembled
        | somewhere other than where it is set.
        */
        'en-CN' => [
            'label' => 'China (English narration)',

            'guidance' => <<<'TEXT'
                Write for a United States audience, in American English, about a story set
                in China. This is the register of a translated Chinese web novel: the
                setting, the names and the family structure are Chinese; the prose is plain
                American English, because an American narrator reads it aloud.

                - Chinese settings and geography: a provincial city, a county town, an
                  ancestral village, a Beijing or Shanghai apartment block, a hospital ward,
                  a company office.
                - Names split by generation, because this is how the people in this world
                  actually introduce themselves in English.
                  - Characters in their twenties: an English given name with a Chinese
                    family name, given name first — Kevin Lin, Amy Sun, Grace Zhou,
                    Leo Xu. This is the generation that picked an English name at
                    university or at work, and using it marks them as the ones who have
                    one foot outside the family.
                  - Parents, grandparents, in-laws and anyone of that generation: a full
                    Chinese name, family name first — Wang Suhua, Chen Wei, Li Xiuying,
                    Zhang Ming. They do not have an English name and would not use one.
                  - Never Tito, Lola, Ate or Po as a given name. They read as English
                    names but each is also a Filipino honorific, and the narration is
                    checked for those.
                - ONE form per character, everywhere, for the whole story. If a character
                  is Kevin Lin, he is Kevin or Kevin Lin and never a second Chinese given
                  name as well — not in narration, not in dialogue, not when an elder is
                  speaking to him. A character with two names is a character the audience
                  has to keep reconciling while listening.
                - Family structure with real authority in it. Parents, parents-in-law and
                  elder relatives make decisions that bind adult children, and refusing them
                  has a cost. This is the engine of the genre and it must not be softened
                  into an American family where every adult is independent.
                - The vocabulary the genre runs on, used naturally rather than explained:
                  dowry, bride price, face, losing face, giving face, filial duty, filial
                  piety, shameless, the eldest son, the family banquet, the ancestral home,
                  the family register, a red envelope, the in-laws.
                - Dialogue is formal and direct. Accusations are made outright and to the
                  person's face — "You are shameless", "You have no filial piety" — not
                  implied, hinted at, or delivered as sarcasm. Characters address each other
                  by relationship as often as by name: Mother, Second Uncle, Eldest Brother,
                  Auntie.
                - Yuan. Amounts are stated in yuan, and a sum should be plausible for the
                  setting.
                - Metric units throughout: kilometers, meters, centimeters, kilograms,
                  Celsius, square meters for an apartment.
                - American spelling: color, realize, traveled, gray, apologize.

                Do NOT relocate the story to America by accident. No American holidays,
                schools, agencies, law enforcement, currency or measurements. The narration
                is American English; the world is not.
                TEXT,

            /*
            | The inverse check. Same rule as en-US's list, applied the other
            | way round: a term belongs here only if it has NO plausible reading
            | in a story set in China. A hit fails a paid stage, so anything
            | carrying a second reading goes in the warnlist instead — and the
            | imperial units are almost all like that, because 'feet' are body
            | parts, 'pounds' and 'inches' are verbs, 'miles' is a name, and
            | 'ounce' lives inside an idiom.
            |
            | Note the terms deliberately ABSENT. 'county' is a real Chinese
            | administrative division and the standard English for it. 'high
            | school', 'middle school', 'college' and 'dorm' all exist in China.
            | 'mayor' exists. 'Labor Day' is a Chinese public holiday. Every one
            | of those would have failed an act for describing China correctly.
            */
            'denylist' => array_merge($operatorLeak, array_keys($notAmericanEnglish), [
                // The shared lists come first and they are not optional here.
                // Moving the story to China does not move the operator out of
                // Manila or make the narrator British, so both leaks are live in
                // this profile exactly as they are in en-US. What follows is the
                // part that is specific to this setting.

                // Operator idiom with no reading in China but a real one in a
                // US story (Mexican and Spanish words), so not in the shared list.
                'merienda', 'adobo', 'centavo',

                // US holidays with no Chinese reading. 'fourth of july' and
                // 'memorial day' are NOT here: "the twenty-fourth of July" and
                // "the fourth of July" are dates, and National Memorial Day is
                // China's own name for December 13. Both refused a real act
                // or would have (story 34, act 3, $0.1939). Warnlist.
                'thanksgiving', 'super bowl', 'mardi gras',

                // The US school system specifically. 'senior year' and
                // 'freshman' are NOT here — translated novels use them for the
                // Chinese equivalents, so they sit in the warnlist. Neither are
                // 'school district' (school-district housing is one of the
                // most Chinese details a story can carry; it refused story 29's
                // act 3), 'prom' (international schools hold them), 'varsity'
                // and 'cheerleader' (Chinese university teams and squads), or
                // 'little league' (an idiom, and idiom is vocabulary).
                'homecoming game', 'sorority', 'fraternity house',
                'PTA meeting', 'valedictorian',

                // US agencies, law and institutions. 'zip code' is NOT here:
                // it is what an American calls any postal code, and vocabulary
                // for a Chinese thing is correct. Neither are 'homeowners
                // association' (the 业委会, in a genre full of apartment
                // disputes) or 'ivy league' (what Chinese parents aim a child
                // at). All three are warned.
                'DMV', 'IRS', 'FBI', 'social security number', 'medicare',
                'medicaid', 'food stamps', 'sheriff', 'state trooper',
                'district attorney', 'grand jury', 'public defender',
                'miranda rights', 'national guard', 'trailer park',
                'the midwest',

                // Emergency number. China's are 110 and 120. Matched as
                // phrases rather than as the bare digits, which are also a
                // year, a street number and a sports car.
                'call 911', 'called 911', 'dialed 911', '911 operator',

                // The one imperial unit with no second reading. 'dollar' and
                // 'dollars' are NOT here: Hong Kong dollars and US-dollar
                // savings are ordinary in this genre. Warned, not refused.
                'fahrenheit',
            ]),

            /*
            | Wrong for the setting but with a legitimate reading — imperial
            | units whose words are ordinary English, and the handful of
            | American-flavoured terms a translated novel genuinely uses.
            | Surfaced at Gate 1, never enforced.
            */
            'warnlist' => array_merge($operatorLeakWarn, $notAmericanEnglishWarn, [
                // Imperial units. Every one has an English homograph or lives
                // in an idiom, which is exactly why none of them are above.
                'miles', 'mile', 'feet', 'inches', 'inch', 'pounds', 'ounces',
                'ounce', 'yards', 'gallons', 'acres', 'cents',

                // Moved off the denylist by its own rule on 2026-09-14: each
                // has a plausible reading in a story set in China. See the
                // comments above for which reading. A real leak of any of them
                // still surfaces here, where it costs a glance, not an act.
                'dollar', 'dollars', 'fourth of july', '4th of july',
                'memorial day', 'school district', 'prom', 'varsity',
                'cheerleader', 'little league', 'zip code',
                'homeowners association', 'ivy league', 'tricycle driver',

                // Christian and American-civic furniture. Present in China but
                // unusual in this genre, so worth a look rather than a refusal.
                'church', 'pastor', 'reverend', 'christmas', 'halloween',

                // US school vocabulary that translated novels DO borrow for the
                // Chinese equivalents, so a warning rather than a failure.
                'senior year', 'freshman', 'sophomore', 'junior year',
                'graduate school', 'homeroom',

                // Reads as an American suburb rather than a Chinese city.
                'backyard', 'front porch', 'the suburbs', 'picket fence',
                'driveway', 'strip mall',
            ]),

        ],

    ],

];
