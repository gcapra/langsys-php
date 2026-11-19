<?php

namespace Langsys\SDK\Format;

use Langsys\SDK\Log\LoggerInterface;

/**
 * Interpolates {placeholder} values into translated strings.
 *
 * Behaviour is aligned with the Langsys JS SDK so that a single shared catalog
 * renders identically from a PHP backend and a JS frontend:
 *
 * - Unknown keys are left VERBATIM (including braces), never blanked. A missing
 *   value must be visible to the developer rather than silently rendering empty.
 *   The same applies to a key that is present but null.
 * - Whitespace inside braces is tolerated: `{ name }` resolves the key `name`.
 * - Numbers are formatted per the target locale's CLDR rules (1234.5 renders as
 *   "1.234,5" in de-DE). Pass a value as a string to opt out of grouping - the
 *   documented escape hatch for IDs, codes and version numbers.
 * - Dates are formatted with the locale's medium date style.
 * - Booleans render as "true"/"false" to match JS String(bool), not PHP's "1"/"".
 * - ICU MessageFormat ({n, plural, one {...} other {...}}) is detected and
 *   formatted with ext-intl so plural rules are correct per language.
 *
 * Failure handling is deliberately non-fatal in both directions:
 * - If a string uses ICU syntax but ext-intl is unavailable at runtime, a
 *   warning naming the phrase and the missing extension is logged once, and the
 *   string falls back to simple substitution. ext-intl is a hard composer
 *   requirement, so this only occurs when intl is disabled after install; the
 *   point is that the operator is told exactly why, rather than discovering it
 *   as a quietly mistranslated plural.
 * - If ICU parsing fails, we fall back to simple substitution rather than
 *   throwing. A visibly-imperfect string beats a crash mid-render, and backend
 *   validation is meant to stop malformed ICU reaching us in the first place.
 */
class Interpolator
{
    /**
     * Detects ICU MessageFormat argument slots.
     *
     * Deliberately matches style-less forms such as `{n, number}` as well as
     * `{n, plural, ...}`. Kept character-for-character in sync with the JS SDK.
     */
    const ICU_PATTERN = '/\{[^{}]+,\s*(plural|select|selectordinal|number|date|time)\s*[,}]/';

    /**
     * Matches a simple placeholder and captures its (untrimmed) key.
     */
    const PLACEHOLDER_PATTERN = '/\{([^{}]*)\}/';

    /**
     * The `%name%` escape form (TOK-5).
     *
     * Deliberately restricted to identifier characters. A looser pattern would
     * match across ordinary prose - "Save 20% on 5% APR" contains a `%...%`
     * span - and turn a percentage into a placeholder. With this pattern the
     * span between the two signs must look like a variable name, and even then
     * it is only substituted when the caller actually supplied that key, so
     * unmatched text is returned untouched rather than eaten.
     */
    const PERCENT_PLACEHOLDER_PATTERN = '/%([A-Za-z_][A-Za-z0-9_]*)%/';

    /**
     * Locale used for ICU formatting when no target locale is known.
     */
    const FALLBACK_LOCALE = 'en';

    /**
     * Maximum ICU nesting depth handled by the no-intl fallback.
     */
    const MAX_ICU_DEPTH = 24;

    /**
     * @var LoggerInterface|null
     */
    protected $logger;

    /**
     * @var bool Whether the missing-intl warning has already been emitted.
     */
    protected $warnedMissingIntl = false;

    /**
     * @param LoggerInterface|null $logger
     */
    public function __construct($logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * @var array Locale+template pairs already noted, so one recovering phrase
     *            rendered a thousand times produces one notice, not a thousand.
     */
    protected $notedRecoveries = [];

    /**
     * @var array Locale+template pairs whose formatter failure was warned.
     */
    protected $warnedFormatterFailures = [];

    /**
     * Whether the render in progress prints numbers as plain digits (MIG-4).
     *
     * @var bool
     */
    protected $plainNumbers = false;

    /**
     * Interpolate parameters into a string.
     *
     * @param string $text The (already translated) string
     * @param array $params Map of placeholder name => value
     * @param string|null $locale Target locale, drives plural rules and formatting
     * @param bool $plainNumbers Print numbers as plain digits, `#` included, as a
     *                           legacy key's source framework does (MIG-4); a
     *                           plural still selects by the number
     * @return string
     */
    public function interpolate($text, array $params = [], $locale = null, $plainNumbers = false)
    {
        $previous = $this->plainNumbers;
        $this->plainNumbers = (bool) $plainNumbers;

        try {
            return $this->interpolateText($text, $params, $locale);
        } finally {
            $this->plainNumbers = $previous;
        }
    }

    /**
     * interpolate()'s body.
     *
     * @param string $text
     * @param array $params
     * @param string|null $locale
     * @return string
     */
    protected function interpolateText($text, array $params, $locale)
    {
        if (!is_string($text) || $text === '') {
            return $text;
        }

        // `%` as well as `{`: TOK-5 makes `%name%` an accepted spelling of
        // `{name}`, and returning early on `{` alone shipped the raw `%name%`
        // to the reader - a placeholder rendered as literal text on the page.
        if (strpos($text, '{') === false && strpos($text, '%') === false) {
            return $text;
        }

        // Normalise the escape form ONCE, here, rather than teaching every
        // downstream path a second spelling. After this line ICU detection,
        // argument recovery and simple substitution all see `{name}` and cannot
        // disagree about which forms they support.
        $text = $this->normalizePercentPlaceholders($text, $params);

        // No params is the COMMONEST call, and it is exactly the case that used
        // to return here - shipping raw ICU source to the page whenever a
        // translator wrote a plural into a phrase the caller renders without
        // arguments. Recovery exists for the missing-argument case, and "all
        // arguments missing" is the most missing a phrase can be, so the fast
        // path is only safe for text with no ICU construct in it.
        if (empty($params) && !$this->hasIcuSyntax($text)) {
            return $text;
        }

        if ($this->hasIcuSyntax($text)) {
            if (!$this->hasIntl()) {
                // Simple substitution CANNOT handle an ICU construct - its
                // pattern can't match a slot containing commas and braces - so
                // the raw MessageFormat source would be emitted to the page.
                // For a localization product that is worse than an untranslated
                // phrase, which at least reads as a sentence.
                $this->warnMissingIntl($text);

                return $this->renderIcuWithoutIntl($text, $params, $locale);
            }

            // A well-formed pattern whose ARGUMENT is missing is not a parse
            // failure, so MessageFormatter neither throws nor returns false - it
            // echoes a bare "{argName}" and destroys the surrounding sentence.
            // The malformed-ICU fallback below is therefore never reached. Route
            // these to the branch-selecting renderer instead, which keeps the
            // sentence and makes the gap visible.
            //
            // Reachable without any caller error: the backend promotes a plain
            // {name} into {name_gender, select, ...} for gendered target locales,
            // so the argument does not exist in the source phrase the developer
            // wrote and nothing tells them the target grew one.
            $missing = $this->missingIcuArguments($text, $params);

            if ($missing) {
                $this->noteDefaultedArguments($missing, $text, $locale);

                // Rewrite ONLY the missing nodes and hand the rest back to intl.
                //
                // This used to route the WHOLE string to the simplified
                // renderer, whose branch selection knows only `=N`, one-iff-1
                // and `other`. So one missing argument silently degraded every
                // OTHER argument's plural selection: in pl/ru/ar a supplied
                // n=3 rendered `other` where CLDR requires `few`, producing
                // grammatically wrong output in languages that mark it.
                //
                // Recovering node-by-node keeps supplied arguments on the intl
                // path, where their CLDR categories are correct.
                //
                // Each recovered node becomes an argument of its own, its
                // rendering the value: intl writes a value as it is, where the
                // same text in the pattern would be read as ICU, a param value
                // naming another param or holding a quote or a `#` included.
                $recovered = [];
                $rewritten = $this->recoverMissingNodes($text, $missing, $params, $locale, 0, $recovered, $this->recoveredArgumentPrefix($text, $params));

                // Hand intl only the SUPPLIED arguments, and the recovered ones.
                // A missing argument can be missing by being present-and-null
                // (ICU-2), and passing that through would let intl fill a plain
                // `{argName}` the phrase also holds with an empty string -
                // erasing the visible gap. Withholding them makes intl echo the
                // literal, which is the required output.
                $suppliedParams = array_diff_key($params, array_flip($missing));

                $formatted = $this->formatIcu($rewritten, $recovered + $suppliedParams, $locale, $text);

                if (is_string($formatted)) {
                    return $formatted;
                }

                // intl absent, or the rewritten pattern would not parse or
                // format.
                return $this->renderIcuWithoutIntl($text, $params, $locale);
            }

            $formatted = $this->formatIcu($text, $params, $locale);
            if (is_string($formatted)) {
                return $formatted;
            }

            // ICU-6: a pattern that parses but fails to format renders through
            // this SDK's own branch selection - never "" and never the raw
            // construct.
            if ($formatted === false) {
                return $this->renderIcuWithoutIntl($text, $params, $locale);
            }

            // A pattern that does not parse falls through to simple
            // substitution, which leaves the construct untouched. This matches
            // the JS SDK exactly - see testMalformedIcuFallsBackToTemplateUnchanged.
        }

        return $this->substitute($text, $params, $locale);
    }

    /**
     * Whether a string contains ICU MessageFormat argument slots.
     *
     * @param string $text
     * @return bool
     */
    public function hasIcuSyntax($text)
    {
        return is_string($text) && preg_match(self::ICU_PATTERN, $text) === 1;
    }

    /**
     * Whether a string contains any simple placeholder.
     *
     * @param string $text
     * @return bool
     */
    public function hasPlaceholders($text)
    {
        return is_string($text) && preg_match(self::PLACEHOLDER_PATTERN, $text) === 1;
    }

    /**
     * Format a string via ICU MessageFormat.
     *
     * @param string $text
     * @param array $params
     * @param string|null $locale
     * @param string|null $template The phrase as written, when $text is a rewrite of it
     * @return string|false|null The formatted string; false when the pattern
     *                           parsed and the formatter failed on it (ICU-6);
     *                           null when intl is absent or the pattern does not parse
     */
    protected function formatIcu($text, array $params, $locale, $template = null)
    {
        if (!$this->hasIntl()) {
            $this->warnMissingIntl($text);
            return null;
        }

        $icuLocale = $this->resolveLocale($locale);

        // MessageFormatter wants scalars/timestamps; DateTime is not accepted.
        // A date or number in a plain `{d}` / `{n}` is written as the simple
        // path writes it: intl would print a date's timestamp, and PHP's
        // MessageFormatter prints an untyped number unformatted (`1234.5` in
        // de-DE, not `1.234,5`). A typed argument (`{n, plural}`, `{n, number}`)
        // stays a number, so plural rules and `#` still see it.
        $icuParams = [];
        foreach ($params as $key => $value) {
            if ($value instanceof \DateTimeInterface && !$this->isTypedArgument($text, $key)) {
                $icuParams[$key] = $this->formatDate($value, $locale);
                continue;
            }

            $icuValue = $this->toIcuValue($value);
            $icuParams[$key] = (is_int($icuValue) || is_float($icuValue)) && !$this->isTypedArgument($text, $key)
                ? $this->formatNumber($icuValue, $locale)
                : $icuValue;
        }

        // MIG-4: with plain numbers, `#` and `{n, number}` become arguments of
        // their own holding the number as text, which intl writes as it is.
        if ($this->plainNumbers) {
            $plain = [];
            $text = $this->plainNumberPattern($text, $params, $this->plainArgumentPrefix($text, $params), $plain);
            $icuParams += $plain;
        }

        // intl can THROW rather than return a falsy value: MessageFormatter
        // raises IntlException when intl.use_exceptions is On, and @ does not
        // suppress exceptions. Escaping here would break the render, which this
        // class promises never to do.
        $formatter = null;

        try {
            $formatter = @\MessageFormatter::create($icuLocale, $text);

            // create() returns null on some versions and false on others.
            if (!$formatter) {
                $this->log('warning', 'ICU pattern could not be parsed; falling back to simple interpolation', [
                    'phrase' => $template !== null ? $template : $text,
                    'locale' => $icuLocale,
                    'intl_error' => intl_get_error_message(),
                ]);
                return null;
            }

            $result = @$formatter->format($icuParams);

            if ($result === false) {
                $this->warnFormatterFailure($template !== null ? $template : $text, $icuLocale, $formatter->getErrorMessage());
                return false;
            }

            return $result;
        } catch (\Throwable $e) {
            if ($formatter) {
                $this->warnFormatterFailure($template !== null ? $template : $text, $icuLocale, $e->getMessage());
                return false;
            }

            $this->log('warning', 'ICU pattern could not be parsed; falling back to simple interpolation', [
                'phrase' => $template !== null ? $template : $text,
                'locale' => $icuLocale,
                'exception' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * The pattern with each `#` and style-less `{n, number}` given a number
     * replaced by an argument of its own, whose value, in $plain, is the number
     * as plain digits. A plural keeps its own argument, so it still selects by
     * the number. `#` belongs to the plural whose branch holds it directly, as
     * ICU reads it: in a select's branch it is text.
     *
     * @param string $text
     * @param array $params
     * @param string $prefix
     * @param array $plain Filled with argument name => plain digits
     * @param string|null $hash The argument a `#` here stands for
     * @param int $offset That plural's offset
     * @param int $depth
     * @return string
     */
    protected function plainNumberPattern($text, array $params, $prefix, array &$plain, $hash = null, $offset = 0, $depth = 0)
    {
        if ($depth > self::MAX_ICU_DEPTH) {
            return $text;
        }

        $out = '';
        $length = strlen($text);
        $i = 0;

        while ($i < $length) {
            $char = $text[$i];

            if ($char === '#' && $hash !== null) {
                $out .= $this->plainArgument($this->toIcuValue($params[$hash]) - $offset, $prefix, $plain);
                $i++;
                continue;
            }

            if ($char !== '{') {
                $out .= $char;
                $i++;
                continue;
            }

            $end = $this->matchingBrace($text, $i);

            if ($end === null) {
                $out .= substr($text, $i);
                break;
            }

            $out .= $this->plainNumberArgument(substr($text, $i + 1, $end - $i - 1), $params, $prefix, $plain, $depth);
            $i = $end + 1;
        }

        return $out;
    }

    /**
     * One argument of plainNumberPattern(), braces included.
     *
     * @param string $body The text between the argument's braces
     * @param array $params
     * @param string $prefix
     * @param array $plain
     * @param int $depth
     * @return string
     */
    protected function plainNumberArgument($body, array $params, $prefix, array &$plain, $depth)
    {
        $comma = strpos($body, ',');

        if ($comma === false) {
            return '{' . $body . '}';
        }

        $name = trim(substr($body, 0, $comma));
        $rest = substr($body, $comma + 1);
        $secondComma = strpos($rest, ',');
        $type = strtolower(trim($secondComma === false ? $rest : substr($rest, 0, $secondComma)));
        $value = array_key_exists($name, $params) ? $this->toIcuValue($params[$name]) : null;
        $numeric = is_int($value) || is_float($value);

        if ($type === 'number' && $secondComma === false) {
            return $numeric ? $this->plainArgument($value, $prefix, $plain) : '{' . $body . '}';
        }

        if ($secondComma === false || !in_array($type, ['plural', 'selectordinal', 'select'], true)) {
            return '{' . $body . '}';
        }

        $branches = substr($rest, $secondComma + 1);
        $hash = $type !== 'select' && $numeric ? $name : null;
        $offset = $hash !== null && preg_match('/^\s*offset\s*:\s*(\d+)/', $branches, $match) ? (int) $match[1] : 0;

        $out = '';
        $length = strlen($branches);
        $i = 0;

        while ($i < $length) {
            if ($branches[$i] !== '{') {
                $out .= $branches[$i];
                $i++;
                continue;
            }

            $end = $this->matchingBrace($branches, $i);

            if ($end === null) {
                $out .= substr($branches, $i);
                break;
            }

            $out .= '{' . $this->plainNumberPattern(substr($branches, $i + 1, $end - $i - 1), $params, $prefix, $plain, $hash, $offset, $depth + 1) . '}';
            $i = $end + 1;
        }

        return '{' . substr($body, 0, $comma + 1) . substr($rest, 0, $secondComma + 1) . $out . '}';
    }

    /**
     * A new argument holding a number as plain digits.
     *
     * @param int|float $value
     * @param string $prefix
     * @param array $plain
     * @return string
     */
    protected function plainArgument($value, $prefix, array &$plain)
    {
        $name = $prefix . count($plain);
        $plain[$name] = (string) $value;

        return '{' . $name . '}';
    }

    /**
     * An argument-name prefix nothing in the text or the params uses.
     *
     * @param string $text
     * @param array $params
     * @return string
     */
    protected function plainArgumentPrefix($text, array $params)
    {
        $prefix = 'langsys_plain_';
        $taken = $text . "\0" . implode("\0", array_map('strval', array_keys($params)));

        while (strpos($taken, $prefix) !== false) {
            $prefix = 'x' . $prefix;
        }

        return $prefix;
    }

    /**
     * ICU-6: a formatter failure is a defect in the phrase, so it warns at
     * every log level - unlike ICU-4's debug-only notice for a missing
     * argument, which is normal - once per template and locale.
     *
     * @param string $template
     * @param string $icuLocale
     * @param string $error
     * @return void
     */
    protected function warnFormatterFailure($template, $icuLocale, $error)
    {
        $key = $icuLocale . "\0" . $template;

        if (isset($this->warnedFormatterFailures[$key])) {
            return;
        }

        $this->warnedFormatterFailures[$key] = true;

        $this->log('warning', 'ICU formatting failed; rendered through the SDK\'s own branch selection. Fix the phrase.', [
            'phrase' => $template,
            'locale' => $icuLocale,
            'intl_error' => $error,
        ]);
    }

    /**
     * Replace only the ICU nodes whose argument is missing, leaving the rest
     * byte-identical so intl can apply full CLDR selection to them. Each one
     * becomes a plain argument named $prefix and a number, its rendering in
     * $recovered under that name.
     *
     * Descends into supplied nodes as well, so a missing argument nested inside
     * a supplied `select` recovers while the `select` itself stays on the intl
     * path.
     *
     * @param string $text
     * @param array $missing Argument names, keyed by name
     * @param array $params
     * @param string|null $locale
     * @param int $depth
     * @param array $recovered Argument name => recovered rendering, added to
     * @param string $prefix
     * @return string
     */
    protected function recoverMissingNodes($text, array $missing, array $params, $locale, $depth = 0, array &$recovered = [], $prefix = 'langsys_recovered_')
    {
        if ($depth > self::MAX_ICU_DEPTH) {
            return $text;
        }

        // missingIcuArguments() returns a LIST of names; flip it so membership
        // is a key test rather than a scan.
        $missingByName = array_flip($missing);

        $out = '';
        $length = strlen($text);
        $i = 0;

        while ($i < $length) {
            if ($text[$i] !== '{') {
                $out .= $text[$i];
                $i++;
                continue;
            }

            $end = $this->matchingBrace($text, $i);

            if ($end === null) {
                // Unbalanced - emit verbatim rather than looping forever.
                $out .= substr($text, $i);
                break;
            }

            $body = substr($text, $i + 1, $end - $i - 1);
            $name = null;

            if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*,\s*(?:plural|select|selectordinal|number|date|time)\s*[,}]?/', $body, $m)) {
                $name = $m[1];
            }

            if ($name !== null && isset($missingByName[$name])) {
                // Missing: this node's recovered rendering, as an argument's
                // value. Any `#` inside a recovered plural is the literal
                // {argName}, the visible gap the rule asks for.
                $argument = $prefix . count($recovered);
                $recovered[$argument] = $this->renderIcuArgument($body, $params, $locale, $depth);
                $out .= '{' . $argument . '}';
            } else {
                // Supplied, or not branch-typed: keep it verbatim for intl, but
                // recover anything missing nested inside it.
                $out .= '{' . $this->recoverMissingNodes($body, $missing, $params, $locale, $depth + 1, $recovered, $prefix) . '}';
            }

            $i = $end + 1;
        }

        return $out;
    }

    /**
     * A prefix for recovered nodes' argument names that neither the phrase
     * nor a param holds.
     *
     * @param string $text
     * @param array $params
     * @return string
     */
    protected function recoveredArgumentPrefix($text, array $params)
    {
        $prefix = 'langsys_recovered_';
        $taken = $text . "\0" . implode("\0", array_map('strval', array_keys($params)));

        while (strpos($taken, $prefix) !== false) {
            $prefix = 'x' . $prefix;
        }

        return $prefix;
    }

    /**
     * Whether the pattern gives an argument a type anywhere, as in
     * `{d, date}`, rather than only naming it as a plain `{d}`.
     *
     * @param string $text
     * @param string|int $name
     * @return bool
     */
    protected function isTypedArgument($text, $name)
    {
        // ICU's Pattern_White_Space, not PCRE's \s: a right-to-left mark
        // between a name and its comma (common in Arabic and Hebrew
        // translations) still types the argument. Missing it would format a
        // plural's count into a string and select the wrong branch.
        $name = preg_quote((string) $name, '/');
        $space = '[\s\x{85}\x{200E}\x{200F}\x{2028}\x{2029}]*';
        $typed = @preg_match('/\{' . $space . $name . $space . ',/u', $text);

        // Text that is not valid UTF-8 cannot be read with /u: read its bytes.
        if ($typed === false) {
            $typed = preg_match('/\{\s*' . $name . '\s*,/', $text);
        }

        return $typed === 1;
    }

    /**
     * Emit one debug notice per recovering render, naming every defaulted
     * argument and the locale.
     *
     * A recovered argument never appears in the source phrase, so there is
     * nothing for the developer to grep for and no failing behaviour to notice -
     * the page renders, reads plausibly, and is subtly wrong. A behaviour with
     * no observable trace is undiscoverable by construction.
     *
     * Deduped on locale + template so a recovering phrase rendered on every
     * request produces one line rather than a flood.
     *
     * @param array $missing Argument names, keyed by name
     * @param string $text
     * @param string|null $locale
     * @return void
     */
    protected function noteDefaultedArguments(array $missing, $text, $locale)
    {
        $key = $this->resolveLocale($locale) . "\0" . $text;

        if (isset($this->notedRecoveries[$key])) {
            return;
        }

        $this->notedRecoveries[$key] = true;

        $this->log(
            'debug',
            'ICU arguments were not supplied and their default branches were used. '
            . 'The source phrase not asking for these arguments is NORMAL - a target '
            . 'locale can require a distinction the source does not make. To choose '
            . 'the branch yourself, pass the value in params.',
            [
                'defaulted_arguments' => array_values($missing),
                'locale' => $this->resolveLocale($locale),
                'phrase' => $text,
            ]
        );
    }

    /**
     * ICU argument names referenced by the pattern but absent from $params.
     *
     * @param string $text
     * @param array $params
     * @return array
     */
    protected function missingIcuArguments($text, array $params)
    {
        if (!preg_match_all('/\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*,\s*(?:plural|select|selectordinal|number|date|time)\s*[,}]/', $text, $m)) {
            return [];
        }

        $missing = [];

        foreach ($m[1] as $name) {
            if (!array_key_exists($name, $params) || $params[$name] === null) {
                $missing[$name] = $name;
            }
        }

        return array_values($missing);
    }

    /**
     * Best-effort ICU rendering without ext-intl.
     *
     * Produces a READABLE SENTENCE rather than raw MessageFormat source. It is
     * not CLDR-correct - that is precisely why ext-intl is a hard requirement -
     * but every language ends up with prose instead of visible markup.
     *
     * Branch selection, in order:
     *   1. An exact `=N` branch matching the value (standards-correct).
     *   2. `one` when the value is exactly 1 AND the translator supplied a
     *      `one` branch. Across CLDR languages that define `one`, n=1 falls in
     *      it, so this is right far more often than `other` would be.
     *   3. `other`, the category every plural form is required to provide.
     *
     * For `select`, the branch matching the parameter value is used, else
     * `other`. `#` inside a plural's chosen branch becomes the formatted value;
     * inside a select's it is text.
     *
     * @param string $text
     * @param array $params
     * @param string|null $locale
     * @return string
     */
    protected function renderIcuWithoutIntl($text, array $params, $locale, $hash = null, $depth = 0)
    {
        // Bail on pathological nesting rather than exhausting memory. Catalog
        // content is not caller-controlled, but a deeply nested pattern would
        // otherwise take the whole render down with an uncatchable fatal.
        if ($depth > self::MAX_ICU_DEPTH) {
            return $text;
        }

        $out = '';
        $length = strlen($text);
        $i = 0;

        while ($i < $length) {
            $char = $text[$i];

            // '#' is the enclosing plural's value. Emitted HERE during the walk
            // rather than substituted into the branch before recursing: doing it
            // by substitution let a value that itself looked like ICU be
            // re-scanned (unbounded recursion), and let an outer '#' overwrite a
            // nested plural's own '#'.
            if ($char === '#' && $hash !== null) {
                $out .= $hash;
                $i++;
                continue;
            }

            if ($char !== '{') {
                $out .= $char;
                $i++;
                continue;
            }

            $end = $this->matchingBrace($text, $i);

            if ($end === null) {
                // Unbalanced - emit verbatim rather than looping forever.
                $out .= substr($text, $i);
                break;
            }

            $out .= $this->renderIcuArgument(
                substr($text, $i + 1, $end - $i - 1),
                $params,
                $locale,
                $depth
            );

            $i = $end + 1;
        }

        return $out;
    }

    /**
     * Render one ICU argument body (the text between its outer braces).
     *
     * @param string $body
     * @param array $params
     * @param string|null $locale
     * @return string
     */
    protected function renderIcuArgument($body, array $params, $locale, $depth = 0)
    {
        $verbatim = '{' . $body . '}';

        $comma = strpos($body, ',');

        // No comma: an ordinary {key} placeholder.
        if ($comma === false) {
            $key = trim($body);

            if ($key === '' || !array_key_exists($key, $params) || $params[$key] === null) {
                return $verbatim;
            }

            $formatted = $this->formatValue($params[$key], $locale);

            return $formatted === null ? $verbatim : $formatted;
        }

        $name = trim(substr($body, 0, $comma));
        $rest = substr($body, $comma + 1);

        $secondComma = strpos($rest, ',');
        $type = strtolower(trim($secondComma === false ? $rest : substr($rest, 0, $secondComma)));

        $missing = !array_key_exists($name, $params) || $params[$name] === null;

        // A missing {v, number}, {d, date} or {t, time} is the visible {v}
        // (ICU-3), never the construct.
        if ($missing && in_array($type, ['number', 'date', 'time'], true)) {
            return '{' . $name . '}';
        }

        if ($missing && !in_array($type, ['plural', 'selectordinal', 'select'], true)) {
            return $verbatim; // An unknown type: nothing sensible to render.
        }

        $value = $missing ? null : $params[$name];

        // Style-less forms: {v, number}, {d, date}, {t, time}.
        if (in_array($type, ['number', 'date', 'time'], true)) {
            $formatted = $this->formatValue($value, $locale);

            return $formatted === null ? $verbatim : $formatted;
        }

        if (!in_array($type, ['plural', 'selectordinal', 'select'], true)) {
            return $verbatim;
        }

        if ($secondComma === false) {
            return $verbatim; // Declared a branch type but supplied no branches.
        }

        $branches = $this->parseIcuBranches(substr($rest, $secondComma + 1));

        if (empty($branches)) {
            return $verbatim;
        }

        // A missing argument selects `other`, which every plural and select is
        // required to provide. For select that yields a CORRECT sentence - the
        // neutral branch is exactly what an unknown gender should render. For
        // plural nothing can be inferred, so the count itself stays visible as
        // {name} while the sentence around it survives, which beats both
        // destroying the sentence and dumping the pattern.
        $chosen = $missing
            ? (isset($branches['other']) ? $branches['other'] : null)
            : $this->chooseIcuBranch($type, $value, $branches, $locale);

        if ($chosen === null) {
            return $verbatim;
        }

        // A plural's branch is walked with THIS argument's value as its '#'. A
        // nested plural inside it supplies its own, so the outer value cannot
        // clobber the inner one, and a value that happens to contain '#' or
        // braces is never re-scanned. In a select's branch '#' is text, as ICU
        // reads it, the select nested in a plural or not.
        $hash = null;
        if ($type !== 'select') {
            $number = $missing ? '{' . $name . '}' : $this->formatValue($value, $locale);
            $hash = $number === null ? '' : $number;
        }

        return $this->renderIcuWithoutIntl(
            $chosen,
            $params,
            $locale,
            $hash,
            $depth + 1
        );
    }

    /**
     * Split an ICU branch list into keyword => content.
     *
     * @param string $text e.g. "one {# item} other {# items}"
     * @return array
     */
    protected function parseIcuBranches($text)
    {
        $branches = [];
        $length = strlen($text);
        $i = 0;

        while ($i < $length) {
            // Read the keyword up to its opening brace.
            while ($i < $length && $text[$i] !== '{') {
                $i++;
            }

            if ($i >= $length) {
                break;
            }

            $keyword = trim(substr($text, 0, $i));

            // A plural may carry an `offset:N` prefix before its branches;
            // without stripping it the first branch is keyed "offset:1 one".
            if (preg_match('/^offset\s*:\s*\S+\s+(.*)$/s', $keyword, $m)) {
                $keyword = trim($m[1]);
            }

            $end = $this->matchingBrace($text, $i);

            if ($end === null) {
                break;
            }

            if ($keyword !== '') {
                $branches[$keyword] = substr($text, $i + 1, $end - $i - 1);
            }

            // Continue with whatever follows this branch.
            $text = substr($text, $end + 1);
            $length = strlen($text);
            $i = 0;
        }

        return $branches;
    }

    /**
     * Pick a branch without CLDR plural rules.
     *
     * @param string $type
     * @param mixed $value
     * @param array $branches
     * @return string|null
     */
    protected function chooseIcuBranch($type, $value, array $branches, $locale = null)
    {
        if ($type === 'select') {
            $key = is_scalar($value) ? (string) $value : '';

            if (isset($branches[$key])) {
                return $branches[$key];
            }

            return isset($branches['other']) ? $branches['other'] : null;
        }

        // Exact matches are standards-correct and always safe.
        if (is_numeric($value)) {
            $number = (float) $value;

            foreach ($branches as $keyword => $content) {
                // Compare numerically so "=1" matches 1, "1.0" and "1".
                if (strlen($keyword) > 1 && $keyword[0] === '='
                    && is_numeric(substr($keyword, 1))
                    && (float) substr($keyword, 1) === $number) {
                    return $content;
                }
            }

            // The value's CLDR category in the render locale, where intl can
            // compute it.
            $category = $this->pluralCategory($type, $value, $locale);

            if ($category !== null) {
                return isset($branches[$category]) ? $branches[$category]
                    : (isset($branches['other']) ? $branches['other'] : reset($branches));
            }

            // Loose compare: 1, 1.0 and "1.0" must all take the `one` branch.
            if ($number === 1.0 && isset($branches['one'])) {
                return $branches['one'];
            }
        }

        if (isset($branches['other'])) {
            return $branches['other'];
        }

        // No 'other' (technically invalid ICU) - take whatever exists.
        return reset($branches) === false ? null : reset($branches);
    }

    /**
     * The CLDR plural (or ordinal) category of a number in a locale, asked of
     * intl through a pattern whose branches name their own category - PHP's
     * intl exposes no plural-rules class. Null without intl, or when intl
     * cannot answer.
     *
     * @param string $type plural or selectordinal
     * @param mixed $value
     * @param string|null $locale
     * @return string|null
     */
    protected function pluralCategory($type, $value, $locale)
    {
        if (!$this->hasIntl()) {
            return null;
        }

        $kind = $type === 'selectordinal' ? 'selectordinal' : 'plural';
        $probe = '{n, ' . $kind . ', zero {zero} one {one} two {two} few {few} many {many} other {other}}';

        try {
            $formatter = @\MessageFormatter::create($this->resolveLocale($locale), $probe);
            $category = $formatter ? @$formatter->format(['n' => $value + 0]) : false;
        } catch (\Throwable $e) {
            return null;
        }

        return is_string($category) && $category !== '' ? $category : null;
    }

    /**
     * Find the '}' matching the '{' at $start, honouring nesting.
     *
     * @param string $text
     * @param int $start
     * @return int|null
     */
    protected function matchingBrace($text, $start)
    {
        $depth = 0;
        $length = strlen($text);

        for ($i = $start; $i < $length; $i++) {
            if ($text[$i] === '{') {
                $depth++;
                continue;
            }

            if ($text[$i] === '}') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * Replace simple {key} placeholders.
     *
     * Uses preg_replace_callback rather than preg_replace on purpose: with a
     * replacement STRING, PHP interprets $1 / \1 inside the replacement as
     * backreferences, so any translation or parameter value containing a dollar
     * sign (a price like "$5", or a stray "$&") gets mangled. Returning the
     * value from a callback sidesteps that entirely.
     *
     * @param string $text
     * @param array $params
     * @param string|null $locale
     * @return string
     */
    /**
     * Rewrite `%name%` to `{name}` for keys the caller actually supplied.
     *
     * Only supplied keys are rewritten, and that is the safety property: text
     * containing a percent span that is not a placeholder - "Save 20% on 5%
     * APR", a CSS width, a printf-style template someone pasted - has no
     * matching parameter, so it is left exactly as written. An unconditional
     * rewrite would convert prose into placeholders and then leave `{...}`
     * visible on the page when nothing filled it.
     *
     * @param string $text
     * @param array $params
     * @return string
     */
    protected function normalizePercentPlaceholders($text, array $params)
    {
        if (empty($params) || strpos($text, '%') === false) {
            return $text;
        }

        $result = preg_replace_callback(
            self::PERCENT_PLACEHOLDER_PATTERN,
            function ($matches) use ($params) {
                return array_key_exists($matches[1], $params)
                    ? '{' . $matches[1] . '}'
                    : $matches[0];
            },
            $text
        );

        return $result === null ? $text : $result;
    }

    protected function substitute($text, array $params, $locale)
    {
        $self = $this;

        $result = preg_replace_callback(
            self::PLACEHOLDER_PATTERN,
            function ($matches) use ($params, $locale, $self) {
                $key = trim($matches[1]);

                if ($key === '' || !array_key_exists($key, $params)) {
                    return $matches[0]; // Unknown key - leave verbatim.
                }

                $value = $params[$key];

                if ($value === null) {
                    return $matches[0]; // Present but null - also left visible.
                }

                $formatted = $self->formatValue($value, $locale);

                return $formatted === null ? $matches[0] : $formatted;
            },
            $text
        );

        // preg_replace_callback returns null on PCRE failure (e.g. backtrack
        // limit on a pathological string); never hand back null to a renderer.
        return $result === null ? $text : $result;
    }

    /**
     * Format a single parameter value for output.
     *
     * Public so the substitution callback can reach it on PHP 7.4 without
     * binding gymnastics.
     *
     * @param mixed $value
     * @param string|null $locale
     * @return string|null Null when the value has no sensible string form
     */
    public function formatValue($value, $locale = null)
    {
        // Strings pass through untouched - this is the documented opt-out from
        // locale number formatting.
        if (is_string($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return $this->formatNumber($value, $locale);
        }

        if ($value instanceof \DateTimeInterface) {
            return $this->formatDate($value, $locale);
        }

        // An enum renders as Laravel's enum_value() reads it: a backed enum by
        // its value, a plain one by its name. Before PHP 8.1 neither interface
        // exists and both checks are false.
        if ($value instanceof \BackedEnum) {
            return $this->formatValue($value->value, $locale);
        }

        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        // Arrays, resources, objects without __toString: no sensible rendering.
        return null;
    }

    /**
     * Format a number per the locale's CLDR rules.
     *
     * @param int|float $value
     * @param string|null $locale
     * @return string
     */
    protected function formatNumber($value, $locale)
    {
        if ($this->plainNumbers || !$this->hasIntl()) {
            return (string) $value;
        }

        try {
            $formatter = @\NumberFormatter::create($this->resolveLocale($locale), \NumberFormatter::DECIMAL);

            // Returns null on some versions, false on others.
            if (!$formatter) {
                return (string) $value;
            }

            $formatted = @$formatter->format($value);

            return $formatted === false ? (string) $value : $formatted;
        } catch (\Throwable $e) {
            return (string) $value;
        }
    }

    /**
     * Format a date with the locale's medium date style.
     *
     * @param \DateTimeInterface $value
     * @param string|null $locale
     * @return string
     */
    protected function formatDate(\DateTimeInterface $value, $locale)
    {
        if (!$this->hasIntl()) {
            return $value->format('Y-m-d');
        }

        // IntlDateFormatter's CONSTRUCTOR throws on PHP 8+ for a bad locale;
        // @ cannot suppress an exception, so this must be caught explicitly.
        try {
            $formatter = new \IntlDateFormatter(
                $this->resolveLocale($locale),
                \IntlDateFormatter::MEDIUM,
                \IntlDateFormatter::NONE
            );

            $formatted = @$formatter->format($value);

            return $formatted === false ? $value->format('Y-m-d') : $formatted;
        } catch (\Throwable $e) {
            return $value->format('Y-m-d');
        }
    }

    /**
     * Convert a value into something MessageFormatter accepts.
     *
     * @param mixed $value
     * @return mixed
     */
    protected function toIcuValue($value)
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return $value;
    }

    /**
     * @param string|null $locale
     * @return string
     */
    protected function resolveLocale($locale)
    {
        if (!is_string($locale) || trim($locale) === '') {
            return self::FALLBACK_LOCALE;
        }

        return $locale;
    }

    /**
     * @return bool
     */
    protected function hasIntl()
    {
        return class_exists('\MessageFormatter');
    }

    /**
     * Warn once per instance that ICU content cannot be formatted.
     *
     * @param string $text
     * @return void
     */
    protected function warnMissingIntl($text)
    {
        if ($this->warnedMissingIntl) {
            return;
        }

        $this->warnedMissingIntl = true;

        $this->log('warning', 'Phrase uses ICU MessageFormat but ext-intl is not loaded; plural and select rules will not be applied', [
            'phrase' => $text,
            'missing_extension' => 'intl',
            'fallback' => 'simple placeholder substitution',
        ]);
    }

    /**
     * @param string $level
     * @param string $message
     * @param array $context
     * @return void
     */
    protected function log($level, $message, array $context = [])
    {
        if ($this->logger instanceof LoggerInterface) {
            $this->logger->log($level, $message, $context);
        }
    }
}
