# Langsys PHP SDK

[![packagist](https://img.shields.io/packagist/v/langsys/langsys-php.svg?style=flat)](https://packagist.org/packages/langsys/langsys-php)
[![build](https://img.shields.io/github/actions/workflow/status/langsys/langsys-php/ci.yml?style=flat)](https://github.com/langsys/langsys-php/actions)
[![last commit](https://img.shields.io/github/last-commit/langsys/langsys-php.svg?style=flat)](https://github.com/langsys/langsys-php/commits)
[![commit activity](https://img.shields.io/github/commit-activity/m/langsys/langsys-php.svg?style=flat)](https://github.com/langsys/langsys-php/pulse)
[![php](https://img.shields.io/packagist/dependency-v/langsys/langsys-php/php?style=flat)](https://packagist.org/packages/langsys/langsys-php)
[![downloads](https://img.shields.io/packagist/dm/langsys/langsys-php.svg?style=flat)](https://packagist.org/packages/langsys/langsys-php)
[![license](https://img.shields.io/packagist/l/langsys/langsys-php.svg?style=flat)](./LICENSE)

Official PHP SDK for the [Langsys](https://langsys.dev) Translation API. Manage translations, register phrases, and sync your application's translatable content.

## Requirements

- PHP 7.4 or higher (tested up to PHP 8.4)
- cURL extension
- JSON extension
- intl extension (for ICU plurals and locale-aware number/date formatting)
- DOM, libxml, filter and tokenizer extensions (included in most PHP builds); tested on
  libxml2 2.9 and 2.15 (CI runs both)
- Redis extension (optional, for Redis caching)

## Installation

### Via Composer (Recommended)

```bash
composer require "langsys/langsys-php:^2.0"
```

**Laravel:** `langsys/langsys-php-laravel` 1.0.0 requires `langsys/langsys-php` `^1.3`, so a
Laravel app that uses it stays on langsys-php 1.x until the Laravel package releases a
version built on 2.0.0.

**Using a coding agent?** Run `npx langsys-skill install`. It gives the agent the Langsys
skills, this package's included: the package ships its own in `skill/`.

Coming from 1.x? [UPGRADING.md](UPGRADING.md) lists what an existing app notices.
[tests/README.md](tests/README.md) says how to run this SDK's tests.

### Manual Installation (Without Composer)

1. Download or clone the repository
2. Include the autoloader:

```php
<?php
require_once '/path/to/langsys-php/autoload.php';

use Langsys\SDK\Client;

$client = new Client('your-api-key', 'your-project-id');
```

## Quick Start

```php
<?php
require 'vendor/autoload.php';

use Langsys\SDK\Client;

// Initialize client (uses environment variables)
$client = new Client();

// Get translations for Spanish
$translations = $client->getTranslations('es-es');

// Translate a phrase
echo $client->translate('Home', 'es-es', 'UI'); // "Inicio"
```

## Configuration

### Environment Variables

Set these environment variables to configure the SDK:

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `LANGSYS_API_KEY` | Yes | - | Your Langsys API key. Without it the client is unconfigured (see *Without credentials*) |
| `LANGSYS_PROJECT_ID` | Yes | - | Your project UUID. Without it the client is unconfigured |
| `LANGSYS_API_URL` | No | `https://api.langsys.dev/api` | API base URL |
| `LANGSYS_CACHE_DRIVER` | No | `file` | Cache driver: `file`, `redis`, or `none` |
| `LANGSYS_CACHE_PATH` | No | `langsys-cache` in the system temp dir | Directory for file cache |
| `LANGSYS_CACHE_TTL` | No | `3600` | Cache TTL in seconds |
| `LANGSYS_BASE_URL` | No | Auto-detect | Base URL for resolving relative URLs in content blocks |
| `LANGSYS_LOG_PATH` | No | - | Path to log file (without it, warnings and errors go to PHP's error log) |
| `LANGSYS_LOG_LEVEL` | No | `info` | Minimum log level: `debug`, `info`, `warning`, `error` |
| `LANGSYS_MESSAGES_CATEGORY` | No | `Errors` | Category server message templates are registered and looked up under |
| `LANGSYS_MESSAGES_PREVIOUS_CATEGORY` | No | - | After renaming the messages category, the one the templates were under before, so they keep their translations |

### Constructor Options

You can also pass configuration directly:

```php
$client = new Client(
    'your-api-key',           // API key (or null to use env)
    'your-project-id',        // Project ID (or null to use env)
    [
        'api_url' => 'https://api.langsys.dev/api',
        'cache_driver' => 'file',
        'cache_path' => '/tmp/langsys-cache',
        'cache_ttl' => 3600,
        'cache_clear' => false,               // Clear cache on initialization
        'base_url' => 'https://example.com',  // For resolving relative URLs
        'log_path' => '/var/log/langsys.log', // Enable logging
        'log_level' => 'info',                // Minimum level to log
        'messages_category' => 'Errors',      // Category for server message templates
        // 'messages_previous_category' => 'Errors', // After renaming messages_category: where the templates were
        // 'previous_categories' => ['checkout' => 'header'], // Renamed categories, for translate() and planSync()
        'require_credentials' => false,       // Throw at first use when the key or project ID is missing
        // 'logger' => $logger,               // A Langsys\SDK\Log\LoggerInterface, used instead of log_path
        'warn_runtime_requirements' => true,  // Write setup warnings straight to PHP's error log too
    ]
);
```

### Without credentials

A client built without an API key or a project ID is *unconfigured*, so an app's tests, CI
and a fresh clone run without secrets. Every phrase and content block renders as its source
text with its values filled, a page comes back as given, nothing is fetched or queued, and
one warning per process names the missing settings. The miss fallback, a loaded snapshot
and the migration mode's language files still answer. An explicit write
(`registerPhrases()`, `registerContentBlock()`, a sync or an import) throws a
`ConfigurationException` saying the client is not configured, and `isConfigured()` reports
the state:

```php
$client = new Client();           // LANGSYS_API_KEY and LANGSYS_PROJECT_ID unset
$client->isConfigured();          // false
$client->translate('Hello, {name}', 'es-es', null, null, ['name' => 'Ana']); // "Hello, Ana"
```

For production, pass `'require_credentials' => true`. The client still builds, so a
framework can create it while it boots without secrets, and its first use throws the
`ConfigurationException` naming the missing setting.

## API Key Types

Langsys has three types of API keys:

- **Read**: fetches translations
- **Write**: fetches translations and registers new phrases and content blocks
- **IP write**: writes only from the addresses on its allow-list, and reads from anywhere

Whether a request may register is the API's decision for that request, `write_enabled`,
which depends on the key, the caller's address and any write grant. The SDK never decides
it from the key's type, and an answer without the field is read-only. A cached
authorization holds no decision, so a request that writes asks the API once, when it
first needs to. `canWrite()` asks on its first call in a request and throws a
`LangsysException` when the API cannot answer:

```php
try {
    $writable = $client->canWrite(); // This request may register phrases, or is read-only
} catch (\Langsys\SDK\Exception\LangsysException $e) {
    $writable = false; // The API could not be asked
}

echo $client->getKeyType(); // "read", "write" or "ip_write": the key's type, not the decision
```

## Full Page Translation

The `translatePage()` method translates an entire HTML document in one pass. It automatically:
- Sets the `lang` attribute on `<html>`
- Ensures `<meta charset="utf-8">` exists, in the `<head>` a browser implies when the
  document has none (a fragment is given no head)
- Translates `<title>`, meta description, OpenGraph tags, Twitter cards
- Extracts and translates body content (phrases and content blocks)
- Registers new phrases automatically (when the API lets the request write)
- Falls back to original content when translations are missing

### Basic Usage

```php
<?php
ob_start();
// ... your application generates the HTML page ...
$html = ob_get_contents();
ob_end_clean();

require 'vendor/autoload.php';
use Langsys\SDK\Client;

$client = new Client();
$client->setLocale('es-es');
echo $client->translatePage($html, 'homepage');
```

### Locale Detection

A locale your framework or app already resolved is the one to serve: pass it as
`framework` in the request data to `resolveRequestLocale()` (a framework integration
passes `App::getLocale()` and the like), and set the result. It is mapped to the
project's form, `es_ES` to `es-es` and a bare `es` to the project's default Spanish
locale, and served as the base locale when the project doesn't serve it; the SDK adds no
`Vary` for it. `resolveRequestLocale()` only returns its choice, so apply it:

```php
$client->setLocale($client->resolveRequestLocale(['framework' => $appLocale])['locale']);
```

Where nothing resolved it, `getLocale()` (with no locale set) chooses the request's
locale once per request, from the first usable source in this order:

1. **The URL**: a `?locale=` query parameter, the first path segment (`/fr/pricing`)
   or the subdomain (`fr.example.com`).
2. **A cookie or session value** named `locale`.
3. **`Accept-Language`**, negotiated against the project's locales.
4. Otherwise the project's base locale.

Every candidate is checked against the locales the project serves (its base and
target locales); an unsupported one is skipped, never served. `fr` or `fr-be`
matches a project serving `fr-fr`. The response's `Vary` names every request field the
choice read: none when the URL decided, `Cookie` when a cookie or session value did, and
`Accept-Language, Cookie` when the header or the base locale did (`Accept-Language` alone
when the cookie and session lookups are off and no resolver is set), so a CDN never serves
one visitor's language to the next. The SDK never writes a cookie.

```php
// Explicit locale
$client->setLocale('fr-ca');

// Or let the request decide
$locale = $client->getLocale();

// Where your app keeps the value: parameter and cookie names, or your own resolver
// for the URL and cookie steps (its answer is checked like any other)
$client = new Client($key, $project, ['request_locale' => [
    'query_param' => 'lang',
    'cookie' => 'site_lang',      // null turns the cookie lookup off
    'session_key' => 'site_lang', // null turns the session lookup off
    // 'path' => false,           // don't read the first path segment
    // 'subdomain' => false,      // don't read the first label of a host with three or more
    // 'resolver' => fn (array $request) => ['locale' => $request['query']['l'] ?? null, 'from' => 'url'],
    // A framework that sets Vary on its own response: take it from the result instead
    // 'send_vary' => false,
]]);

// Chained usage
echo (new Client())
    ->setLocale('de-de')
    ->translatePage($html, 'contact');
```

### How It Works

The page is walked into **units**: every element that does not contain block
elements of its own — a paragraph, a list item, a heading, and equally an `<img>`,
`<input>`, `<button>` or `<a>` sitting directly under a container. A unit's phrases
are its own translatable attributes first (`title`, `alt`, `placeholder`, … in a
fixed order), then its text in document order.

1. **A unit whose one phrase is its one text node** registers as a simple phrase:
   - `<p>Hello</p>` → phrase "Hello"
   - `<p><strong>Hello World</strong></p>` → phrase "Hello World" (inline formatting preserved)
   - `<p><a href="#">Click here</a></p>` → phrase "Click here"
   - `<p><svg>…<text>Label</text></svg></p>` → phrase "Label" (the drawing is kept)
2. **Any other unit** registers as a content block:
   - `<p><strong>Hello</strong> World</p>` → content block with phrases ["Hello", "World"]
   - `<nav><a>Home</a><a>About</a></nav>` → content block with phrases ["Home", "About"]
   - `<p title="Tooltip">Hello</p>` → content block with phrases ["Tooltip", "Hello"]
   - `<img alt="Logo">` → content block with phrases ["Logo"] (an attribute has no text node to write into)
3. **Head section** is processed for translatable meta tags
4. **`translate="no"`** attribute is respected to skip elements
5. **Script/style** tags are never processed

The same content yields the same shape in every Langsys SDK, because a phrase and
a block holding the same words are two catalog entries with two ids.

### Example Output

```php
// Input HTML
$html = '<!DOCTYPE html>
<html lang="en">
<head>
    <title>Welcome</title>
    <meta name="description" content="A great website">
</head>
<body>
    <h1>Welcome</h1>
    <p>Get started today</p>
</body>
</html>';

$client->setLocale('es-es');
echo $client->translatePage($html, 'homepage');

// Output (with translations available):
// <!DOCTYPE html>
// <html lang="es-es" data-ls-resolved="es-es">
// <head>
//     <meta charset="utf-8">
//     <title>Bienvenido</title>
//     <meta name="description" content="Un gran sitio web">
// </head>
// <body>
//     <h1>Bienvenido</h1>
//     <p>Comienza hoy</p>
// </body>
// </html>
```

### Read-Only Keys

With a read-only API key, `translatePage()` will:
- Translate content that has existing translations
- Silently skip registration of new phrases (no errors)
- Fall back to original content for untranslated items

### Per-Section Categories with `data-langsys-category`

By default, all phrases on a page use the category passed to `translatePage()`. You can override this for specific sections using the `data-langsys-category` attribute:

```html
<!-- Default category is 'homepage' -->
<body>
    <header data-langsys-category="header">
        <h1>Welcome</h1>  <!-- Registered under category: header -->
        <nav>
            <a>Home</a>   <!-- Registered under category: header -->
            <a>About</a>  <!-- Registered under category: header -->
        </nav>
    </header>

    <main>
        <p>Hello World</p>  <!-- Registered under category: homepage (default) -->

        <section data-langsys-category="widgets/contact">
            <h2>Contact Us</h2>  <!-- Registered under category: widgets/contact -->
            <p>Get in touch</p>  <!-- Registered under category: widgets/contact -->
        </section>
    </main>

    <footer data-langsys-category="footer">
        <p>Copyright 2024</p>  <!-- Registered under category: footer -->
    </footer>
</body>
```

```php
// All content uses its respective category
$client->setLocale('es-es');
echo $client->translatePage($html, 'homepage');
```

**Key behaviors:**
- Categories are **inherited** - child elements use their parent's category unless they specify their own
- Any element can have `data-langsys-category` - not just block elements
- Empty string (`data-langsys-category=""`) falls back to `__uncategorized__`
- This allows reusable components (headers, footers, widgets) to have consistent translations across pages

### Selector-Based Category Mapping

For more flexible category assignment, use CSS selectors to map elements to categories:

```php
$client->setLocale('es-es');
echo $client->translatePage($html, 'homepage', [
    // Navigation links get their own category, under the page's
    'nav li' => [
        'category' => 'Navigation',
        'overrideParentElementCategory' => true,
    ],

    // Multiple selectors for the same category
    '.action, .btn' => [
        'category' => 'UI Elements',
        'overrideParentElementCategory' => true,
    ],

    // Without the override, a rule applies only where no category reaches
    // the element, so under 'homepage' this one never does
    '.promo' => ['category' => 'Promotions'],
]);
```

**Supported selectors:**
- Tag: `div`, `p`, `li`
- Class: `.btn`, `.primary`
- ID: `#submit`
- Tag + class: `div.button`, `p.special`
- Attribute: `[data-type]`, `[type="submit"]`, `[class^="btn-"]`
- Descendant: `nav li`
- Child: `ul > li`
- Multiple: `button, .btn` (comma-separated)

**Priority order (highest to lowest):**
1. Selector with `overrideParentElementCategory: true`
2. Element's `data-langsys-category` attribute
3. The category inherited from a parent element; at the top of the page, the category
   passed to `translatePage()`
4. Selector with `overrideParentElementCategory: false`
5. `__uncategorized__`

**About `overrideParentElementCategory`:**
This option is most often desired to be `true`, ensuring selector-matched elements always use their assigned category. The default is `false` to require an explicit decision for each selector, since enabling it overrides any `data-langsys-category` attributes set in the HTML. When `false`, the HTML attribute and the inherited category both take precedence over the selector rule, and every element inherits the category passed to `translatePage()`: a selector without the override applies only when `translatePage()` is given no category and no ancestor carries one.

**Recommended starting configuration:**

```php
$selectorCategories = [
    // Navigation links and menus
    'nav li, .nav-link, .menu-item' => [
        'category' => 'Navigation',
        'overrideParentElementCategory' => true,
    ],

    // Buttons and interactive UI elements
    'button, input[type="submit"], input[type="button"], .btn, .button' => [
        'category' => 'UI Elements',
        'overrideParentElementCategory' => true,
    ],

    // Error and validation messages
    '.error, .error-message, .validation-message, [role="alert"]' => [
        'category' => 'Errors',
        'overrideParentElementCategory' => true,
    ],

    // Footer (legal, copyright, policies)
    'footer, .footer' => [
        'category' => 'Footer',
        'overrideParentElementCategory' => true,
    ],
];
```

### Moving Content to Another Category

A phrase's or block's category is part of what identifies it, so moving it to a new category
would register new content with no translations. Name the category it came from instead:
anywhere a category is accepted, pass an array.

```php
$client->translate('Buy now', null, ['category' => 'Checkout', 'previous_category' => 'Header']);
$client->translateContentBlock($html, ['category' => 'Checkout', 'previous_category' => '']); // '' = it had no category
echo $client->translatePage($html, ['category' => 'home', 'previous_category' => 'landing'], [
    'nav li' => ['category' => 'Navigation', 'previous_category' => 'Header', 'overrideParentElementCategory' => true],
]);
```

```html
<section data-langsys-category="Checkout" data-langsys-previous-category="Header">
    <p>Buy now</p>
</section>
```

- The new item is registered with `previous_category`, and the API carries the old item's
  translations over to it. Until they arrive, the previous category's translation renders,
  and the element is stamped `data-ls-previous-category` (a phrase) or
  `data-ls-previous-contentblock` (a block) so a JavaScript SDK hydrating the page keeps it.
- Once the new category holds a translation, the previous category is ignored, so it can stay
  in place for a release and be removed afterwards. It never changes a `custom_id`.
- A whole renamed category, such as a key group whose language file was renamed, is one Client
  option: `'previous_categories' => ['checkout' => 'header']`. `translate()`, `resolve()` and
  `translateRich()` apply it to any call that names no move, and `planSync()` uses it as its
  default map. A renamed messages category takes `messages_previous_category`.
- `planSync()` hits take a `'previous_category'` key. Hits naming different previous categories
  for one item are listed in `$plan->moveConflicts` and fail `failsStrict()`; the first in file
  path and line order is sent. `$plan->leftBehind` lists moves where
  another call still uses the same text under the old category; the move retires the old item
  for every call, so move that call too or drop the previous category.
- Needs the Langsys API v0.108.4 or later. An older API ignores the field, and the content
  registers as new.

### Forcing Content Block with `data-langsys-contentblock`

By default, `translatePage()` decomposes nested content into individual phrases where possible. Use `data-langsys-contentblock` to treat an element and all its children as a single content block:

```html
<!-- Without attribute: "Contact Us" and "Fill out the form" would be separate phrases -->
<!-- With attribute: entire section is registered as one content block -->
<section data-langsys-contentblock="true">
    <h2>Contact Us</h2>
    <p>Fill out the form below</p>
</section>
```

**What the value means** (either spelling, `data-langsys-contentblock` or `data-ls-contentblock`;
trimmed, case-insensitive):

- **No value, `""`, `"true"`, `"1"` or `"yes"`** declares a block. The SDK registers the element
  as one content block and, when it renders it, sets the attribute to the block's id.
- **`"false"` or `"0"`** opts out: the element is walked as ordinary markup.
- **Any other value** is the block's id, stamped by a renderer or supplied by your app. The
  element renders from the catalog entry under that id, or stays in the source language if the
  catalog holds none. When the catalog lacks the id, the element's content is registered under
  it, unless the element or an ancestor carries `data-ls-resolved`: a server rendered that
  markup from the catalog, so nothing is registered for it.

Every rendered block host carries the id it was rendered from, so the block can be identified
from the page in a browser inspector: a block the walk found gets `data-ls-contentblock="<id>"`,
a declared one has the id written into the attribute that declared it
(`data-langsys-contentblock="<id>"`), and one whose value is already its id is left as written.

**Use cases:**
- Complex widgets that should be translated as a unit
- Content where phrase order and context is critical
- Reusable components with interdependent text

**A marked element inside other content is its own unit.** An element carrying
`data-langsys-contentblock` or `data-langsys-phrase` inside a paragraph, a content
block or another marked element contributes none of its words to what surrounds
it: it is registered and translated on its own, on `translatePage()` and
`translateContentBlock()` alike. So `<p>See <span data-langsys-phrase>our <b>new</b>
plans</span> today</p>` registers the block `["See", "today"]` and the phrase
`our {m0o}new{m0c} plans`, and the same words never register twice.

## Fetching Translations

### Get All Translations

Returns translations grouped by category:

```php
$translations = $client->getTranslations('es-es');

// Structure:
// [
//     'UI' => [
//         'Home' => 'Inicio',
//         'About' => 'Acerca de',
//     ],
//     '__uncategorized__' => [
//         'Welcome' => 'Bienvenido',
//     ]
// ]
```

### Translate a Single Phrase

The `translate()` method both translates AND automatically queues new phrases for registration:

```php
// Set locale first (or auto-detect from browser)
$client->setLocale('es-es');

// Basic usage - uses current locale
$text = $client->translate('Home');

// With explicit locale
$text = $client->translate('Home', 'es-es');

// With category
$text = $client->translate('Home', 'es-es', 'UI');

// Using current locale with category
$text = $client->translate('Home', null, 'UI');

// For content block phrases
$text = $client->translate('Menu Item', 'es-es', 'Navigation', 'content-block-id');

// With placeholder values - see Placeholder Interpolation below
$text = $client->translate('Hello, {name}!', null, 'UI', null, ['name' => 'Sarah']);
```

If the phrase doesn't exist in translations, it will be:
1. Queued for registration (when the API lets the request write)
2. Returned as-is (fallback to original)
3. Added to in-memory cache to prevent re-queueing in same request

### Placeholder Interpolation

Pass dynamic values via the `$params` argument rather than building the string
yourself. Placeholders use `{name}`, the same canonical form the Langsys JS SDKs
store, so a shared catalog renders identically from a PHP backend and a JS frontend.

> **⚠️ Never pre-format strings before translating.**
>
> ```php
> // WRONG - registers "Hello, Sarah!", "Hello, Ahmed!", "Hello, Priya!", ...
> $client->translate(sprintf('Hello, %s!', $user->name));
>
> // RIGHT - registers "Hello, {name}!" exactly once
> $client->translate('Hello, {name}!', null, null, null, ['name' => $user->name]);
> ```
>
> `translate()` auto-registers any phrase it doesn't recognise, so building the
> string yourself creates a **new catalog entry for every distinct runtime value**.
> That pollutes the catalog every Langsys SDK shares, is billed as translatable
> words, and can't be undone by fixing the code afterwards. The SDK cannot detect
> this at runtime — an interpolated string is indistinguishable from an authored
> one — so this is the one rule worth remembering.

Placeholders work in phrases, content blocks and whole pages:

```php
$client->translate('Hello, {name}!', null, 'UI', null, ['name' => 'Sarah']);
$client->translateContentBlock('<p>Welcome back, {name}</p>', 'homepage', ['name' => 'Sarah']);
$client->translatePage($html, 'homepage', [], ['name' => 'Sarah']);
```

In `translateContentBlock()`, placeholders resolve in text nodes **and** in
translatable attributes (`placeholder`, `alt`, `title`, …).

In `translatePage()`, placeholders resolve in text nodes, in the `<head>`
(`<title>`, meta description, `og:*`, `twitter:*`), and in translatable
attributes, including a lone `<input placeholder="Search {site}">` beside a
paragraph.

#### Behaviour

| Case | Result |
|------|--------|
| Unknown key | Left **verbatim** (`{name}` stays `{name}`) — missing data stays visible |
| Key present but `null` | Also left verbatim |
| `{ name }` | Whitespace tolerated |
| String value | Passed through untouched — the **opt-out** from number formatting (IDs, codes) |
| Int / float | Formatted for the locale (`1234.5` → `1.234,5` in de-DE) |
| Bool | `"true"` / `"false"` |
| Enum | A backed enum by its value, a plain enum by its name |
| `DateTimeInterface` | Locale-formatted, medium date style |
| Phrase not translated yet | Fallback text still interpolates, so users never see raw `{name}` |

`translatePage()` is the exception: a page it can't translate, because no catalog or no
locale can be read (a client without credentials, or the API unreachable with nothing
cached), comes back exactly as given, `{name}` included. On a page, mark printed values
instead (*Values from variables*, below): a marked value shows its value either way.

The phrase registered with Langsys always keeps its placeholders — interpolation
only affects what your code receives.

#### Excluding content from translation

Two spellings, both author-facing and equivalent:

```html
<code translate="no">rm -rf /</code>
<div data-notrans>Internal build id: 4f2a91</div>
```

`translate="no"` is the standard HTML attribute. `data-notrans` is an alias for
hosts whose templating strips unknown bare attributes, or where `translate`
collides with another tool.

For `data-notrans`, presence alone is intent — the bare attribute works like any
boolean HTML attribute — and only an explicit `"false"` or `"0"` opts out
(case-insensitive). Excluded subtrees are never extracted, never registered, and
never rendered over.

This is also the right way to tell `translatePage()` that a subtree is **already
translated** — for example content resolved server-side by another mechanism.
Without it, page translation looks up the already-translated string as a source
phrase, misses, and registers it, putting translated text into the catalog as a
new source phrase.

#### Keeping a run together with `data-langsys-phrase`

By default, page translation splits at tag boundaries, so this becomes **two**
catalog entries — `"Based on {n}"` and `"reviews"`:

```html
<p>Based on {n} <strong>reviews</strong></p>
```

That puts the count in a different phrase from the noun it inflects, and no
plural rule can reach across the boundary. In Russian, Arabic or Polish the noun
form depends on the number, so a correct translation is impossible.

Mark the element to register the whole run as **one** phrase:

```html
<p data-langsys-phrase>Based on {n} <strong>reviews</strong></p>
```

The catalog then receives a single entry with markup tokens:

```
Based on {n} {m0o}reviews{m0c}
```

`{m0o}` / `{m0c}` are the opening and closing positions of the first element.
Translators may **move** them, and the markup is rebuilt where the tokens end up:

```
На основе {n, plural, one {# {m0o}отзыва{m0c}} other {# {m0o}отзывов{m0c}}}
  n=1 → На основе 1 <strong>отзыва</strong>
  n=3 → На основе 3 <strong>отзывов</strong>
```

Notes:

- This is the same wire format as the JS SDK's `<Phrase>` component, so entries
  registered by either SDK are usable by the other.
- Tokens are valid ICU argument names, which is what lets a markup-bearing
  phrase carry a plural at all.
- Element attributes (classes, `href`) are preserved — only the position is
  taken from the translation.
- Presence alone enables it; `data-langsys-phrase="false"` (or `"0"`) opts out.
- An element you excluded (`translate="no"`, `data-notrans`) is one empty token
  pair, `Signed in as {m0o}{m0c} today`, whatever it holds, and renders exactly
  as you wrote it, values included: a translator never sees its text, and a
  translation cannot write into it. A run made only of excluded elements
  registers nothing.
- If a translation drops, unbalances or misnumbers the tokens, the text is
  rendered without the markup rather than failing. The rendered page never
  contains literal `{m0o}`/`{m0c}` tokens on any path.

**If you server-render with `translatePage()` and then hydrate with a JS SDK**,
use a JS SDK version whose tokenizer recognises `data-langsys-phrase` (it skips
subtrees carrying either that or its own `data-ls-phrase`). The marker survives
into the rendered HTML by design, so a JS tokenizer that does not recognise it
will walk into a subtree this SDK has already handled and re-register it **split
at the tag boundaries** — silently undoing the keep-together guarantee for
exactly the content that needed it. This only applies when a JS tokenizer walks
server-rendered nodes; a JS app rendering its own components from the catalog
never sees this SDK's DOM.

#### Translated pages are marked as resolved

A page rendered in a language other than your project's base language is output,
not source. `translatePage()` says so on the root element:

```html
<html lang="es-es" data-ls-resolved="es-es">
```

Anything that walks that page later — a Langsys JS SDK hydrating it, or a middleware
translating the response again — registers none of its text, so Spanish never enters
the catalog as a source phrase. The page still translates and keeps its content-block
ids. A page rendered in the base language is source and carries no marker, so it stays
discoverable. A marker you put on the root yourself, in either spelling
(`data-ls-resolved` or `data-langsys-resolved`), is left as it is.

**A client-side app mounted inside a translated page** renders source text of its own,
which should still be discovered. Opt its mount point back out:

```html
<div id="app" data-ls-resolved="false"></div>
```

The nearest marked ancestor decides, and only `false` or `0` opt out.

#### Values from variables

A sentence that shows a value — a user's name, a count — is one phrase however many
values it is shown with. Rendered text alone can't say which part was a variable, so a
template integration marks each value it prints:

```html
<p>Hello <!--ls:name-->Ana<!--/ls-->, welcome back</p>
<!-- or, where comments can't be written: -->
<p>Hello <span data-ls-param="name">Ana</span>, welcome back</p>
```

`translatePage()` and `translateContentBlock()` read either form as the phrase
`Hello {name}, welcome back` with `name` = `Ana`. Every user's render registers that one
phrase, a translation places the value where its `{name}` is, and the output keeps the
marker around the value. A plural or select in the translation chooses on the value
itself:

| Markup | Registers |
|---|---|
| `<p>Hello <!--ls:name-->Ana<!--/ls--></p>` | the phrase `Hello {name}` |
| `<p>Hi <b><!--ls:name-->Ana<!--/ls--></b>, you have <!--ls:count-->3<!--/ls--> messages</p>` | one block, `Hi` · `{name}` · `, you have {count} messages` |
| `<td><!--ls:total-->89.00<!--/ls--> <!--ls:currency-->EUR<!--/ls--></td>` | nothing: it has no text of its own |
| `<p>Hello <!--ls:Name-->Ana<!--/ls--></p>` | nothing: a name must match `[a-z][a-z0-9_]*`, and a value that can't be named is never registered (one debug notice) |

A param you pass yourself under the same name wins over the marked value. A pair with an
element inside it, an unclosed pair and any other comment are ordinary markup. Text
without markers registers what it renders.

#### Plurals and gender: write the sentence flat

Never write ICU. Write the sentence as it reads, with the count as a placeholder, and
Langsys makes each language's plural forms, the base language included (Russian's four,
Arabic's six, and English's `You have 1 new message`): it runs the same plural check on
the base-language text, so English needs no ICU either. Langsys makes them once it
has checked the phrase: a translation run checks its own phrases, and an hourly check
covers the rest. Until then, and until a cached catalog refreshes, the base language
shows the sentence as written.

```php
$client->translate('You have {count} new messages.', null, 'Inbox', null, ['count' => $count]);
```

`You have {count} new messages.` is stored for Spanish as
`{count, plural, one {Tienes # mensaje nuevo.} other {Tienes # mensajes nuevos.}}`.
The client asks the API for that ICU form (`X-Langsys-Capabilities: icu`) on every
request, so a call that passes `count` renders the right form with no change. A
sentence that says something different at zero is its own phrase, chosen in code.

In a language with grammatical gender, Langsys can turn `{name}` into
`{name_gender, select, …}` in the translation. Pass `name_gender` beside `name` when you
know it (`female`, `male`); without it the neutral form renders.

Requires the `intl` extension. If `intl` is disabled at runtime the SDK logs a
warning naming the phrase and renders without it rather than failing the render: a
`select` still picks its branch, a plural picks an exact `=N` branch, else `one` at 1,
else `other`, a number is written as given and a date as `Y-m-d`. Malformed ICU also falls back rather
than throwing.

### Translate a Content Block

The `translateContentBlock()` method translates HTML content AND automatically queues what is new for registration. The fragment is one unit, read the way the page walk reads one: a fragment whose one phrase is its one text node, such as `<p>Hello</p>`, is looked up, registered and rendered as the phrase "Hello", written back into that text node; anything else is a content block:

```php
$client->setLocale('es-es');

$html = '<p><a href="#">Click here</a> to learn more</p>';

// Basic usage
$translated = $client->translateContentBlock($html);

// With category
$translated = $client->translateContentBlock($html, 'homepage');

// With placeholder values
$translated = $client->translateContentBlock('<p>Hi {name}</p>', 'homepage', ['name' => 'Sarah']);
```

This uses the same phrase extraction logic as `registerContentBlock()`, ensuring consistent behavior between translation and registration: `registerContentBlock('<p>Hello</p>')` registers the phrase "Hello". Passing a custom id names a content block explicitly, and it registers as one whatever its shape.

### Automatic Registration (Queuing)

The SDK automatically queues new phrases and content blocks during translation and flushes them at the end of the request:

```php
$client->setLocale('es-es');

// These queue new items automatically:
$client->translate('New phrase');
$client->translateContentBlock('<p>New content</p>');
$client->translatePage($html);

// Flush manually, or let the shutdown handler do it:
$result = $client->flushPendingRegistrations();
// Returns: ['phrases' => 5, 'content_blocks' => 2, 'success' => true, ...]
```

The automatic flush is **best-effort**. A `Client` sends what it still has queued
when it is freed, and one still alive when the process ends sends it from a shutdown
function. Neither happens when the process is killed (out of memory, a hard timeout),
and a long-lived worker (Octane, Swoole, a queue worker) that keeps one `Client` for
its whole life reaches neither until it exits. Call `flushPendingRegistrations()`
yourself at the end of each request or job, then `resetRequestState()`; the reset
drops anything still queued, so one request's phrases never go out with another's, and
clears the locale, one set with `setLocale()` included.

**When a send fails** the items stay queued and the SDK backs off: the next flush
waits 3 seconds, then 6, doubling up to 5 minutes, and the first successful send
resets the wait. The wait belongs to the `Client`, so on a long-lived worker it
carries across requests, and a failing endpoint is not asked again by every request.
`retained` in the result counts what is still queued; `dropped` counts what nothing
will send.

**`reason` says why a flush did not succeed**, and is null when it did:

| `reason` | Meaning |
|---|---|
| `not_write_enabled` | This request may not write; the queue is dropped. |
| `catalog_unavailable` | The catalog could not be read, so no miss could be decided and nothing was queued. |
| `backing_off` | Waiting after a failed send; the queue is kept. |
| `decision_unavailable` | Whether this request may write could not be read; the queue is kept. |
| `send_failed` | The API refused or never received the batch; the queue is kept. |

**A phrase ending in an ellipsis** (`…` or `...`) is logged at debug level, since
upstream code may have cut a longer text short. It is not registered only when a
longer phrase starting with the same text is already known in its category, so
`Loading…` registers normally.

**Queue Management:**

```php
// Check for pending items
if ($client->hasPendingRegistrations()) {
    // ...
}

// Get pending items
$phrases = $client->getPendingPhrases();
$blocks = $client->getPendingContentBlocks();

// Clear without sending to API
$client->clearPendingRegistrations();
```

> **Note**: With a key that may not write, the flush sends nothing and reports the
> items as `dropped`. A key whose type writes but that the server answers with
> `write_enabled: false` (an `ip_write` key used from an address off its allow-list,
> for example) is reported once, as a warning, so a misconfigured integration is
> not silent.

### Get Translation Statistics

```php
$resource = $client->translations();
$stats = $resource->getStats('es-es');

echo "Total words: " . $stats['words'];
echo "Untranslated: " . $stats['untranslated'];
```

### Get Full Response with Metadata

```php
$resource = $client->translations();
$response = $resource->getFlat('es-es');

// Response includes:
// - status: bool
// - words: int (total translatable words)
// - untranslated: int (words not yet translated)
// - data: array (the translations)
```

## Registering Phrases

> **Note**: Registers only when the API lets this request write (`canWrite()`); otherwise
> `registerPhrases()` and `registerContentBlock()` throw a `LangsysException`.

### Register Simple Phrases

```php
$client->registerPhrases([
    'New Feature',
    'Click Here',
    'Learn More',
]);
```

### Register Phrases with Categories

```php
$client->registerPhrases([
    ['phrase' => 'Dashboard', 'category' => 'Navigation'],
    ['phrase' => 'Settings', 'category' => 'Navigation'],
    ['phrase' => 'Submit', 'category' => 'Forms'],
]);
```

### Register Phrases with Translatable Flag

```php
$client->registerPhrases([
    [
        'phrase' => 'Copyright 2024',
        'category' => 'Footer',
        'translatable' => false,  // Mark as non-translatable
    ],
]);
```

### Register Content Blocks

Content blocks group multiple phrases together (useful for HTML content). Phrases are automatically extracted from the HTML:

```php
// Simple usage - just pass HTML content
$client->registerContentBlock(
    '<nav><a>Home</a><a>About</a></nav>'
);
// Phrases auto-extracted: ['Home', 'About']
// customId auto-generated from content hash

// With category
$client->registerContentBlock(
    '<nav><a>Home</a><a>About</a></nav>',
    'Navigation'  // Category
);

// With category and label
$client->registerContentBlock(
    '<nav><a>Home</a><a>About</a></nav>',
    'Navigation',           // Category
    'Main Navigation Menu'  // Label
);

// With explicit customId (for stable ID to update content later)
$client->registerContentBlock(
    '<nav><a>Home</a><a>About</a></nav>',
    'Navigation',
    'Main Navigation Menu',
    'main-menu-001'  // Custom ID
);
```

**Auto-extraction supports:**
- Text nodes
- Button and submit input values
- Select option text
- Use `translate="no"` attribute to exclude elements from extraction

**URL Resolution:**
Relative URLs in `src`, `srcset`, and `poster` attributes are automatically converted to absolute URLs when content blocks are registered. The base URL is determined from:
1. The `base_url` config option (if set)
2. The `LANGSYS_BASE_URL` environment variable (if set)
3. Auto-detected from `$_SERVER['HTTP_HOST']` and protocol headers

**Default translatable attributes:**

| Category | Attributes |
|----------|------------|
| Standard HTML | `placeholder`, `alt`, `title`, `label` |
| ARIA | `aria-label`, `aria-placeholder`, `aria-description`, `aria-valuetext`, `aria-roledescription` |
| Validation | `data-error`, `data-error-message`, `data-validation-message`, `data-invalid-message`, `data-required-message`, `data-pattern-message` |
| Framework | `data-confirm`, `data-tooltip`, `data-title`, `data-content`, `data-original-title`, `data-bs-title`, `data-bs-content`, `data-loading-text`, `data-success-message`, `data-warning-message`, `data-empty-message`, `data-placeholder` |

```php
// Complex example with attributes
$client->registerContentBlock(
    '<form>
        <input placeholder="Your email" title="Enter email address">
        <button type="submit">Subscribe</button>
        <span translate="no">Do not translate this</span>
    </form>',
    'Forms'
);
// Extracted: ['Your email', 'Enter email address', 'Subscribe']
// Note: "Do not translate this" is skipped due to translate="no"
```

### Custom Translatable Attributes

You can customize which HTML attributes are extracted for translation:

```php
// Add your own attributes to the defaults
$client->addTranslatableAttributes(['data-label', 'data-tooltip', 'data-i18n']);

// Or replace the defaults entirely
$client->setTranslatableAttributes(['data-label', 'data-text']);

// Reset to defaults
$client->resetTranslatableAttributes();

// Check current attributes
$attrs = $client->getTranslatableAttributes();
```

This is useful when your application uses custom data attributes for translatable content:

```php
$client->addTranslatableAttributes(['data-label']);

$client->registerContentBlock(
    '<div data-label="Custom Label">Visible Text</div>',
    'UI'
);
// Extracted: ['Custom Label', 'Visible Text']
```

### Bulk Registration Methods

```php
$resource = $client->translatableItems();

// Register multiple phrases with same category
$resource->createPhrasesWithCategory(
    ['Error 404', 'Error 500', 'Something went wrong'],
    'Errors'
);

// Register from a category map
$resource->createFromMap([
    'Navigation' => ['Home', 'About', 'Contact'],
    'Forms' => ['Submit', 'Cancel', 'Reset'],
    'Messages' => ['Success', 'Error', 'Warning'],
]);
```

## Syncing Phrases

The `sync()` method compares local phrases against the remote API and registers any new ones:

```php
// Define phrases used in your application
$localPhrases = [
    ['phrase' => 'Home', 'category' => 'UI'],
    ['phrase' => 'About', 'category' => 'UI'],
    ['phrase' => 'New Feature', 'category' => 'UI'],
];

// Sync with remote
$result = $client->sync($localPhrases, 'es-es');

// Result contains:
// - translations: array (current translations)
// - new_phrases: array (phrases that were new)
// - synced: bool (true if new phrases were registered)

echo "New phrases found: " . count($result['new_phrases']);
echo "Synced: " . ($result['synced'] ? 'Yes' : 'No');
```

> **Note**: If using a read-only API key, `sync()` will identify new phrases but won't register them (`synced` will be `false`).

## Caching

The SDK caches translations to reduce API calls. Three cache drivers are available:

### File Cache (Default)

```php
use Langsys\SDK\Cache\FileCache;

$client = new Client(null, null, [
    'cache' => new FileCache('/path/to/cache', 3600),
]);
```

### Redis Cache

```php
use Langsys\SDK\Cache\RedisCache;

// With connection options
$client = new Client(null, null, [
    'cache' => new RedisCache([
        'host' => '127.0.0.1',
        'port' => 6379,
        'password' => 'secret',
        'database' => 0,           // Redis database number
        'prefix' => 'langsys::',   // Key prefix (default: 'langsys::')
    ]),
]);

// Or with existing Redis instance
$redis = new Redis();
$redis->connect('127.0.0.1');

$client = new Client(null, null, [
    'cache' => new RedisCache($redis, 'myapp:langsys::', 3600),
]);

// Or use the cache_driver option with redis settings
$client = new Client('api-key', 'project-id', [
    'cache_driver' => 'redis',
    'redis' => [
        'host' => '127.0.0.1',
        'port' => 6379,
        'database' => 1,
        'prefix' => 'myapp::langsys::',
    ],
]);
```

### No Caching

```php
use Langsys\SDK\Cache\NullCache;

$client = new Client(null, null, [
    'cache' => new NullCache(),
]);
```

### Cache Management

```php
// Clear cache for specific locale
$client->clearCache('es-es');

// Clear all cache
$client->clearCache();

// Bypass cache for a single request
$translations = $client->getTranslations('es-es', false);

// Clear cache on initialization
$client = new Client('key', 'project', ['cache_clear' => true]);
```

## Logging

The SDK logs to a file in JSON Lines format when a writable file path is configured. Without one, warnings and errors still go to PHP's error log, prefixed `[langsys]`, so a failed registration or an unreachable API is never recorded nowhere; nothing below warning is written there. Pass `'error_log' => false` to turn that off. The one-time warnings about the setup itself (a client without credentials, a missing `intl`, a PHP below 7.4) are written to PHP's error log even then; pass `'warn_runtime_requirements' => false` to turn those off too.

### Enable Logging

```php
// Via constructor options
$client = new Client('key', 'project', [
    'log_path' => '/var/log/langsys.log',
    'log_level' => 'debug',  // debug, info, warning, error
]);

// Via environment variables
putenv('LANGSYS_LOG_PATH=/var/log/langsys.log');
putenv('LANGSYS_LOG_LEVEL=info');
$client = new Client();
```

### Log Levels

| Level | Description |
|-------|-------------|
| `debug` | Detailed debugging info (cache hits/misses, request details) |
| `info` | General operational events (API requests completed, authorization) |
| `warning` | Non-critical issues (a key whose type writes but that the server refuses, a translation that fails to format) |
| `error` | Errors (API request failures, exceptions) |

### What Gets Logged

- **API Requests**: Method, URL, status code, duration (ms)
- **Cache Operations**: Cache hits, misses, sets, deletes
- **Authorization**: Success/failure, key type
- **Registration**: Phrases and content blocks queued/flushed

### Log Context Variables

Each log entry includes a `context` object with relevant data. Here are the context variables for each log message:

**API Requests:**

| Message | Level | Context Variables |
|---------|-------|-------------------|
| `API request starting` | debug | `method`, `url`, `request_body_size`* |
| `API request completed` | info | `method`, `url`, `status_code`, `duration_ms` |
| `API request redirect` | warning | `method`, `url`, `status_code`, `duration_ms` |
| `API request error` | error | `method`, `url`, `status_code`, `duration_ms`, `response_body`**, `payload`*** |
| `API request failed` | error | `method`, `url`, `error`, `errno`, `http_code`, `duration_ms`, `payload`*** |

\* `request_body_size` only included for POST requests
\** `response_body` truncated to 1000 characters
\*** `payload` contains full request body (not truncated); only included for POST requests on errors

**Cache Operations:**

| Message | Level | Context Variables |
|---------|-------|-------------------|
| `Cache hit` | debug | `key` |
| `Cache miss` | debug | `key`, `reason` (`not_found`; for the file cache also `expired`, `read_error`, `invalid_data`) |
| `Translations cache hit` | debug | `locale`, `source` (`memory` or `persistent`) |
| `Translations cache miss` | debug | `locale` |
| `Cache set` | debug | `key`, `ttl` |
| `Cache delete` | debug | `key`, `success` |
| `Cache cleared` | debug | `files_removed` (file cache) or `keys_removed` (Redis) |
| `Cache cleared on initialization` | info | (none) |

**Authorization:**

| Message | Level | Context Variables |
|---------|-------|-------------------|
| `Project authorized` | info | `project_id`, `key_type`, `write_enabled` |
| `This key cannot register new text: …` | warning | `project_id`, `key_type` |

**Translations:**

| Message | Level | Context Variables |
|---------|-------|-------------------|
| `Fetching translations` | debug | `locale` |

**Registration:**

| Message | Level | Context Variables |
|---------|-------|-------------------|
| `Phrase queued for registration` | debug | `phrase`, `category` |
| `Content block queued for registration` | debug | `custom_id`, `category`, `phrase_count` |
| `Pending registrations flushed` | info | `phrases`, `content_blocks`, `success` |
| `Flush skipped - this request may not write` | debug | `pending_phrases`, `pending_content_blocks` |

### Log Entry Format

Logs are written in JSON Lines format, one entry per line:

```json
{"timestamp":"2025-01-15T10:30:45.123456Z","level":"info","message":"API request completed","context":{"method":"GET","url":"https://api.langsys.dev/api/translations","status_code":200,"duration_ms":142.5}}
{"timestamp":"2025-01-15T10:30:45.125000Z","level":"debug","message":"Cache set","context":{"key":"translations_proj123_es-es","ttl":3600}}
{"timestamp":"2025-01-15T10:30:46.000000Z","level":"error","message":"API request error","context":{"method":"POST","url":"https://api.langsys.dev/api/translatable-items","status_code":500,"duration_ms":89.2,"response_body":"{\"error\":\"Internal Server Error\",\"message\":\"Database connection failed\"}"}}
{"timestamp":"2025-01-15T10:30:47.000000Z","level":"error","message":"API request failed","context":{"method":"POST","url":"https://api.langsys.dev/api/translatable-items","error":"Operation timed out after 30001 milliseconds","errno":28,"http_code":0,"duration_ms":30001.5}}
```

### Log Viewer

The SDK includes a built-in log viewer with a Flowbite/Tailwind UI:

```php
// Get the LogViewer instance
$viewer = $client->getLogViewer();

// Get log entries (optionally filter by minimum level)
$entries = $viewer->getEntries('warning');

// Get statistics by level
$stats = $viewer->getStats();
// ['total' => 100, 'debug' => 50, 'info' => 30, 'warning' => 15, 'error' => 5]

// Render as HTML page
$html = $viewer->render('info');

// Output directly to browser
$client->displayLogs();

// Clear the log file
$viewer->clear();

// Get file size
echo $viewer->getFormattedFileSize(); // "1.5 MB"
```

**URL Parameters for `displayLogs()`:**

| Parameter | Values | Default | Description |
|-----------|--------|---------|-------------|
| `level` | `debug`, `info`, `warning`, `error` | `debug` | Minimum log level to display |
| `format` | `json` | `html` | Return JSON data instead of HTML page |
| `action` | `clear` | - | Clear all log entries |
| `refresh` | Integer (seconds) | - | Legacy: auto-refresh page every N seconds |

Example URLs:
- `/logs.php` - Show all logs (debug+)
- `/logs.php?level=warning` - Show warnings and errors only
- `/logs.php?format=json` - Get logs as JSON (for AJAX)
- `/logs.php?action=clear` - Clear log file (returns JSON response)

The log viewer includes:
- **Realtime mode** - Toggle button to enable AJAX polling (updates every 2 seconds without page reload)
- **Dynamic filters** - Level filter buttons update instantly without page reload
- **Clear logs** - Button to clear all log entries with confirmation
- **Persistent settings** - Filter level and realtime mode are saved in localStorage
- **Hide messages** - Hide repetitive log messages from display
- Statistics cards showing counts by log level
- Expandable context for each entry with close button

## Server Messages

Validation errors and other messages your server sends can be translated like any
phrase, without changing how your framework reports them. Each message travels as an
entry: `template`, your framework's own sentence before its values are filled, and
`params`, those values; `message` is the filled sentence, shown until a translation
exists. `code` and `field` are whatever your framework already reports - its rule
name or error identifier, and its field path - carried unchanged, or absent. A
translator sees one whole sentence, so gender and number come out right.

### Build entries

```php
use Langsys\SDK\Messages\ServerMessage;

$entry = ServerMessage::make('min', 'The password field must be at least {min} characters.', ['min' => 12], 'password');

$entry->getMessage(); // "The password field must be at least 12 characters."
$entry->toArray();    // field, code, message, template, params
```

The template is your framework's own sentence with the field's label written in where
it references the field (Laravel's `The :attribute field is required.` becomes `The
password field is required.`). Use a `{name}` marker only for a value that isn't
translatable: a number, a date, what the user typed. Attach the entries to your
framework's own error response under a key of your choosing; the response otherwise
stays exactly as your framework writes it.

### Render entries

```php
use Langsys\SDK\Messages\MessageSet;

$messages = MessageSet::fromResponse($responseBody); // finds entries wherever they sit
// or: MessageSet::fromResponse($responseBody, ['key' => 'translatable', 'pieces' => ['template' => 'msgid']]);

foreach ($messages->forField('password') as $entry) {
    echo $client->translateMessage($entry); // translated, or the server's message until it is
}
```

`translateMessage()` looks the template up under the `Errors` category and never uses
the filled message as a key. When your server sends an entry, pass it through
`$client->emitMessage($entry)`: a template the catalog doesn't have yet is registered
after the response.

### Register every template ahead of time

A message doesn't exist until something fails, so no page shows it for discovery. List
them from your code instead:

```php
// langsys-messages.php
use Langsys\SDK\Client;
use Langsys\SDK\Messages\ErrorClassSource;

return [
    'sources' => [new ErrorClassSource([App\Errors\QuotaExceeded::class])],
    'client' => function () { return new Client(); },
];
```

```bash
vendor/bin/langsys-messages             # counts the templates, and reports each one it can't list
vendor/bin/langsys-messages -v          # lists each template and where it comes from
vendor/bin/langsys-messages --register  # registers the ones the catalog doesn't have yet
vendor/bin/langsys-messages --strict    # exits 1 when any message can't be listed
vendor/bin/langsys-messages --config=path/to/langsys-messages.php  # default: ./langsys-messages.php
```

A message the command can't list still registers the first time your server sends it,
so it is reported, not failed; add `--strict` in CI if no error should ever show
untranslated. An error class declares a `MESSAGE` constant, a public property for
each marker, and optionally a `CODE`, passed through as the entry's code. A template
that still holds a Laravel label placeholder (`:attribute`, `:other`, `:values`) is
refused: write the label in.

A message your app defines itself — an API error, a system notice — declares its sentence
and code by implementing `HasAppMessageTemplate`:

```php
use Langsys\SDK\Messages\HasAppMessageTemplate;
use Langsys\SDK\Messages\ServerMessage;

final class QuotaExceeded implements HasAppMessageTemplate
{
    public function __construct(public int $limit) {}

    public function template() { return 'You have used all {limit} of this month\'s requests.'; }
    public function code() { return 'quota_exceeded'; }
}

$catalog->addMessage(QuotaExceeded::class, 'app/Errors');  // listed once, with its code
$entry = ServerMessage::fromApp(new QuotaExceeded(500));    // what you send at runtime
```

It is registered under the messages category with its code; each `{name}` marker is filled
from the public property of the same name. Listing a class builds it without its
constructor, so `template()` must return the sentence itself rather than build it from
constructor arguments. A translate call inside `template()` belongs to that listing:
`planSync()` lists it in `$plan->viaMessageListing` instead of registering it. Pass the
classes your framework discovered as `['message_classes' => [...]]`, so one that inherits
the interface is recognised too, and the listed templates as
`['listed_templates' => array_column($catalog->templates(), 'template')]`, so a sentence the
listing already registers isn't registered a second time, uncategorised, from wherever else
it is called.

Some findings are advice rather than problems — a validated field with no declared label,
which shows the name the framework derives. A source reports those with
`$catalog->advise($source, $issue, $fix, $field)`; the command prints them under
"Advice", and they never fail it, `--strict` included.

## Migrating from Key-Based Translation Files

If your app calls translations by key (`checkout.submit`) and keeps its text in
language files, you can move to Langsys without rewriting a single call. Keep only
your source-language file, delete the others, and name it in the `migration` option:

```php
$client = new Client('your-api-key', 'your-project-id', [
    'migration' => [
        'files' => [
            ['path' => 'lang/en.json', 'format' => 'laravel'],
            'lang/en/checkout.php',
        ],
    ],
]);

echo $client->translate('checkout.submit'); // looks up "Place order" in your file
```

- **A key resolves to its text.** The text, not the key, is what gets registered and
  translated, so you can later replace the key with the text and delete the file with
  nothing else changing.
- **The key's namespace becomes the category.** `checkout.submit` registers under
  `checkout`, or under the category you pass.
- **Anything that isn't a key is text.** `translate('Pay now')` works as always, and
  registers exactly as written: `translate()` takes Langsys syntax.
- **A framework's own call converts its own syntax.** Text that reaches Laravel's
  `__()` or `trans_choice()` without being a key is written in Laravel's syntax, and
  `LegacyValue::fromCall($text, $replace, '__')` (or `'trans_choice'` with the
  number) turns it into the Langsys phrase and the params to render it with, the way
  Laravel reads it: only the placeholders the call passes convert, a `|` is a plural
  only through `trans_choice()`, and `:Name`/`:NAME` stay as written with a warning.
  So `__('Hello :name', ['name' => $n])` and `translate('Hello {name}')` are one phrase.
- **Placeholders are converted in every file.** `:name`, `{{name}}`, `{name}`, `%{name}`,
  `%(name)s` and `%(name)d` all become `{name}`, and `%%` is a literal `%`. A placeholder
  carrying number formatting (`%.2f`, `%(amount).2f`) or no name (`%s`) is registered as
  written, with a warning.
- **A key's number params print as plain digits**, as Laravel and the other frameworks
  print them: `translate('footer.copyright', null, null, null, ['year' => 2026])` on
  `© :year` shows `© 2026` in every locale, `#` in a plural included, and the plural still
  selects by the number. A call that is not a key hit formats numbers for the locale
  (`© 2,026` in English), as always.
- **Plurals are converted by the file's format**: `laravel`, `vue-i18n`, `i18next` or
  `plain`. The frameworks read the same characters differently (`car | cars` is a
  plural in vue-i18n and plain text in Laravel), so each file says which one wrote it.
  A PHP array file is `laravel` unless you say otherwise; a JSON file is `plain`, which
  converts no plurals, so declare the format of any JSON file that holds them. Any other
  format, such as a Rails YAML file or a gettext `.po`, is refused when the `Client` is
  created, with an error naming the format and the file.
- **A JSON file can sit under a namespace**: `['path' => 'locales/en/cart.json',
  'namespace' => 'cart']` answers `cart.items.title` from `{"items": {"title": ...}}`, as
  a PHP array file answers keys under its own name.
- **Forms that can't be converted are registered as written, with a warning**: a
  capitalising placeholder like `:Name`, or a plural range with no exact equivalent.
- **A framework's own bundled strings go in `fallback_files`**, which answer only
  keys your `files` don't define. Package keys (`courier::messages.welcome`) resolve
  through `'namespaces' => ['courier' => ['files' => [...], 'fallback_files' => [...]]]`.

With no `migration` option nothing is read and nothing is looked up.

### Import the translations you already have

Before deleting your target-language files, import them once, so the work already
done shows in Langsys immediately instead of being machine translated again:

```php
$result = $client->importLegacyTranslations([
    'es-es' => ['files' => ['lang/es.json', 'lang/es/checkout.php']],
    'fr-fr' => ['files' => ['lang/fr.json', 'lang/fr/checkout.php']],
]);
// ['success' => true, 'phrases' => 214, 'translations' => 401,
//  'human_translations_saved' => 401, 'human_translations_skipped' => 0, 'skipped' => [...]]
```

Each locale's configuration has the `migration` option's shape. Every key your source
files define registers the phrase a lookup of that key registers, together with each
locale's translation of the same key, converted the same way; Langsys stores those as
human translations and does not machine translate them.

- A locale your project doesn't target is refused before anything is sent.
- A translation that is missing, empty, or can't be converted is not imported: the
  phrase still registers, that locale is machine translated, and `skipped` lists the
  key, the locale and why.
- Past your plan's human-translated words for the period, the rest are machine
  translated; `human_translations_skipped` counts them.
- The import needs a request the API lets write; otherwise it returns `success: false`
  and `reason: 'not_write_enabled'`, and a refused request returns
  `reason: 'send_failed'`.

List what can't be migrated as it stands by adding
`new Langsys\SDK\Migration\LegacyKeysSource($client->getLegacyKeys())` to the sources
in your `langsys-messages.php`: it names each value it can't convert and each key
defined in more than one of your files.

## Behind a Framework's Translate Function

These are the pieces a framework binding (such as the Laravel binding) builds its own
`__()` on. With them an app keeps calling its framework's function, its language files
keep working, and phrases register from a sync command instead of at runtime.

### Sync: register from source, not at runtime

```php
use Langsys\SDK\Sync\SourceScanner;

$scanner = new SourceScanner(); // __, trans, t, trans_choice, Lang::get, Lang::choice
$hits = [];
foreach ($files as $path) {
    $hits = array_merge($hits, $scanner->scan(file_get_contents($path), $path));
}

$plan = $client->planSync($hits, ['es-es' => ['files' => ['lang/es.json']]]);
$plan->counts();      // ['in_catalog' => 120, 'with_translations' => 14, 'new' => 3]
$plan->reported;      // calls whose argument isn't a literal, with file and line
$plan->failsStrict(); // true when any call was reported

$client->applySync($plan); // registers what the catalog lacks
```

In a CI gate with no API key, plan offline instead. Every phrase counts as new, and what
a strict run would fail on is still reported:

```php
use Langsys\SDK\Sync\Planner;

$plan = Planner::offline($hits, ['files' => ['lang/en.json']], [], ['covered_groups' => ['validation']]);
exit($plan->failsStrict() ? 1 : 0);
```

Only a literal is a phrase: `__('Welcome back')` is collected, `__($message)` is reported
and never registered. A key built inside a literal group, `__("validation.$key")`, is
listed in `$plan->covered` instead: every line of that group in your base-language files
registers anyway, so it doesn't fail `--strict`. A group whose lines register another way — Laravel's
`validation` — is named with `planSync($hits, $targets, ['covered_groups' => ['validation']])`. A key your `migration` files hold registers as a lookup of it would,
under its group; any other literal registers as its call converts it, so
`__('Hello :name', ['name' => $n])` registers `Hello {name}`. Replacements the source
doesn't spell out still count: `compact('name')` passes `name`, and a variable passes every
placeholder in the text, as the running call would. A phrase the catalog lacks
but your other languages' files translate registers with those translations, stored as
human translations.

A line a template prints raw — a `getHtml(...)` or `choiceHtml(...)` call on the translator,
which the Laravel package's next release compiles `@lang`, `@t` and `@choice` to — registers with its inline markup as tokens, `Read our {m0o}terms{m0c}`, the phrase
`translateRich()` looks up, and its translations from your other languages' files are
tokenized the same way, each run on your source element it belongs to: a tag your line
holds once is that element, and of a tag it holds more than once, each is the element with
the same attributes, so a translation may reorder two links. One whose elements can't be
matched so (another count or tag, or two links whose attributes tell neither apart) is
listed in `$plan->skipped` as `not_converted`, and your language file keeps serving that
line. Once the catalog answers, the page rebuilds your source's own
elements, so an attribute a translated file changed, such as a localized `href`, gives way
to the source's.

With `'runtime_registration' => false`, `translate()`, `translateMessage()` and
`emitMessage()` register nothing; `translatePage()` and `translateContentBlock()` still discover what they render.

A line that still holds a label placeholder — `:attribute`, `:other`, `:values` — is
never registered on its own: at runtime the field's label is written in, so the raw line
is never looked up. `$plan->viaValidation` lists each one; it registers through your
validation listing, once per field.

### Validation rule objects

A rule object hands the validator a finished message, values filled in. Let it state its
sentence instead, so the value stays a marker:

```php
use Langsys\SDK\Messages\HasMessageTemplate;

class MaxWords implements ValidationRule, HasMessageTemplate
{
    public function __construct(public int $max) {}

    public function template()
    {
        return 'The :attribute may not have more than {max} words.';
    }
}
```

`MessageCatalog::addRule($rule, $label, $filledMessage, $source, $field)` lists it once per
field with the label written in — `The summary may not have more than {max} words.` — and
`RuleTemplate::forField($rule, $label)` gives the same template and `['max' => 50]` for the
runtime entry. A rule without the interface is listed from its filled message and
reported, which fails `--strict`.

### Language files as the fallback

```php
$client->useMissFallback(function ($phrase, $locale, $category, $argument) {
    return $myFramework->line($argument, $locale); // the line, unfilled, or null
});

$client->resolve('Welcome, {name}', 'es-es', null, ['name' => 'Ana']);
// ['text' => 'Bienvenido, Ana', 'from' => 'catalog' | 'fallback' | 'source']
```

A phrase resolves to the catalog's translation, then to your language file's line, then
to the source, filled with its params either way. When the catalog can't be read — the API
unreachable, a failed load — `translate()` and `resolve()` carry on down that chain without
throwing, and say so once, at debug. `from` says who wrote the text: print
catalog text escaped wherever your framework prints raw.

### Lines with links: `translateRich()`

```php
$client->translateRich('Read our <a href="/terms">terms</a>');
// ['html' => 'Lee <a href="/terms">los términos</a>', 'from' => 'catalog']
```

The line registers as `Read our {m0o}terms{m0c}`. Rendering rebuilds only your source's
elements, with their attributes as you wrote them, around the translated text: a tag or
attribute in a translation comes out as text, so a translation can move your link but never
add one, change its `href` or run script. An element you excluded is never translated: it
renders as you wrote it, with the params you pass filled in, since the line is a template.
A page or a block, by contrast, keeps excluded text exactly as written, so
`<code translate="no">{name}</code>` can show the syntax itself. A line from your language
files comes back as your framework prints it, with a marked value in it given back as its
marker.

### Declared value sets

A sentence that names a status or a category is registered once per value, with the value
written in — `The order is Shipped.` — so translators see the whole sentence. Declare the
values:

```php
use Langsys\SDK\Messages\TranslatesAs;

#[TranslatesAs('status')]
enum OrderStatus: string
{
    case Shipped = 'shipped';

    public function label(): string { return 'Shipped'; } // what readers see
}

class Category extends Model implements \Langsys\SDK\Messages\TranslatableValues
{
    public static function translatableValues()
    {
        return ['category' => static::pluck('name')->all()];
    }
}

$client = new Client($key, $project, ['value_sets' => [OrderStatus::class, Category::class]]);
$client->translate('The order is {status}.', null, null, null, ['status' => OrderStatus::Shipped]);
// looks up "The order is Shipped."
```

A backed enum's word is its `label()`, else its `value` — define `label()` when the value is
a slug. `planSync()` registers every sentence with every value. At runtime a value added
since the last sync registers its sentence after the response; a value no declaration names
for its placeholder never registers and renders as a placeholder.

### Marking a server-rendered page

```php
$html = $client->markResolved($html, 'es-es');
```

Adds `data-ls-resolved="es-es"` to the page's `<html>` tag, or, for a fragment response with
no `<html>`, to each of its top-level elements, and changes nothing else, so a browser SDK
hydrating the page registers none of its translated text. A page in your base language, or
an element already marked, is left as it was.

## Catalog Snapshots

A snapshot is your catalog for chosen languages and categories, saved to a file, for
setups that shouldn't call the API while rendering: a mobile bundle, a first paint,
an offline or air-gapped deploy.

```bash
vendor/bin/langsys-snapshot --locale=es-es --locale=fr-fr --category=UI --category=Errors --out=catalog.snapshot.json
```

The command reads `LANGSYS_API_KEY` and `LANGSYS_PROJECT_ID`, or a `--config` file
returning `['client' => ...]`. It keeps exactly what the API returns for the
categories you name.

```php
use Langsys\SDK\Snapshot\Snapshot;

$snapshot = Snapshot::load('catalog.snapshot.json');
$spanish = $snapshot->catalog('es-es'); // category => entries, as the API returns them
```

A snapshot is a cache, never a source. **To refresh one, export it again.** Don't edit
it: every snapshot carries a checksum of its contents, and `Snapshot::load()` refuses
one that has changed since it was exported.

Every Langsys SDK writes and reads the same snapshot format, `langsys-catalog-snapshot`
version 1, so a snapshot exported here loads in another SDK and the other way round.
It carries the project's base locale (`$snapshot->baseLocale()`) along with the catalog.
A load that fails says why: `SnapshotException::getReason()` is `format`, `version`,
`missing-member` or `checksum`.

**Seed the client from a snapshot** to render with no API call:

```php
$client = new Client($key, $project, ['snapshot' => Snapshot::load('catalog.snapshot.json')]);
// or: $client->useSnapshot(Snapshot::load('catalog.snapshot.json'));
```

A phrase or block the snapshot holds renders from it without fetching. Anything it
doesn't hold is read from the live catalog, which from then on outranks the snapshot;
offline, it stays in the source language. Only the live catalog decides what is new
and registered. While the API can't be reached, the snapshot's languages are the ones
the request locale can resolve to.

## Error Handling

The SDK throws specific exceptions for different error types:

```php
use Langsys\SDK\Exception\LangsysException;
use Langsys\SDK\Exception\AuthenticationException;
use Langsys\SDK\Exception\ValidationException;
use Langsys\SDK\Exception\ApiException;

try {
    $client = new Client();
    $translations = $client->getTranslations('es-es');
} catch (AuthenticationException $e) {
    // 401 - Invalid or expired API key
    echo "Auth failed: " . $e->getMessage();
} catch (ValidationException $e) {
    // 422 - Invalid request data
    echo "Validation failed: " . $e->getMessage();
    print_r($e->getErrors());

    // The error's code and its message entries, when the API sends them
    echo $e->getErrorCode();
    foreach ($e->getServerMessages()->forField('name') as $entry) {
        echo $client->translateMessage($entry);
    }
} catch (ApiException $e) {
    // Other API errors (4xx, 5xx)
    echo "API error: " . $e->getMessage();
    echo "HTTP code: " . $e->getHttpStatusCode();
} catch (LangsysException $e) {
    // Base exception (configuration errors, etc.)
    echo "Error: " . $e->getMessage();
}
```

## Integration Example

Create a helper function for use in your templates:

```php
<?php
// helpers.php

use Langsys\SDK\Client;

function __($phrase, $category = '__uncategorized__')
{
    static $client = null;
    static $locale = null;

    if ($client === null) {
        $client = new Client();
        $locale = $_SESSION['locale'] ?? 'en-us';
    }

    return $client->translate($phrase, $locale, $category);
}

// Usage in templates:
echo __('Home', 'UI');
echo __('Welcome');
```

## Project Information

Get information about the connected project:

```php
$project = $client->getProject();

echo $project['id'];           // Project UUID
echo $project['title'];        // Project name
echo $project['base_locale'];  // e.g., "en-us"
echo $project['key_type'];     // "read", "write" or "ip_write"

print_r($project['target_locales']);  // ["es-es", "fr-ca", ...]
print_r($project['default_locales']); // Default locale per language
```

## Utilities

### Reference lists for your own forms

```php
$client->getCurrencies();          // [['code' => 'USD', 'name' => 'dólar estadounidense', 'symbol' => '$', ...], ...]
$client->getCountries('fr-fr');    // [['label' => 'Costa Rica', 'code' => 'CR', 'eu' => false], ...]
$client->getDialCodes();           // [['country_code' => 'CR', 'dial_code' => '+506', 'name' => 'Costa Rica (+506)'], ...]
$client->getLocalesFormat('flat'); // [['code' => 'es-cr', 'name' => 'Español (Costa Rica)'], ...]
```

Each list is named in the locale you pass, else the current one. They match the Langsys JS
SDK's lists of the same names. `getLocalesFormat()` takes `''` (grouped by language name),
`'flat'` (`code` and `name`) or `'data'` (`code`, `locale_name` and `lang_name`), answers the
list for that one display locale, and writes every locale code `es-cr` however your
organisation formats codes. A list is held per display locale, in memory and in the cache
for the catalog's lifetime (`cache_ttl`), so a later request sends nothing for it. A display
locale that is not shaped as a locale is refused, logged, and nothing is sent. If the API
cannot answer, or answers something that is not the list, you get an empty list and the
cause is logged; the list is not asked for again for 3 seconds, doubling on each failure to
5 minutes. A client without credentials sends nothing and gets an empty list. Nothing
throws, unless `require_credentials` is on and the client has none.

The `utilities()` resource below gives the raw, paginated endpoints. A display locale is
written into the request path as one segment; an empty one, `.` or `..` would reach another
route, so it throws an `InvalidArgumentException` and nothing is sent.

### Countries

```php
$utils = $client->utilities();

// Get paginated list of countries (names in Spanish)
$response = $utils->getCountries('es-es', ['page' => 1, 'records_per_page' => 25, 'order_by' => 'label:ASC']);
// Returns: ['status' => true, 'page' => 1, 'data' => [['label' => 'Costa Rica', 'code' => 'CR'], ...]]

// Get all countries without pagination
$countries = $utils->getAllCountries('en-us');
// Returns: [['label' => 'Costa Rica', 'code' => 'CR'], ...]

// Get countries as select options (code => label)
$options = $utils->getCountrySelectOptions('en-us');
// Returns: ['CR' => 'Costa Rica', 'US' => 'United States', ...]
```

### Dial Codes

```php
$utils = $client->utilities();

// Get paginated list of dial codes
$response = $utils->getDialCodes('en-us');
// Returns: ['status' => true, 'data' => [['country_code' => 'CR', 'dial_code' => '506', 'name' => 'Costa Rica (+506)'], ...]]

// Get all dial codes without pagination
$dialCodes = $utils->getAllDialCodes('en-us');

// Get dial codes as select options
$options = $utils->getDialCodeSelectOptions('en-us');
// Returns: ['CR' => 'Costa Rica (+506)', 'US' => 'United States (+1)', ...]
```

### Locales

```php
$utils = $client->utilities();

// Get locales grouped by language
$response = $utils->getLocalesGrouped(['en-us']);
// Returns: ['status' => true, 'data' => ['en-us' => ['Spanish' => [['code' => 'es-cr', 'name' => 'Spanish (Costa Rica)'], ...]]]]

// Get flat list of locales
$response = $utils->getLocalesFlat(['en-us']);
// Returns: ['status' => true, 'data' => ['en-us' => [['code' => 'es-cr', 'name' => 'Spanish (Costa Rica)'], ...]]]

// Get detailed locale information
$response = $utils->getLocalesDetailed(['en-us']);

// Get simple list for a single display locale
$locales = $utils->getLocaleList('en-us');
// Returns: [['code' => 'es-cr', 'name' => 'Spanish (Costa Rica)'], ...]

// Get locales as select options
$options = $utils->getLocaleSelectOptions('en-us');
// Returns: ['es-cr' => 'Spanish (Costa Rica)', 'fr-ca' => 'French (Canada)', ...]

// Include project's target locales
$options = $utils->getLocaleSelectOptions('en-us', true);
```

### Building Form Dropdowns

```php
$utils = $client->utilities();

// Country dropdown
echo '<select name="country">';
foreach ($utils->getCountrySelectOptions('en-us') as $code => $name) {
    echo "<option value=\"$code\">$name</option>";
}
echo '</select>';

// Phone dial code dropdown
echo '<select name="dial_code">';
foreach ($utils->getDialCodeSelectOptions('en-us') as $code => $name) {
    echo "<option value=\"$code\">$name</option>";
}
echo '</select>';

// Language/locale dropdown
echo '<select name="locale">';
foreach ($utils->getLocaleSelectOptions('en-us') as $code => $name) {
    echo "<option value=\"$code\">$name</option>";
}
echo '</select>';
```

## Testing

The SDK includes a comprehensive test suite using PHPUnit.

### Running Tests

```bash
# Install dev dependencies
composer install

# Run every test that needs nothing but this repository
vendor/bin/phpunit --testsuite no-clone

# Run specific test file
vendor/bin/phpunit tests/ConfigTest.php

# Run with coverage report (requires Xdebug)
vendor/bin/phpunit --testsuite no-clone --coverage-text
```

The contract tests in `tests/Contract` run against a real Langsys API on an SDK test clone,
reached through `tests/contract-fixture/`, and need Node 18 or later; without a clone named
they fail rather than skip. `vendor/bin/phpunit` runs both suites. [tests/README.md](tests/README.md)
says how to run them.


### Test Structure

```
tests/
├── bootstrap.php                    # Test autoloader
├── ConfigTest.php                   # Configuration tests
├── ClientTest.php                   # Main client tests
├── Mock/
│   └── MockHttpClient.php           # Mock HTTP client for testing
├── Cache/
│   ├── NullCacheTest.php            # Null cache tests
│   ├── FileCacheTest.php            # File cache tests
│   └── RedisCacheTest.php           # Redis cache tests (skipped if no ext)
├── Html/
│   ├── HtmlParserTest.php           # HTML phrase extraction tests
│   ├── HeadHandlerTest.php          # Head section translation tests
│   └── PageTranslatorTest.php       # Full page translation tests
├── Locale/
│   └── LocaleDetectorTest.php       # Locale detection tests
├── Resources/
│   ├── TranslationsTest.php         # Translations resource tests
│   ├── TranslatableItemsTest.php    # TranslatableItems resource tests
│   └── UtilitiesTest.php            # Utilities resource tests
├── Log/
│   ├── LoggerTest.php               # Logger tests (JSON Lines, level filtering)
│   ├── NullLoggerTest.php           # NullLogger tests
│   └── LogViewerTest.php            # LogViewer tests
└── Exception/
    └── ExceptionTest.php            # Exception class tests
```

### Test Coverage

- The no-clone suite uses a mock HTTP client or a loopback stand-in, and makes no real API
  calls
- The contract suite asserts on what a real Langsys API holds after each call
- Redis tests skip if the extension or a server is unavailable, and fail instead with
  `LANGSYS_REQUIRE_REDIS` set

## License

MIT
