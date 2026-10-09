# Changelog

All notable changes to the Langsys PHP SDK are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- **Missing credentials no longer throw from the constructor.** A client built
  without an API key or project ID is now *unconfigured*: `translate()`,
  `translateContentBlock()` and `translatePage()` return the source text with
  placeholders resolved, nothing is fetched or queued for registration, and one
  warning per process names what to set (`LANGSYS_API_KEY`,
  `LANGSYS_PROJECT_ID`). Throwing took down whatever built the client - a
  Laravel app 500'd on every page until its `.env` was filled in. Explicit
  writes (`registerPhrases()`, `registerContentBlock()`) still throw, with a
  message that says the client is not configured. `isConfigured()` reports the
  state. Pass `'require_credentials' => true` to keep the old behaviour.

## [1.3.1] - 2026-08-16

### Fixed

- **A missing ICU argument destroyed the sentence and shipped a bare placeholder
  to end users.** `MessageFormatter` does not throw or return `false` for a
  well-formed pattern whose argument is absent — it echoes `{argName}` — so the
  ICU path "succeeded", the malformed-ICU fallback was never reached, and:

  ```
  {name_gender, select, male {Bienvenido} female {Bienvenida} other {Bienvenide}} {name}
    with ['name' => 'Sarah']   ->  '{name_gender} Sarah'      the sentence is gone
  {count, plural, one {# item} other {# items}}
    with ['name' => 'Sarah']   ->  '{count}'
  ```

  **This is reachable without any caller error.** The backend promotes a plain
  `{name}` into `{name_gender, select, …}` for gendered target locales, so the
  argument does not exist in the source phrase the developer wrote and nothing
  tells them the target grew one. Every app translating into a gendered locale
  hits it.

  A missing argument now selects the `other` branch, which every `plural` and
  `select` is required to provide:

  ```
  select  ->  'Bienvenide Sarah'    a CORRECT sentence; `other` is exactly what
                                    an unknown gender should render
  plural  ->  '{count} items'       nothing can be inferred for a count, so it
                                    stays visible while the sentence survives
  ```

  Note the asymmetry: `select` is recoverable, `plural` is only made less bad.
  Both beat destroying the sentence.

  Behaviour is now identical with and without `ext-intl` — the two paths
  previously produced two *different* broken outputs for the same input.

  Reported by the Laravel wrapper, from an unmerged branch by a human colleague
  (giancapra) that patched the wrapper's own Interpolator; that file was deleted
  when the wrapper consolidated onto this SDK, so the fix had nowhere to land.

## [1.3.0] - 2026-08-16

### Changed

- **`translatePage()` no longer registers mid-render.** It issued blocking HTTP
  calls while the user waited: one POST for the phrases, **one POST per new
  content block**, then a cache clear and a refetch. A page with eight new blocks
  blocked on ten round trips before a byte was sent, while `translate()` — the
  other entry point to the same catalog — made none.

  Page registration now routes through the same pending queue as `translate()`,
  flushed by the shutdown handler or by a host framework's post-response hook
  (Laravel terminable middleware, Octane `RequestTerminated`). Measured on a page
  with four new content blocks and four new phrases: **0 requests during render**
  (was 5 POSTs plus a GET), and **2 batched POSTs at flush** (was 5).

  The per-block N+1 disappears with it — `flushPendingRegistrations()` already
  batched content blocks through `createContentBlocks()`; only the inline path
  sent them one at a time.

  **Behaviour change:** a page no longer picks up translations registered by an
  *earlier* request within the same response, because the post-registration
  refetch is gone. Those appear on the next request instead. The refetch could
  never help the items it was sequenced against — freshly registered phrases have
  no translations yet.

- `Client::queuePhraseForRegistration()` and `queueContentBlockForRegistration()`
  are now public, so `PageTranslator` uses the same queue rather than a parallel
  path.

### Added

- `tests/Html/PageRegistrationTest.php` — `translatePage()`'s registration path
  previously had no test at all, despite being the route by which a whole page
  reaches the shared catalog.

## [1.2.0] - 2026-08-16

### Fixed

- **`data-notrans` did the opposite of protecting content.** The check tested the
  raw attribute string for PHP truthiness, which inverted both ends: a bare
  `data-notrans` is `''` and therefore falsy, so the natural form silently did
  nothing and the content was extracted and **registered into the shared
  catalog**; while every explicit value was a non-empty string and therefore
  truthy, so even `data-notrans="false"` excluded. There was no value an author
  could write to opt back in.

  Now: presence is intent, only `"false"` or `"0"` opts out, trimmed and
  case-insensitive. `translate="no"` is case-insensitive too.

- Whitespace defeated the opt-out on `data-langsys-phrase` and
  `data-langsys-contentblock`: `=" false "` enabled the marker rather than
  opting out.

### Changed

- **All three markers now follow one rule.** `data-langsys-phrase`,
  `data-langsys-contentblock` and `data-notrans` are enabled by presence alone
  and disabled only by an explicit `"false"`/`"0"`, trimmed and case-insensitive.

  **Behaviour change:** a bare or empty `data-langsys-contentblock` previously
  did nothing, because its contract required a non-empty value. It now enables
  the marker. This removes the last silent no-op among the markers — the same
  failure shape as the `data-notrans` bug, where a marker looks applied and
  isn't. Anyone relying on an empty value being ignored should remove the
  attribute instead.

### Added

- `HtmlParser::isTranslationExcluded()`, `isPhraseMarked()` and
  `isContentBlockMarked()` — public statics carrying the single definition of
  each rule. These previously lived as duplicated private logic across five call
  sites with three different semantics, and one copy was dead. The JS SDK mirrors
  them under matching names.
- **`tests/fixtures/tokenizer-reference.json`** — HTML fragment in, expected
  token list out, plus the resulting `custom_id`. The existing id fixtures pin
  the hash but take synthetic token lists, so a divergence in how two SDKs derive
  tokens from HTML passed them silently. That was live: the JS SDK harvested every
  `<option>` twice, giving different ids for any content block containing a
  `<select>` while both suites were green.
- Both exclusion attributes are documented for the first time, including that
  they are the supported way to tell `translatePage()` a subtree is already
  translated.

## [1.1.0] - 2026-08-14

### Security

- **`data-langsys-phrase` let the translation catalog rewrite `<script>` and
  `<style>` bodies.** Their contents were encoded into the phrase, so inline JS
  and CSS were registered to the shared catalog (billed, and potentially leaking
  inline config), `translate="no"` was silently overridden, and a translation for
  a `<script>` body was applied straight back into the element — making the
  catalog a script-injection vector for any page using the marker.

  Opaque subtrees (`script`, `style`, `noscript`, `template`, `svg`, `math`, and
  anything marked `translate="no"` / `data-notrans`) are now preserved verbatim
  and contribute nothing to the phrase. Introduced and fixed before release; no
  tagged version shipped it.

### Added

- **`data-langsys-phrase` — keep a run of inline markup as ONE phrase.** Page
  translation splits at tag boundaries, so
  `<p>Based on {n} <strong>reviews</strong></p>` produced the separate entries
  `"Based on {n}"` and `"reviews"` — putting the count in a different catalog
  entry from the noun it inflects. No ICU plural rule can reach across that
  boundary, making a correct translation impossible in Russian, Arabic or Polish.

  Marked elements register as a single phrase carrying `{m0o}`/`{m0c}` markup
  tokens, the same wire format as the JS SDK's `<Phrase>`, so entries are
  consumable by either SDK. Translators may reorder the tokens and the markup is
  rebuilt where they end up; element attributes are preserved. Tokens are valid
  ICU argument names, so a marked phrase can carry a plural.

  Dropped, unbalanced, crossed or unknown-index tokens render the text without
  markup rather than failing, and a token naming a nonexistent slot is stripped
  rather than shipped to the browser.

### Fixed

- **Without `ext-intl`, ICU phrases rendered their raw MessageFormat source to
  end users.** Simple substitution cannot match a construct containing commas
  and nested braces, so a phrase like
  `{n, plural, one {# товар} few {# товара} other {# товаров}}` was emitted
  verbatim into the page — worse output than an untranslated phrase, which at
  least reads as a sentence.

  ICU phrases now degrade to a readable sentence. Branch selection is: an exact
  `=N` branch when one matches (standards-correct), then `one` when the value is
  exactly 1 and the translator supplied a `one` branch, then `other`. `select`
  matches the parameter value, falling back to `other`. `#` becomes the value,
  and nested placeholders inside the chosen branch still resolve.

  This is **not** CLDR-correct — that is exactly why `ext-intl` is required —
  but every language now produces prose rather than visible markup. Behaviour
  with `ext-intl` present is unchanged, and a malformed pattern is still emitted
  verbatim, matching the JS SDK.

- **Content blocks containing invalid UTF-8 all collapsed onto one `custom_id`.**
  `json_encode()` returns `false` on invalid UTF-8 and `md5(false)` is `md5('')`,
  so every such block shared a single id. Falls back to a serialization that
  cannot fail.

- Without `ext-intl`, a parameter value that itself looked like ICU caused
  unbounded recursion and an uncatchable memory-exhaustion fatal; an outer `#`
  also overwrote a nested plural's own value. Values are no longer re-scanned and
  each nesting level keeps its own `#`. Deeply nested patterns are bounded.

- Without `ext-intl`, the `one` branch was missed for `1.0` and `"1.0"`, and an
  `offset:N` prefix mis-keyed the first branch.

### Known limitations

- Without `ext-intl`, ICU **apostrophe quoting** is not handled: a translation
  using `'{'` as a literal desynchronises brace matching and falls back to
  emitting the pattern verbatim, and `''` is not unescaped. Only affects hosts
  missing the required extension.

- `data-langsys-phrase` is a `translatePage()` feature. Inside a content block a
  marked run still splits at tag boundaries, because content blocks are applied
  by a path with no tokenized branch.

- Content block `custom_id`s match the JS SDKs **only when paired with
  `langsys-js-typescript` 0.6.0 or later**. Below that version the JS SDK hashed
  UTF-16 code units rather than UTF-8 bytes, so any non-ASCII character produced
  a different id for the same block (and could collide two distinct blocks onto
  one id). Fixed on the JS side in 0.6.0; this SDK's ids were always the correct
  values and did not change. Verified against the published 0.6.0 package using
  `tests/fixtures/custom-id-reference.json`: 12/12.

## [1.0.2] - 2026-08-12

### Fixed

- **Content block `custom_id`s did not match the JS SDKs.** PHP hashed
  `md5('category|phrase1|phrase2')` while the JS SDKs hash
  `md5(JSON.stringify([category, tokens]))`, so the *same* content block
  registered under two different ids depending on which SDK saw it first —
  producing duplicate entries in the catalog every Langsys SDK shares.

  `generateCustomId()` now JSON-encodes with `JSON_UNESCAPED_SLASHES |
  JSON_UNESCAPED_UNICODE`, which is byte-identical to `JSON.stringify`, and
  normalises the reserved `__uncategorized__` sentinel and `null` to `''` — the
  JS side passes a raw category and never the sentinel. That also reconciles the
  two PHP callers, which previously defaulted differently
  (`translateContentBlock()` to `__uncategorized__`, `createContentBlock()` to
  `null`) and so disagreed with each other.

  **Upgrade note:** existing content blocks will be assigned new ids and
  re-register on first use. Previously-registered blocks under the old ids are
  orphaned and can be removed from the Translation Manager.

- **`Utilities::getLocaleData()` returned an empty array on a locale-code format
  mismatch.** The API keys its response by the display locale formatted in the
  organisation's configured `locale_code_format`, which need not match the string
  we sent (`en-US` vs `en-us`). Since a single display locale is requested, the
  only entry is now taken regardless of key formatting.

### Internal

- Tests no longer hardcode `md5()` content-block ids; they derive them via
  `generateCustomId()` so they survive a change to the hashing scheme.

## [1.0.1] - 2026-08-11

### Fixed

- **The ext-intl warning became a FATAL error under Laravel.** The runtime
  requirement guard used `trigger_error(E_USER_WARNING)`, which routes through
  the installed error handler. Laravel's `HandleExceptions` converts PHP errors
  into thrown `ErrorException`s, so on any Laravel host without `ext-intl`,
  **constructing the Client threw instead of degrading** — inverting the entire
  intent of a guard that exists to keep renders working. Now uses `error_log()`,
  which cannot be intercepted or escalated by any error handler. Reported by the
  Laravel wrapper with a reproducing stack trace.
- **`translatePage()` never interpolated the `<head>`.** `<title>`, meta
  description, `og:*` and `twitter:*` shipped raw `{name}` placeholders to the
  browser while the body resolved them correctly.
- **`LocaleDetector` treated `q=0` as acceptable.** RFC 7231 defines `q=0` as
  "not acceptable", but `Accept-Language: de;q=0` resolved to `de-de`. A `q` that
  is malformed (`q=abc`) or out of range (`q=1.5`) now discards that entry rather
  than silently defaulting it to `q=1`, which had promoted broken entries to top
  priority.
- **ICU calls could throw and break the render.** `MessageFormatter::create()`,
  `NumberFormatter::create()` and `new IntlDateFormatter()` can raise
  `IntlException` (and `@` does not suppress exceptions), violating this class's
  promise never to throw. All three are now guarded, as is the `false`-vs-`null`
  return difference between intl versions.
- `PageTranslator` no longer retains per-call params after `translatePage()`
  returns, including when it unwinds on an exception.

### Added

- `LocaleDetector::fromAcceptLanguage($header)` — same semantics as
  `fromBrowser()` but takes the header explicitly, so a framework can pass its
  own request header instead of faking `$_SERVER`. Requested by the Laravel wrapper.
- `warn_runtime_requirements` constructor option to silence the `error_log()` leg
  of the requirement warning (the SDK logger leg still fires).
- A CI job that runs the suite **without** `ext-intl`, so the graceful-degradation
  path is verified on CI rather than only on a developer machine that happens to
  lack the extension.

### Internal

- `tests/ClientRequirementsTest.php` restored PHP's error handler with
  `set_error_handler($previous)`, which *pushes* a handler rather than popping
  one. That silently disabled PHPUnit's error handler for every test that ran
  afterwards, so warnings across the rest of the suite were swallowed. Now uses
  `restore_error_handler()`.
- `SpyLogger` moved to `tests/Support/` so individual test files can be run on
  their own.

## [1.0.0] - 2026-08-10

First tagged release.

### Added

- **Placeholder interpolation** (`src/Format/Interpolator.php`). Translated
  strings can carry `{name}` placeholders, substituted at render time. The
  canonical stored form matches the Langsys JS SDKs, so a shared catalog renders
  identically from a PHP backend and a JS frontend.
  - `translate($phrase, $locale, $category, $contentBlockId, $params)`
  - `translateContentBlock($html, $category, $params)`
  - `translatePage($html, $category, $selectorCategories, $params)`
  - In HTML paths, placeholders resolve in text nodes **and** translatable
    attributes.
  - Unknown keys and `null` values are left verbatim so missing data stays
    visible; `{ name }` whitespace is tolerated; string values opt out of number
    formatting; numbers and dates format per the target locale.
  - Untranslated fallback text still interpolates, so newly-registered phrases
    never render a raw `{name}` to end users.
  - Registration always queues the **raw** placeholder-bearing phrase, so one
    catalog entry serves every runtime value.
- **ICU MessageFormat support** for plurals and select, giving correct plural
  categories per language (Russian's four, Arabic's six). Malformed ICU falls
  back to simple substitution rather than throwing.
- **Runtime requirement warnings** (`Client::checkRuntimeRequirements()`). Warns
  once per process when PHP is below 7.4 or `ext-intl` is missing. Reported
  through both the SDK logger and `trigger_error()`, because the manual
  `autoload.php` install typically has no log path configured and bypasses
  Composer's constraints entirely. Warns rather than throws — a missing
  `ext-intl` degrades to simple substitution instead of breaking the render.
- CI across PHP 7.4–8.4 with `ext-intl` loaded, so the ICU plural/select tests
  actually execute. `LANGSYS_REQUIRE_INTL` turns their `markTestSkipped` into a
  hard failure, preventing a green suite that silently skipped every plural
  assertion.

### Changed

- **BREAKING: minimum PHP version raised from 5.6 to 7.4.**
- **BREAKING: `ext-intl` is now a required extension**, needed for ICU plural
  rules and locale-aware number/date formatting.
- Dev dependency narrowed to PHPUnit ^9.0 (PHPUnit ^5.7 supported the old 5.6
  floor and is no longer relevant).

### Fixed

- **Browser locale detection was inconsistent between hosts.**
  `LocaleDetector::fromBrowser()` had two code paths that disagreed, and which
  one ran depended on whether `ext-intl` was loaded — so the same visitor could
  be served a different language on two otherwise identical deployments:

  | `Accept-Language` | without intl | with intl |
  |---|---|---|
  | `en` | `en-en` | `en` |
  | `en,es-MX;q=0.9` | `es-mx` | `en` |

  The non-intl path matched whichever locale-shaped substring appeared in the
  header regardless of priority, so `es-MX` at `q=0.9` beat `en` at an implicit
  `q=1`. The intl path handled priority correctly but returned a bare language
  code, which cannot match a project locale since the API addresses translations
  by `xx-yy` codes.

  Both paths now parse by quality value and then fill a missing region, so they
  produce identical results on every host. The fallback is a real
  `Accept-Language` parser rather than a substring match.

### Notes

- Building dynamic strings before translating — `translate(sprintf('Hello, %s!',
  $name))` — registers a new catalog phrase for every distinct runtime value and
  pollutes the catalog shared with every other Langsys SDK. Use `$params`
  instead. This cannot be detected at runtime; see README.
