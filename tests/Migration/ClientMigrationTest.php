<?php

namespace Langsys\SDK\Tests\Migration;

use Langsys\SDK\Tests\Support\BuildsMockClient;
use Langsys\SDK\Tests\Support\SpyLogger;
use PHPUnit\Framework\TestCase;

/**
 * MIG-1, 2, 3, 5 and 6 on the client: in the mode, translate() resolves its
 * argument as a key first and registers the source value, never the key; with
 * no mode it never reads a file.
 */
class ClientMigrationTest extends TestCase
{
    use BuildsMockClient;

    /** @var string */
    private $dir;

    /** @var string */
    private $cwd;

    protected function setUp(): void
    {
        $this->cwd = getcwd();
        $this->dir = sys_get_temp_dir() . '/langsys-mig-client-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/lang/en', 0777, true);
        file_put_contents($this->dir . '/lang/en.json', json_encode([
            'checkout' => ['submit' => 'Place order', 'greeting' => 'Hello :name'],
            'cart' => ['items_one' => '{{count}} item', 'items_other' => '{{count}} items'],
        ]));
        file_put_contents($this->dir . '/lang/en/greeting.php', '<?php return ["hello" => "Hello, :Name"];');
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        @unlink($this->dir . '/lang/en.json');
        @unlink($this->dir . '/lang/en/greeting.php');
        @rmdir($this->dir . '/lang/en');
        @rmdir($this->dir . '/lang');
        @rmdir($this->dir);
    }

    private function inMode(array $catalog = [], array $options = [])
    {
        $client = $this->mockClient($catalog, array_merge(['migration' => ['files' => [['path' => $this->dir . '/lang/en.json', 'format' => 'i18next'], $this->dir . '/lang/en/greeting.php']]], $options));
        $client->setLocale('es-es');

        return $client;
    }

    /**
     * WIRE-4 in the mode: a configured PHP file that fails to load leaves
     * translate() rendering, never throwing.
     */
    public function testALangFileThatFailsToLoadLeavesTranslateRendering(): void
    {
        file_put_contents($this->dir . '/lang/en/greeting.php', '<?php return [;');
        $client = $this->inMode(['__uncategorized__' => ['Some literal text' => 'Algo de texto']]);

        $this->assertSame('Algo de texto', $client->translate('Some literal text'));
        $this->assertSame('greeting.hello', $client->translate('greeting.hello'), 'a key in the broken file is not found');
    }

    private function queued($client)
    {
        return array_map(function ($p) {
            return [$p['category'], $p['phrase']];
        }, array_values($client->getPendingPhrases()));
    }

    /**
     * MIG-1: with no mode nothing is read and nothing is looked up - even with a
     * source file sitting exactly where an app would keep one.
     */
    public function testWithNoModeTheArgumentIsNeverTreatedAsAKey(): void
    {
        chdir($this->dir);
        $client = $this->mockClient(['__uncategorized__' => []]);
        $client->setLocale('es-es');

        $this->assertSame('checkout.submit', $client->translate('checkout.submit'));
        $this->assertSame([['__uncategorized__', 'checkout.submit']], $this->queued($client));
        $this->assertNull($client->getLegacyKeys());
    }

    /**
     * MIG-2 and MIG-3: a key hit is its source value, registered as the value and
     * rendered from the value's translation; the key never reaches the catalog.
     */
    public function testAKeyResolvesToItsSourceValueAndTheValueIsWhatRegisters(): void
    {
        $client = $this->inMode(['checkout' => []]);

        $this->assertSame('Place order', $client->translate('checkout.submit'));
        $this->assertSame([['checkout', 'Place order']], $this->queued($client));

        $translated = $this->inMode(['checkout' => ['Place order' => 'Realizar pedido']]);
        $this->assertSame('Realizar pedido', $translated->translate('checkout.submit'));
    }

    public function testTheKeyRegistersExactlyAsTheSourceTextWouldHave(): void
    {
        $viaKey = $this->inMode(['checkout' => []]);
        $viaKey->translate('checkout.submit');

        $direct = $this->mockClient(['checkout' => []]);
        $direct->setLocale('es-es');
        $direct->translate('Place order', null, 'checkout');

        $this->assertSame($this->queued($direct), $this->queued($viaKey));
    }

    /**
     * MIG-2 and MIG-6: an argument that is not a key is literal source text,
     * registered as usual, and the miss is logged so a typo'd key is visible.
     */
    public function testAMissIsLiteralTextAndIsLogged(): void
    {
        $logger = new SpyLogger();
        $client = $this->inMode(['__uncategorized__' => []], ['logger' => $logger]);

        $this->assertSame('Pay now', $client->translate('Pay now'));
        $this->assertSame([['__uncategorized__', 'Pay now']], $this->queued($client));

        $client->translate('checkout.sumbit');
        $this->assertContains(['__uncategorized__', 'checkout.sumbit'], $this->queued($client));

        $logged = array_filter($logger->entries, function ($entry) {
            return $entry['level'] === 'debug' && isset($entry['context']['argument']) && $entry['context']['argument'] === 'checkout.sumbit';
        });
        $this->assertNotEmpty($logged, 'the miss is visible at debug');

        // translate() is Langsys t(): a miss is already Langsys syntax, so a
        // Laravel-shaped argument registers exactly as written.
        $client->translate('Hello :name', null, '__uncategorized__', null, ['name' => 'Ana']);
        $this->assertContains(['__uncategorized__', 'Hello :name'], $this->queued($client));
    }

    /**
     * MIG-5: the key's namespace is the category unless the call passes one.
     */
    public function testAnExplicitCategoryWinsOverTheNamespace(): void
    {
        $client = $this->inMode(['UI' => []]);
        $client->translate('checkout.submit', null, 'UI');

        $this->assertSame([['UI', 'Place order']], $this->queued($client));
    }

    /**
     * MIG-4 on the client: converted placeholders fill from the caller's params,
     * and a converted plural renders through ICU.
     */
    public function testConvertedPlaceholdersAndPluralsRenderFromParams(): void
    {
        $client = $this->inMode(['checkout' => [], 'cart' => []]);

        $this->assertSame('Hello Sarah', $client->translate('checkout.greeting', null, null, null, ['name' => 'Sarah']));
        $this->assertSame('1 item', $client->translate('cart.items', null, null, null, ['count' => 1]));
        $this->assertSame('3 items', $client->translate('cart.items', null, null, null, ['count' => 3]));
        $this->assertContains(['checkout', 'Hello {name}'], $this->queued($client));
    }

    public function testAnUnrecognisedValueRegistersAsWrittenWithAWarning(): void
    {
        $logger = new SpyLogger();
        $client = $this->inMode(['greeting' => []], ['logger' => $logger]);

        $client->translate('greeting.hello');

        $this->assertSame([['greeting', 'Hello, :Name']], $this->queued($client));
        $this->assertNotEmpty(array_filter($logger->entries, function ($entry) {
            return $entry['level'] === 'warning' && isset($entry['context']['key']) && $entry['context']['key'] === 'greeting.hello';
        }));
    }

    /**
     * A package key never takes the literal path: registering `courier::...` as
     * a phrase is the key-shaped registration MIG-6 exists to prevent.
     */
    public function testAPackageKeyThatDoesNotResolveRegistersNothing(): void
    {
        $client = $this->inMode(['__uncategorized__' => []]);

        $this->assertSame('courier::messages.welcome', $client->translate('courier::messages.welcome'));
        $this->assertSame([], $this->queued($client));
    }

    /**
     * Only key-shaped text is a package key: a sentence that holds `::` is
     * looked up, filled and registered like any other.
     */
    public function testASentenceHoldingADoubleColonIsLiteralText(): void
    {
        $client = $this->inMode(['__uncategorized__' => ['Call {cls}::create() to start' => 'Llama a {cls}::create() para empezar']]);

        $this->assertSame('Llama a Order::create() para empezar', $client->translate('Call {cls}::create() to start', null, null, null, ['cls' => 'Order']));

        $client->translate('Use Foo::bar() here');
        $this->assertSame([['__uncategorized__', 'Use Foo::bar() here']], $this->queued($client));
    }

    /**
     * MIG-6: a value changed in the file is simply a new phrase.
     */
    public function testAChangedValueRegistersAsANewPhrase(): void
    {
        $before = $this->inMode(['checkout' => ['Place order' => null]]);
        $before->translate('checkout.submit');
        $this->assertSame([], $this->queued($before), 'the registered value is known');

        file_put_contents($this->dir . '/lang/en.json', json_encode(['checkout' => ['submit' => 'Complete purchase']]));
        $after = $this->inMode(['checkout' => ['Place order' => null]]);
        $after->translate('checkout.submit');
        $this->assertSame([['checkout', 'Complete purchase']], $this->queued($after));
    }

    public function testResolveLegacyKeyExposesTheEntryForABinding(): void
    {
        $client = $this->inMode();

        $this->assertSame(['phrase' => 'Place order', 'category' => 'checkout', 'key' => 'checkout.submit', 'file' => $this->dir . '/lang/en.json', 'recognised' => true, 'issue' => null], $client->resolveLegacyKey('checkout.submit'));
        $this->assertSame('UI', $client->resolveLegacyKey('checkout.submit', 'UI')['category']);
        $this->assertNull($client->resolveLegacyKey('Pay now'));
    }

    /**
     * MIG-4: a key hit prints its number params as plain digits, as i18next
     * does, its translation too.
     */
    public function testAKeyHitPrintsItsNumberParamsAsPlainDigits(): void
    {
        file_put_contents($this->dir . '/lang/en.json', json_encode([
            'footer' => ['copyright' => '© {{year}} Northwind'],
            'cart' => ['items_one' => '{{count}} item', 'items_other' => '{{count}} items'],
        ]));
        $client = $this->inMode(['footer' => ['© {year} Northwind' => '© {year} Northwind, tous droits réservés'], 'cart' => []]);

        foreach (['en-us', 'fr-fr', 'es-es'] as $locale) {
            $client->setLocale($locale);
            $this->assertStringStartsWith('© 2026 Northwind', $client->translate('footer.copyright', null, null, null, ['year' => 2026]), $locale);
        }

        $client->setLocale('en-us');
        $this->assertSame('1234 items', $client->translate('cart.items', null, null, null, ['count' => 1234]));
        $this->assertSame('1 item', $client->translate('cart.items', null, null, null, ['count' => 1]));
    }

    /**
     * The same text as a literal, or a call no key answers, is grouped for the
     * locale as before. Grouping needs ext-intl: without it no number is grouped.
     */
    public function testALiteralOrAMissStillGroupsItsNumberParams(): void
    {
        if (!extension_loaded('intl')) {
            $this->markTestSkipped('ext-intl is not available');
        }

        file_put_contents($this->dir . '/lang/en.json', json_encode([
            'footer' => ['copyright' => '© {{year}} Northwind'],
        ]));
        $client = $this->inMode(['footer' => ['© {year} Northwind' => '© {year} Northwind, tous droits réservés']]);
        $client->setLocale('en-us');

        $this->assertStringStartsWith('© 2,026 Northwind', $client->translate('© {year} Northwind', null, 'footer', null, ['year' => 2026]), 'a literal is grouped');
        $this->assertSame('Order 12,345', $client->translate('Order {id}', null, null, null, ['id' => 12345]), 'a miss is grouped');
    }

}
