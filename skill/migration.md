# Key-based language files, sync, and snapshots

## The `migration` option: keep calling by key

An app that calls translations by key (`checkout.submit`) and keeps its text in language files moves to Langsys without rewriting a call. Keep only the source-language files, and name them:

```php
$client = new Client($key, $project, [
    'migration' => [
        'files' => [
            ['path' => 'lang/en.json', 'format' => 'laravel'],
            'lang/en/checkout.php',
        ],
    ],
]);

$client->translate('checkout.submit');   // looks up "Place order", the text your file holds
```

- **A key resolves to its text, and the text is what registers and translates.** Later you can replace the key with its text and delete the file, and nothing else changes.
- **The key's group becomes the category**: `checkout.submit` registers under `checkout`, unless you pass one.
- **Anything that isn't a key is text**, in Langsys syntax: `translate('Pay now')` works as it does without the option.
- **Placeholders convert in every file**: `:name`, `{{name}}`, `{name}`, `%{name}`, `%(name)s` and `%(name)d` all become `{name}`, and `%%` is a literal `%`. A placeholder with number formatting (`%.2f`) or no name (`%s`) registers as written, with a warning, as does a capitalising `:Name`.
- **A key's number params print as plain digits**, as the source framework printed them: `© 2026`, never `© 2,026`, in every locale, `#` in a plural included; the plural still selects by the number. A call that is not a key hit formats numbers for the locale.
- **Plurals convert by the file's `format`**: `laravel`, `vue-i18n`, `i18next` or `plain`. The same characters mean different things (`car | cars` is a plural in vue-i18n and plain text in Laravel), so say which wrote each file. A PHP array file is `laravel` unless you say otherwise; a JSON file is `plain`, which converts no plurals. Any other format is refused when the `Client` is built.
- **A JSON file can sit under a namespace**: `['path' => 'locales/en/cart.json', 'namespace' => 'cart']` answers `cart.items.title`.
- **A framework's bundled strings go in `fallback_files`**, which answer only keys `files` doesn't define. Package keys (`courier::messages.welcome`) resolve through `'namespaces' => ['courier' => ['files' => [...]]]`.

Text that reaches a framework's own call without being a key is in that framework's syntax. `LegacyValue::fromCall($text, $replace, '__')`, or `'trans_choice'` with the count, turns it into the Langsys phrase and the params to render it with, as the framework reads it: only the placeholders the call passes convert, and `|` is a plural only through `trans_choice`. So `__('Hello :name', ['name' => $n])` and `translate('Hello {name}')` are one phrase.

`new Langsys\SDK\Migration\LegacyKeysSource($client->getLegacyKeys())` in your `langsys-messages.php` sources lists each value that can't be converted and each key defined in more than one file ([messages.md](./messages.md)).

## Import the translations you already have

Before deleting the target-language files, import them once, so their work shows in Langsys as human translations instead of being machine translated again:

```php
$result = $client->importLegacyTranslations([
    'es-es' => ['files' => ['lang/es.json', 'lang/es/checkout.php']],
    'fr-fr' => ['files' => ['lang/fr.json', 'lang/fr/checkout.php']],
]);
// ['success' => true, 'phrases' => 214, 'translations' => 401,
//  'human_translations_saved' => 401, 'human_translations_skipped' => 0, 'skipped' => [...]]
```

Each locale takes the `migration` option's shape. A locale the project doesn't target is refused before anything is sent. A translation that is missing, empty or can't be converted is listed in `skipped` with its key, locale and reason; its phrase still registers and is machine translated. Past the plan's human-translated words for the period, the rest are machine translated, counted in `human_translations_skipped`. A read-only request returns `success: false` with `reason: 'not_write_enabled'`.

## Sync: register from source code, not at runtime

An app behind a framework's translate function (`__()`, `trans()`) can register its phrases from a scan of its code, in a deploy step or CI, and turn runtime registration off. The scanner reads PHP: scan PHP source and compiled views, not template files. Raw Blade or Twig yields nothing, and with runtime registration off, nothing in those templates ever registers.

```php
use Langsys\SDK\Sync\SourceScanner;

$scanner = new SourceScanner();   // __, trans, t, trans_choice, Lang::get, Lang::choice
$hits = [];
foreach ($files as $path) {
    $hits = array_merge($hits, $scanner->scan(file_get_contents($path), $path));
}

$plan = $client->planSync($hits, ['es-es' => ['files' => ['lang/es.json']]]);
$plan->counts();        // ['in_catalog' => 120, 'with_translations' => 14, 'new' => 3]
$plan->reported;        // calls whose argument isn't a literal, with file and line
$plan->failsStrict();   // true when any call was reported
$client->applySync($plan);   // registers what the catalog lacks
```

- Only a literal is a phrase. `__($message)` is reported and never registered.
- A key built inside a literal group, `__("validation.$key")`, is in `$plan->covered`: every line of that group in your files registers anyway. Name a group whose lines register another way with `['covered_groups' => ['validation']]` as `planSync()`'s third argument.
- A phrase your other languages' files translate registers with those translations, as human translations.
- Renamed a key group, say `lang/en/header.php` to `checkout.php`? Set the Client's `previous_categories` option to `['checkout' => 'header']` (or pass it in `planSync()`'s third argument, which replaces the Client's), and every line registered under `checkout` moves from `header` with its translations, the lines no literal call names included. A hit's own `'previous_category'` wins over the map, and hits naming different ones are in `$plan->moveConflicts`, which fails `--strict` ([primitives.md](./primitives.md#moving-content-to-another-category)).
- A line holding a label placeholder (`:attribute`) is in `$plan->viaValidation`: it registers through your validation listing, once per field.
- A template-printed line (`getHtml()`, `choiceHtml()`) registers tokenized, `Read our {m0o}terms{m0c}`, the key `translateRich()` looks up.

With no API key, plan offline. Every phrase counts as new, and what a strict run fails on is still reported:

```php
use Langsys\SDK\Sync\Planner;

$plan = Planner::offline($hits, ['files' => ['lang/en.json']], [], ['covered_groups' => ['validation']]);
exit($plan->failsStrict() ? 1 : 0);
```

With no Client, pass the moves it would have taken from its options in the fourth argument, so the offline verdict matches the real sync: `previous_categories`, and `messages_category` with `messages_previous_category` for a renamed messages category.

## Language files as the fallback

```php
$client->useMissFallback(function ($phrase, $locale, $category, $argument) use ($framework) {
    return $framework->line($argument, $locale);   // the line, unfilled, or null
});

$client->resolve('Welcome, {name}', 'es-es', null, ['name' => 'Ana']);
// ['text' => 'Bienvenido, Ana', 'from' => 'catalog' | 'fallback' | 'source']
```

A phrase resolves to the catalog's translation, then to your file's line, then to the source, filled with its params either way. While the catalog can't be read, `translate()` and `resolve()` carry on down that chain without throwing. The fallback is asked only for a locale: one set with `setLocale()` or passed to the call. With neither, and no request locale that can be read (a client without credentials, or an API that can't authorize), a phrase goes straight to its source. `from` says who wrote the text. Print `catalog` text escaped, even where your framework prints its own lines raw: a translator wrote it.

## Catalog snapshots

A snapshot is the catalog for chosen locales and categories, saved to a file, for a render that shouldn't call the API: a first paint, a mobile bundle, an offline deploy.

```bash
vendor/bin/langsys-snapshot --locale=es-es --locale=fr-fr --category=UI --category=Errors --out=catalog.snapshot.json
```

```php
use Langsys\SDK\Snapshot\Snapshot;

$client = new Client($key, $project, ['snapshot' => Snapshot::load('catalog.snapshot.json')]);
// or: $client->useSnapshot(Snapshot::load('catalog.snapshot.json'));
```

- What the snapshot holds renders without a fetch. Anything else is read from the live catalog, which from then on outranks the snapshot; offline, it stays in the source language.
- Only the live catalog decides what is new and registered.
- `Snapshot::load()` throws a `SnapshotException` for a missing, edited or unreadable file, so load it where that is handled: inside the `Client`'s options, a snapshot file a deploy left out fails every request. A snapshot exported for another project is ignored, with a warning.
- **A snapshot is a cache, never a source. To refresh one, export it again.** Every snapshot carries a checksum, and `Snapshot::load()` refuses one edited since its export. `SnapshotException::getReason()` says why a load failed: `format`, `version`, `missing-member` or `checksum`.
- Every Langsys SDK reads and writes the same format, `langsys-catalog-snapshot` version 1, so a snapshot exported here loads in a JavaScript SDK and the other way round.

The command reads `LANGSYS_API_KEY` and `LANGSYS_PROJECT_ID`, or a `--config` file returning `['client' => ...]`. `Snapshot::export($client, $locales, $categories)` does the same from code.
