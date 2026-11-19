# Changelog

All notable changes to the Langsys PHP SDK are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- **A legacy key prints its number params as plain digits** (spec MIG-4). In the
  migration mode, `© :year` with `['year' => 2026]` showed `© 2,026` in English and
  `© 2 026` in French, because every number param was formatted with the locale's
  grouping, while Laravel, Rails, gettext, i18next and vue-i18n print it as is. A key hit
  now prints `© 2026` in every locale, its translation too, and `#` and `{n, number}`
  likewise; a plural still selects by the number. A call that is not a key hit formats
  numbers for the locale as before. `Interpolator::interpolate()` takes the same
  behaviour as a fourth argument, `$plainNumbers`.

## [2.2.2] - 2026-11-09

### Fixed

- **A number in a plain `{n}` is locale-formatted in an ICU phrase too.** Once a
  translation held a `plural` or `select`, PHP's `MessageFormatter` printed an untyped
  number as written: `Ana zahlte 1234.5` in de-DE where the same `{n}` outside ICU gives
  `1.234,5`. It is now formatted like it is outside ICU. A typed argument
  (`{n, plural}`, `{n, number}`) stays a number, so plural selection and `#` are unchanged,
  and a string still passes through as written.

## [2.2.1] - 2026-11-09

### Fixed

- **A keyless dry run plans a renamed messages category's move.** `Planner::offline()` and
  `plan()` take `messages_category` and `messages_previous_category` options, and the
  messages category's previous one wins over the `previous_categories` entry for it, as
  `planSync()` already did from the Client. Before, an offline plan in CI left that move out,
  with any conflict or left-behind warning it causes, so `--strict` could pass offline and
  fail on the real sync. `planSync()` now passes the Client's settings through the same path.
- A move conflict's origin naming no category reads `app.php:3 => ''`, not `app.php:3 => `.

## [2.2.0] - 2026-11-09

### Added

- **A renamed category moves with one setting** (spec 8.7.1, MOVE-1). The Client option
  `previous_categories`, `['checkout' => 'header']` (`''` for no category), names
  categories you renamed. `translate()`, `resolve()` and `translateRich()` apply it to a call
  that names no move of its own, a key's group included, so a key in a renamed language file
  renders the old category's translation until the new one has its own. `planSync()` uses it
  as its default map (or takes a `previous_categories` option of its own), and moves every
  item registered under the category, the lines only the base-language file reaches included.
  Pages and content blocks still name moves per call.
- **A renamed messages category keeps its templates' translations** (MSG-6). Set
  `messages_previous_category` (env `LANGSYS_MESSAGES_PREVIOUS_CATEGORY`) to the old
  category. The listing command and a template's first emit register it under the new
  category with `previous_category`, and `translateMessage()` renders the old category's
  translation until the new one has its own. Whether a template is registered is still
  decided under the new category alone. It wins over a `previous_categories` entry for the
  messages category.
- **A sync reports calls that disagree on where an item moved from.**
  `SyncPlan::$moveConflicts` lists them, and `failsStrict()` is true. The first in file path
  and line order is sent, whatever order the hits arrive in.

## [2.1.0] - 2026-11-09

### Added

- **Content can move to another category and keep its translations** (spec 8.7.0,
  MOVE-1 to MOVE-5). Anywhere a category is accepted, pass
  `['category' => 'Checkout', 'previous_category' => 'Header']`: `translate()`,
  `resolve()`, `translateRich()`, `translateContentBlock()`, `registerContentBlock()`,
  `translatePage()`'s default category and its selector rules. On a page, use
  `data-langsys-previous-category` (or `data-ls-previous-category`). Sync hits take a
  `'previous_category'` key. `''` means the content had no category.
  - The previous category is sent as `previous_category` on an explicit registration and
    on the registration a miss or a sync triggers, and the first call that names one
    wins. It never enters a `custom_id` or a queue key.
  - While the new category has no translation, the previous category's renders. A host
    filled that way is stamped `data-ls-previous-category` (a phrase) or
    `data-ls-previous-contentblock` (a block).
  - `SyncPlan::$leftBehind` warns when another call still registers the same text under
    the old category: the move retires the old item for that call too.
  - It is an array rather than a new parameter, so subclasses that override `translate()`
    or `translatePage()` keep working. It needs the Langsys API v0.108.4 or later; an
    older API ignores it, and the content registers as new.

## [2.0.2] - 2026-11-06

### Fixed

- **The skill and the README say never to write ICU.** Write the sentence flat with its
  `{placeholders}`, `translate('You have {count} new messages', null, 'Inbox', null,
  ['count' => $count])`, and Langsys makes each language's plural and gender forms, the
  base language included. A sentence that says something different at zero is its own
  phrase, chosen in code. The skill's rules, its invariants and its interpolation page
  say it, and no example writes a plural or a gender select by hand.
- **The migration warning for a Laravel plural with ranges says to write flat phrases.**
  A value such as `{0} None|[1,19] Some|[20,*] Many`, whose ranges match no plural
  form, is registered as written with a warning that said to rewrite it as
  `{count, plural, ...}`. It now says to write the sentence once with `{count}`, and a
  range that says something different as its own phrase. That message is the only code
  change from 2.0.1.

## [2.0.1] - 2026-10-31

### Added

- **An agent skill ships in `skill/`.** `langsys-php` is the Langsys skill set's core skill
  for PHP, read after the entry skill `langsys` and before a framework's own skill. It
  covers the four translate calls and the `data-langsys-*` attributes, whether a request
  registers, the `Client`'s options, interpolation and ICU, value markers, server
  messages, key-based language files and sync, snapshots, the request's locale, the
  reference lists, caching and long-lived workers. `npx langsys-skill install` installs it
  from `vendor/langsys/langsys-php/skill/`. A test holds the names it uses to the code:
  methods, classes, options and the keys inside them, environment variables, commands and
  their flags, and links and the headings they point to.

## [2.0.0] - 2026-10-31

### Upgrading from 1.x

[UPGRADING.md](UPGRADING.md) says what to do about each of these.

- **Laravel apps stay on 1.x for now.** `langsys/langsys-php-laravel` 1.0.0 requires
  `langsys/langsys-php` `^1.3`, so an app that uses it stays on langsys-php 1.x until
  the Laravel package releases a version built on 2.0.0.
- **More extensions are declared.** `composer.json` requires `ext-dom`, `ext-libxml`,
  `ext-filter` and `ext-tokenizer` beside `ext-curl`, `ext-json` and `ext-intl`, so a
  host without one is refused at install rather than failing inside a render.
- **Some content registers under a new id.** The SDK now reads text the way the other
  Langsys SDKs do, so the same content gets the same id on every SDK. Content whose id
  moves:
  - text holding a non-breaking space, a line or paragraph separator, or an invisible
    control character;
  - a phrase written with `%name%`, now stored as `{name}`;
  - a content block holding `<script>`, `<style>`, `<template>`, `<noscript>`,
    `<plaintext>` or `<math>`, whose contents are no longer part of it;
  - a block holding text inside `<svg>`, which is translated now;
  - a block holding a `<textarea>` or `<title>` with markup in it;
  - a fragment that ends inside a start tag, such as `…<a title="Read more`, whose
    unfinished tag is left out, as a browser leaves it out;
  - a block holding an element marked `data-ls-phrase` or `data-ls-contentblock`,
    which is a unit of its own;
  - a one-phrase fragment such as `translateContentBlock('<p>Hello</p>')`, which is the
    phrase `Hello`;
  - on a page, a paragraph with a translatable attribute (`title`, `alt`, …), one block
    of the attribute and the text, and an `<img alt>`, `<input placeholder>` or inline
    element outside a paragraph, which registers now;
  - a `data-langsys-contentblock` whose value is not `true`, `1`, `yes`, `false` or
    `0`, which is the block's id;
  - a `data-langsys-phrase` run made only of `translate="no"` or `data-notrans`
    elements, which registers nothing;
  - a `<title>` or a translated `<meta>` `content` holding a line break, a run of
    spaces or a non-breaking space, or a `<meta>` `content` with an edge space, now
    collapsed and trimmed as page text is.

  Each such item registers once under its new id and is machine translated again; the
  page shows the source until then. Translations stay under the old entry, so re-apply
  human translations in the Translation Manager. Content filed under the older
  pipe-form ids is still found and is never registered again.
- **Whether a request registers is the API's decision.** `canWrite()` and every
  registration follow the `write_enabled` the API answers for that request; 1.x went by
  the key's type alone. An `ip_write` key registers from an address on its allow-list,
  where 1.x never registered with one, and registers nothing from any other address. A
  `write` key registers nothing when the API answers `write_enabled: false` or leaves
  the field out, where 1.x registered with it. A `write` or `ip_write` key the API answers
  without write permission (`write_enabled` false or absent) is reported once, as a warning. A request that writes asks the
  API once for its decision, when it flushes after the response, since a cached
  authorization holds none. `canWrite()` asks on its first call in a request and throws
  when the API cannot answer.
- **`flushPendingRegistrations()` reports `success: false` for work it did not do**,
  and names a `reason`: `not_write_enabled`, `catalog_unavailable`, `backing_off`,
  `decision_unavailable` or `send_failed`. A read key reports `not_write_enabled` on
  every request by design.
- **`getTranslations()` and `Translations::getTranslationMap()` throw** a
  `LangsysException` for a response that is not a catalog, where they returned `[]`;
  `getTranslations()` also throws while a failed fetch's backoff window is open. They return a plural Langsys generated for
  a target locale as ICU MessageFormat, where they returned its `other` branch.
- **`new Client()` without an API key or project ID no longer throws**: it builds an
  unconfigured client. Pass `'require_credentials' => true` to throw at first use.
- **Rendered HTML carries more.** `translatePage()` and `translateContentBlock()` mark
  each rendered block with `data-ls-contentblock="<id>"`, and a page rendered in a
  locale other than the base carries `data-ls-resolved="<locale>"` on `<html>`. A
  response whose locale the SDK chose carries a `Vary` naming every request field the
  choice read, unless `send_vary` is `false`.
- **Warnings and errors go to PHP's error log** when no `log_path` is set;
  `'error_log' => false` turns that off.
- **Subclasses**: several protected methods take new optional parameters,
  `Interpolator::formatIcu()` returns `false` when the formatter fails on a pattern,
  `PageTranslator::markItemsAsRegistered()` and `markItemsAsRegisteredWithCategory()`
  are gone, and `Client` has a destructor.

### What's new

- **Long-lived workers.** `resetRequestState()` clears what belongs to one request:
  the write decision, the queue, the catalogs read and the locale, one set with
  `setLocale()` included.
- **A client without credentials renders source text**, with its values filled, and
  sends nothing; an explicit write throws `ConfigurationException`, and
  `isConfigured()` reports the state.
- **The request locale is chosen for you**: from the URL, then a `locale` cookie or
  session value, then `Accept-Language`, always one the project serves, else the base
  locale. A locale the framework already resolved, passed as `framework`, wins.
  `resolveRequestLocale()` exposes the decision.
- **Reference lists on the client.** `getCurrencies()`, `getCountries()`,
  `getDialCodes()` and `getLocalesFormat($format)` answer each list named in a display
  locale, the current one by default. A list is held per display locale, in memory and
  in the cache for the catalog's lifetime, so later requests send nothing for it. A
  locale list answers the display locale's own entry, every code written `es-cr`. A
  display locale not shaped as a locale is refused and nothing is sent. A list the API
  cannot give is empty, with the cause logged, never an exception, and is not asked for
  again for a window of 3 seconds, doubling to 5 minutes. A cache that fails is a miss.
- **Values marked as variables.** `<!--ls:NAME-->VALUE<!--/ls-->` and
  `<span data-ls-param="NAME">VALUE</span>` read as `{NAME}`, so a sentence registers
  once for every user, and render back inside their marker, with every attribute it
  was written with. A plural or select chooses on the value. Each unit takes only the
  values of the text it registered from. A line rendered as a string, such as a
  language file's, writes a value back escaped, and inside an attribute as the value
  alone, so a value never opens an attribute.
- **Enum params.** A backed enum renders as its value and a plain enum as its name, in
  a placeholder and as an ICU argument.
- **Migrating from key-based translation files.** The `migration` option resolves a
  `translate()` argument as a key in your source-language files (`laravel`,
  `vue-i18n`, `i18next` or `plain`), converting placeholders and plurals. A key reads
  as Laravel reads it: a group file's key with a dot in it as written, and text as a
  package key only when shaped `namespace::group.item`. A file in a format this SDK
  does not read is refused when the configuration loads, however it is given, and a
  PHP file that fails to load is reported by `problems()` and resolves nothing.
  `importLegacyTranslations()` registers every key as `translate()` looks it up, with
  the translations you already have, stored as human translations.
- **Behind a framework's translate function**: `planSync()`/`applySync()` register
  from your source and language files, `runtime_registration => false` turns off
  registering at runtime, `useMissFallback()` answers a miss from your language files,
  given the line as the caller wrote it, `resolve()` says where text came from,
  `translateRich()` renders a line with links and emphasis without letting a
  translation add markup, declared value sets (string- or int-backed enums included)
  register a sentence once per value, and `markResolved()` marks a server-rendered
  page.
- **Server messages.** Validation errors and system messages register and translate
  ahead of time: `translateMessage()`, `emitMessage()`, `vendor/bin/langsys-messages`,
  and rule and app-message classes that state their own sentence.
- **Catalog snapshots.** `vendor/bin/langsys-snapshot` exports a catalog every Langsys
  SDK loads, and `'snapshot' => $snapshot` or `useSnapshot()` renders from it without
  calling the API. A snapshot made for another project is not used, and says so.
- **Excluded elements inside a phrase** (`translate="no"`, `data-notrans`) render as
  you wrote them, a value inside them included.
- **A marked element inside other content** is translated as its own unit, its marker
  written in any case.
- **Text inside `<svg>`** is translated; the drawing is left alone.
- **Stamped block ids are honoured**, and both the `data-ls-*` and `data-langsys-*`
  spellings are read.

### Fixed

- **Links and emphasis survive translation.** A phrase wrapped in a link, `<strong>`,
  `<span>` or a `<br>` keeps that markup.
- **A page is read and written as a browser reads it.** It is decoded by its byte-order
  mark, its declared charset or its bytes, by the browser's own decoders; written out
  in UTF-8; a page without `<html>`, `<head>` or `<body>` is translated whole; its
  scripts and styles come back byte for byte; a `<` that starts no tag is text; and
  `<!-->` is an empty comment. Each of these lost or mangled part of a page. On libxml2
  2.9 and 2.15 (Ubuntu 26.04's) alike, a page starts with its own first bytes, never a
  stray `<!--?xml encoding="UTF-8"-->`, and one that opens with an XML declaration
  starts at its first element; a title, textarea, xmp, iframe, noembed or noframes body
  is read as a browser reads it, inside svg, math or a select too, and written back
  never escaped twice; and a page's own escaped text never becomes markup.
- **HTML cut off inside a tag reads as a browser reads it**, as an excerpt trimmed to a
  length often is: the unfinished tag is left out and registers nothing, and a comment
  left open runs to the end of the input.
- **Script, style and excluded text are never rewritten** by a translation. Text inside
  `<plaintext>`, which a browser shows as written, is neither translated nor registered.
- **A plural Langsys generates for a target locale renders its singular** at a count
  of 1. Every request asks the API for ICU.
- **ICU renders on every path.** A page translated without params, and a translation
  that fails to format, render their branch for the value, not the raw pattern; a
  missing `{v, number}`, `{d, date}` or `{t, time}` shows as `{v}`.
- **`#` in a select's branch is text**, as ICU reads it, where the SDK's own renderer
  wrote the select's value.
- **A date in a plain `{d}` of a phrase with ICU in it renders as a date**, not as a
  Unix timestamp.
- **One catalog per locale, whatever its spelling** (`es-ES`, `es_ES`, `es-es`), and
  `sync()` clears it in any spelling.
- **An empty or null category is one category**, in a call, a page's
  `data-langsys-category`, a default category or a selector, so an uncategorised phrase
  stops re-registering on every request.
- **A registered but untranslated phrase is known**, renders its source and is not
  registered again.
- **An outage costs one failed request, not one per phrase.** A failed catalog fetch
  is remembered for 3 seconds, doubling to 5 minutes, and nothing is queued meanwhile.
  A malformed or empty response is never cached.
- **A failing registration endpoint is backed off**, its items kept queued, and a
  refused send records nothing that stops the next request registering.
- **One key's authorization never answers for another.** The cached authorization is
  kept per API key as well as per project, so a read key's answer never stops a write
  key registering, nor the other way round.
- **Every cache key is namespaced by project**, so two projects sharing a cache no
  longer suppress each other's registrations.
- **Registrations are chunked to the batch limit the API advertises**, and a bad limit
  cannot take the request down.
- **An API error in the error envelope** raises its message, with `getErrorCode()` and
  `getServerMessages()`, instead of a `TypeError`.
- **A cache that throws never takes a page down**: the page renders, in its source
  where the catalog cannot be read.
- **A worker that builds a `Client` per request no longer grows** with every request.
  A `Client` its framework lets go is freed at once and sends what it still has
  queued.
- **A display locale cannot steer a request.** The `utilities()` resource throws
  `InvalidArgumentException` for one that names no path segment (empty, `.` or `..`).
- **A truncated phrase ending in `…` is not registered beside its full text.**

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

[2.2.2]: https://github.com/langsys/langsys-php/compare/v2.2.1...v2.2.2
[2.2.1]: https://github.com/langsys/langsys-php/compare/v2.2.0...v2.2.1
[2.2.0]: https://github.com/langsys/langsys-php/compare/v2.1.0...v2.2.0
[2.1.0]: https://github.com/langsys/langsys-php/compare/v2.0.2...v2.1.0
[2.0.2]: https://github.com/langsys/langsys-php/compare/v2.0.1...v2.0.2
[2.0.1]: https://github.com/langsys/langsys-php/compare/v2.0.0...v2.0.1
[2.0.0]: https://github.com/langsys/langsys-php/compare/v1.3.1...v2.0.0
[1.3.1]: https://github.com/langsys/langsys-php/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/langsys/langsys-php/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/langsys/langsys-php/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/langsys/langsys-php/compare/v1.0.2...v1.1.0
[1.0.2]: https://github.com/langsys/langsys-php/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/langsys/langsys-php/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/langsys/langsys-php/releases/tag/v1.0.0
