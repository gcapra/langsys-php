<?php

namespace Langsys\SDK\Tests;

use Langsys\SDK\Client;
use Langsys\SDK\Cache\NullCache;
use Langsys\SDK\Exception\LangsysException;
use Langsys\SDK\Html\HtmlParser;
use Langsys\SDK\Tests\Mock\MockHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the main Client class.
 *
 * Note: These tests require mocking the HTTP client, which is done by extending
 * the Client class to inject our mock. For more thorough testing, the Client class
 * could be refactored to accept an HTTP client via dependency injection.
 */
class ClientTest extends TestCase
{
    /**
     * @var array Environment variables to restore after tests
     */
    protected $originalEnv = [];

    protected function setUp(): void
    {
        $this->originalEnv = [
            'LANGSYS_API_KEY' => getenv('LANGSYS_API_KEY'),
            'LANGSYS_PROJECT_ID' => getenv('LANGSYS_PROJECT_ID'),
        ];

        // Clear env vars for clean tests
        putenv('LANGSYS_API_KEY');
        putenv('LANGSYS_PROJECT_ID');
    }

    protected function tearDown(): void
    {
        // Restore original env vars
        foreach ($this->originalEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $value);
            }
        }
    }

    public function testConstructorWithOptions()
    {
        $client = new Client('test-api-key', 'test-project-id', [
            'cache' => new NullCache(),
        ]);

        $this->assertInstanceOf(Client::class, $client);
        $this->assertEquals('test-project-id', $client->getConfig()->getProjectId());
        $this->assertEquals('test-api-key', $client->getConfig()->getApiKey());
    }

    public function testConstructorWithEnvironmentVariables()
    {
        putenv('LANGSYS_API_KEY=env-api-key');
        putenv('LANGSYS_PROJECT_ID=env-project-id');

        $client = new Client(null, null, [
            'cache' => new NullCache(),
        ]);

        $this->assertEquals('env-api-key', $client->getConfig()->getApiKey());
        $this->assertEquals('env-project-id', $client->getConfig()->getProjectId());
    }

    public function testConstructorMissingApiKeyWithRequireCredentials()
    {
        $this->expectException(LangsysException::class);
        $this->expectExceptionMessage('API key is required');

        new Client(null, 'project-id', ['require_credentials' => true]);
    }

    public function testConstructorMissingProjectIdWithRequireCredentials()
    {
        $this->expectException(LangsysException::class);
        $this->expectExceptionMessage('Project ID is required');

        new Client('api-key', null, ['require_credentials' => true]);
    }

    public function testGetCacheReturnsCache()
    {
        $cache = new NullCache();
        $client = new Client('api-key', 'project-id', [
            'cache' => $cache,
        ]);

        $this->assertSame($cache, $client->getCache());
    }

    public function testGetConfigReturnsConfig()
    {
        $client = new Client('api-key', 'project-id', [
            'cache' => new NullCache(),
        ]);

        $config = $client->getConfig();

        $this->assertEquals('api-key', $config->getApiKey());
        $this->assertEquals('project-id', $config->getProjectId());
    }

    public function testTranslationsResource()
    {
        $client = new Client('api-key', 'project-id', [
            'cache' => new NullCache(),
        ]);

        $translations = $client->translations();

        $this->assertInstanceOf(\Langsys\SDK\Resources\Translations::class, $translations);
    }

    public function testTranslatableItemsResource()
    {
        $client = new Client('api-key', 'project-id', [
            'cache' => new NullCache(),
        ]);

        $items = $client->translatableItems();

        $this->assertInstanceOf(\Langsys\SDK\Resources\TranslatableItems::class, $items);
    }

    public function testUtilitiesResource()
    {
        $client = new Client('api-key', 'project-id', [
            'cache' => new NullCache(),
        ]);

        $utilities = $client->utilities();

        $this->assertInstanceOf(\Langsys\SDK\Resources\Utilities::class, $utilities);
    }

    public function testClearCacheSpecificLocale()
    {
        $client = new Client('api-key', 'project-id', [
            'cache_driver' => 'none',
        ]);

        // Should not throw
        $result = $client->clearCache('es-es');
        $this->assertTrue($result);
    }

    public function testClearCacheAll()
    {
        $client = new Client('api-key', 'project-id', [
            'cache_driver' => 'none',
        ]);

        // Should not throw
        $result = $client->clearCache();
        $this->assertTrue($result);
    }

    public function testCacheDriverNone()
    {
        $client = new Client('api-key', 'project-id', [
            'cache_driver' => 'none',
        ]);

        $this->assertInstanceOf(NullCache::class, $client->getCache());
    }

    public function testCacheDriverNull()
    {
        $client = new Client('api-key', 'project-id', [
            'cache_driver' => 'null',
        ]);

        $this->assertInstanceOf(NullCache::class, $client->getCache());
    }

    public function testCacheDriverFile()
    {
        $cachePath = sys_get_temp_dir() . '/langsys-test-' . uniqid();

        $client = new Client('api-key', 'project-id', [
            'cache_driver' => 'file',
            'cache_path' => $cachePath,
        ]);

        $this->assertInstanceOf(\Langsys\SDK\Cache\FileCache::class, $client->getCache());

        // Clean up
        if (is_dir($cachePath)) {
            rmdir($cachePath);
        }
    }

    public function testCustomCacheInstance()
    {
        $customCache = new NullCache();

        $client = new Client('api-key', 'project-id', [
            'cache' => $customCache,
        ]);

        $this->assertSame($customCache, $client->getCache());
    }

    // =========================================================================
    // translate() with queuing tests
    // =========================================================================

    public function testTranslateQueuesNewPhrase()
    {
        $mockHttp = new MockHttpClient();
        $mockHttp->setResponse('GET', 'authorize-project/project-id', [
            'data' => ['key_type' => 'write'],
        ]);
        $mockHttp->setResponse('GET', 'translations', [
            'data' => [
                '__uncategorized__' => [],
            ],
        ]);

        $client = $this->createClientWithMockHttp($mockHttp);
        $client->setLocale('es-es');

        // Translate a phrase that doesn't exist
        $result = $client->translate('Hello');

        // Should return original (no translation)
        $this->assertEquals('Hello', $result);

        // Should be queued for registration
        $this->assertTrue($client->hasPendingRegistrations());
        $pending = $client->getPendingPhrases();
        $this->assertArrayHasKey('__uncategorized__::Hello', $pending);
    }

    public function testTranslateDoesNotQueueExistingPhrase()
    {
        $mockHttp = new MockHttpClient();
        $mockHttp->setResponse('GET', 'translations', [
            'data' => [
                '__uncategorized__' => [
                    'Hello' => 'Hola',
                ],
            ],
        ]);

        $client = $this->createClientWithMockHttp($mockHttp);
        $client->setLocale('es-es');

        // Translate a phrase that exists
        $result = $client->translate('Hello');

        // Should return translation
        $this->assertEquals('Hola', $result);

        // Should NOT be queued
        $this->assertFalse($client->hasPendingRegistrations());
    }

    public function testTranslateSamePhraseNotQueuedTwice()
    {
        $mockHttp = new MockHttpClient();
        $mockHttp->setResponse('GET', 'translations', [
            'data' => [
                '__uncategorized__' => [],
            ],
        ]);

        $client = $this->createClientWithMockHttp($mockHttp);
        $client->setLocale('es-es');

        // Translate same phrase twice
        $client->translate('Hello');
        $client->translate('Hello');

        // Should only be queued once
        $pending = $client->getPendingPhrases();
        $this->assertCount(1, $pending);
    }

    // =========================================================================
    // translateContentBlock() tests
    // =========================================================================

    public function testTranslateContentBlockWithTranslations()
    {
        $mockHttp = new MockHttpClient();

        // Derive the id rather than hardcoding a hash, so the test survives a
        // change to the hashing scheme (it must stay in sync with the JS SDKs).
        $customId = (new HtmlParser())->generateCustomId('__uncategorized__', ['Hello', 'World']);

        $mockHttp->setResponse('GET', 'translations', [
            'data' => [
                '__uncategorized__' => [
                    $customId => [
                        'Hello' => 'Hola',
                        'World' => 'Mundo',
                    ],
                ],
            ],
        ]);

        $client = $this->createClientWithMockHttp($mockHttp);
        $client->setLocale('es-es');

        $html = '<p>Hello</p><p>World</p>';
        $result = $client->translateContentBlock($html);

        // Should contain translated text
        $this->assertStringContainsString('Hola', $result);
        $this->assertStringContainsString('Mundo', $result);

        // Should NOT be queued (already exists)
        $this->assertFalse($client->hasPendingRegistrations());
    }

    public function testTranslateContentBlockQueuesNewBlock()
    {
        $mockHttp = new MockHttpClient();
        $mockHttp->setResponse('GET', 'authorize-project/project-id', [
            'data' => ['key_type' => 'write'],
        ]);
        $mockHttp->setResponse('GET', 'translations', [
            'data' => [
                '__uncategorized__' => [],
            ],
        ]);

        $client = $this->createClientWithMockHttp($mockHttp);
        $client->setLocale('es-es');

        $html = '<p>Hello</p><p>World</p>';
        $result = $client->translateContentBlock($html);

        // Should return original HTML (no translations yet)
        $this->assertEquals($html, $result);

        // Should be queued for registration
        $this->assertTrue($client->hasPendingRegistrations());
        $pending = $client->getPendingContentBlocks();
        $this->assertCount(1, $pending);
    }

    public function testTranslateContentBlockWithCategory()
    {
        $mockHttp = new MockHttpClient();

        $customId = (new HtmlParser())->generateCustomId('homepage', ['Hello']);

        $mockHttp->setResponse('GET', 'translations', [
            'data' => [
                'homepage' => [
                    $customId => [
                        'Hello' => 'Hola',
                    ],
                ],
            ],
        ]);

        $client = $this->createClientWithMockHttp($mockHttp);
        $client->setLocale('es-es');

        $html = '<p>Hello</p>';
        $result = $client->translateContentBlock($html, 'homepage');

        $this->assertStringContainsString('Hola', $result);
    }

    public function testTranslateContentBlockSameBlockNotQueuedTwice()
    {
        $mockHttp = new MockHttpClient();
        $mockHttp->setResponse('GET', 'translations', [
            'data' => [
                '__uncategorized__' => [],
            ],
        ]);

        $client = $this->createClientWithMockHttp($mockHttp);
        $client->setLocale('es-es');

        $html = '<p>Hello</p>';

        // Translate same block twice
        $client->translateContentBlock($html);
        $client->translateContentBlock($html);

        // Should only be queued once
        $pending = $client->getPendingContentBlocks();
        $this->assertCount(1, $pending);
    }

    // =========================================================================
    // In-memory cache tests
    // =========================================================================

    public function testInMemoryCachePreventsRequeue()
    {
        $mockHttp = new MockHttpClient();
        $mockHttp->setResponse('GET', 'translations', [
            'data' => [
                '__uncategorized__' => [],
            ],
        ]);

        $client = $this->createClientWithMockHttp($mockHttp);
        $client->setLocale('es-es');

        // First translate - queues the phrase and adds to memory cache
        $client->translate('Hello');

        // Second translate - should find in memory cache, not re-queue
        $client->translate('Hello');

        // Only one pending phrase
        $this->assertCount(1, $client->getPendingPhrases());
    }

    public function testClearCacheClearsMemoryCache()
    {
        $mockHttp = new MockHttpClient();

        $client = $this->createClientWithMockHttp($mockHttp);
        $client->setLocale('es-es');

        // First call - set response before call
        $mockHttp->setResponse('GET', 'translations', [
            'data' => [
                '__uncategorized__' => ['Hello' => 'Hola'],
            ],
        ]);
        $client->getTranslations('es-es');

        // Clear cache
        $client->clearCache('es-es');

        // Update response for re-fetch
        $mockHttp->setResponse('GET', 'translations', [
            'data' => [
                '__uncategorized__' => ['Hello' => 'Hola Updated'],
            ],
        ]);

        // Next call should fetch from API again
        $translations = $client->getTranslations('es-es');
        $this->assertEquals('Hola Updated', $translations['__uncategorized__']['Hello']);
    }

    // =========================================================================
    // flushPendingRegistrations() tests
    // =========================================================================

    public function testFlushPendingRegistrationsWithPhrases()
    {
        $mockHttp = new MockHttpClient();
        $mockHttp->setResponse('GET', 'authorize-project/project-id', [
            'data' => ['key_type' => 'write'],
        ]);
        $mockHttp->setResponse('GET', 'translations', [
            'data' => ['__uncategorized__' => []],
        ]);
        $mockHttp->setResponse('POST', 'translatable-items', [
            'status' => true,
        ]);

        $client = $this->createClientWithMockHttp($mockHttp);
        $client->setLocale('es-es');

        // Queue some phrases
        $client->translate('Hello');
        $client->translate('World');

        $this->assertTrue($client->hasPendingRegistrations());

        // Flush
        $result = $client->flushPendingRegistrations();

        $this->assertEquals(2, $result['phrases']);
        $this->assertEquals(0, $result['content_blocks']);
        $this->assertTrue($result['success']);
        $this->assertFalse($client->hasPendingRegistrations());
    }

    public function testFlushPendingRegistrationsWithReadOnlyKey()
    {
        $mockHttp = new MockHttpClient();
        $mockHttp->setResponse('GET', 'authorize-project/project-id', [
            'data' => ['key_type' => 'read'],
        ]);
        $mockHttp->setResponse('GET', 'translations', [
            'data' => ['__uncategorized__' => []],
        ]);

        $client = $this->createClientWithMockHttp($mockHttp);
        $client->setLocale('es-es');

        // Queue a phrase
        $client->translate('Hello');

        // Flush - should silently skip (read-only key)
        $result = $client->flushPendingRegistrations();

        $this->assertEquals(0, $result['phrases']);
        $this->assertFalse($client->hasPendingRegistrations()); // Queue cleared
    }

    public function testClearPendingRegistrations()
    {
        $mockHttp = new MockHttpClient();
        $mockHttp->setResponse('GET', 'translations', [
            'data' => ['__uncategorized__' => []],
        ]);

        $client = $this->createClientWithMockHttp($mockHttp);
        $client->setLocale('es-es');

        $client->translate('Hello');
        $this->assertTrue($client->hasPendingRegistrations());

        $client->clearPendingRegistrations();
        $this->assertFalse($client->hasPendingRegistrations());
    }

    public function testAuthorizeSyncsBatchLimitFromLangsysSettings()
    {
        $mockHttp = new MockHttpClient();
        $mockHttp->setResponse('GET', 'authorize-project/project-id', [
            'data' => [
                'key_type' => 'write',
                'langsys_settings' => [
                    'batch_limit' => 50,
                ],
            ],
        ]);

        $client = $this->createClientWithMockHttp($mockHttp);
        $client->authorize();

        $this->assertEquals(50, $client->translatableItems()->getBatchLimit());
    }

    public function testAuthorizeFallsBackToDefaultBatchLimit()
    {
        $mockHttp = new MockHttpClient();
        $mockHttp->setResponse('GET', 'authorize-project/project-id', [
            'data' => ['key_type' => 'write'],
        ]);

        $client = $this->createClientWithMockHttp($mockHttp);
        $client->authorize();

        $this->assertEquals(200, $client->translatableItems()->getBatchLimit());
    }

    // =========================================================================
    // Helper methods
    // =========================================================================

    /**
     * Create a client with injected mock HTTP client.
     */
    private function createClientWithMockHttp(MockHttpClient $mockHttp)
    {
        $client = new Client('test-api-key', 'project-id', [
            'cache' => new NullCache(),
        ]);

        // Use reflection to inject mock HTTP client
        $reflection = new \ReflectionClass($client);

        $httpProperty = $reflection->getProperty('http');
        $httpProperty->setAccessible(true);
        $httpProperty->setValue($client, $mockHttp);

        // Inject into translations resource
        $transProperty = $reflection->getProperty('translations');
        $transProperty->setAccessible(true);
        $translations = $transProperty->getValue($client);

        $transReflection = new \ReflectionClass($translations);
        $transHttpProperty = $transReflection->getProperty('http');
        $transHttpProperty->setAccessible(true);
        $transHttpProperty->setValue($translations, $mockHttp);

        // Inject into translatableItems resource
        $itemsProperty = $reflection->getProperty('translatableItems');
        $itemsProperty->setAccessible(true);
        $items = $itemsProperty->getValue($client);

        $itemsReflection = new \ReflectionClass($items);
        $itemsHttpProperty = $itemsReflection->getProperty('http');
        $itemsHttpProperty->setAccessible(true);
        $itemsHttpProperty->setValue($items, $mockHttp);

        return $client;
    }
}
