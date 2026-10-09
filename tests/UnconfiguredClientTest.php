<?php

namespace Langsys\SDK\Tests;

use Langsys\SDK\Cache\NullCache;
use Langsys\SDK\Client;
use Langsys\SDK\Exception\LangsysException;
use Langsys\SDK\Tests\Mock\MockHttpClient;
use Langsys\SDK\Tests\Support\SpyLogger;
use PHPUnit\Framework\TestCase;

/**
 * A client built without an API key or project ID.
 *
 * It used to throw from the constructor, which took down whatever was building
 * it: a Laravel app 500'd on every page until its .env was filled in, because
 * the service container resolves the client while rendering. Unconfigured is a
 * normal state - a fresh checkout, a CI job, a preview environment - and the
 * right behaviour is the one a missing translation already has: show the
 * source text. One warning says what to set; the API is never called.
 */
class UnconfiguredClientTest extends TestCase
{
    /**
     * @var \ReflectionProperty
     */
    protected $warnedFlag;

    protected function setUp(): void
    {
        putenv('LANGSYS_API_KEY');
        putenv('LANGSYS_PROJECT_ID');

        $this->warnedFlag = new \ReflectionProperty(Client::class, 'unconfiguredWarned');
        $this->warnedFlag->setAccessible(true);
        $this->warnedFlag->setValue(null, false);
    }

    protected function tearDown(): void
    {
        // Back to the suppressed state the bootstrap set up.
        $this->warnedFlag->setValue(null, true);
    }

    protected function makeClient($apiKey = null, $projectId = null, array $options = [])
    {
        return new Client($apiKey, $projectId, array_merge([
            'cache' => new NullCache(),
            // Keep the error_log leg out of the test output; the logger leg is
            // what these tests assert on.
            'warn_runtime_requirements' => false,
        ], $options));
    }

    /**
     * Swap in an HTTP client that records every request, everywhere the client
     * keeps one.
     */
    protected function injectHttp(Client $client, MockHttpClient $http)
    {
        $reflection = new \ReflectionClass($client);
        foreach (['http'] as $name) {
            $property = $reflection->getProperty($name);
            $property->setAccessible(true);
            $property->setValue($client, $http);
        }
        foreach (['translations', 'translatableItems', 'utilities'] as $name) {
            $property = $reflection->getProperty($name);
            $property->setAccessible(true);
            $resource = $property->getValue($client);
            $inner = (new \ReflectionClass($resource))->getProperty('http');
            $inner->setAccessible(true);
            $inner->setValue($resource, $http);
        }
    }

    public function testMissingCredentialsDoNotThrow()
    {
        $client = $this->makeClient();

        $this->assertFalse($client->isConfigured());
    }

    public function testConfiguredClientReportsConfigured()
    {
        $this->assertTrue($this->makeClient('api-key', 'project-id')->isConfigured());
    }

    public function testMissingProjectIdAloneIsUnconfigured()
    {
        $this->assertFalse($this->makeClient('api-key', null)->isConfigured());
    }

    public function testTranslateRendersSourceTextWithParams()
    {
        $client = $this->makeClient();

        $this->assertSame(
            'Hello, Sarah!',
            $client->translate('Hello, {name}!', 'es-es', 'Greetings', null, ['name' => 'Sarah'])
        );
        $this->assertSame('Checkout', $client->translate('Checkout', 'fr-fr'));
    }

    public function testNothingIsFetchedOrQueued()
    {
        $client = $this->makeClient();
        $http = new MockHttpClient();
        $this->injectHttp($client, $http);

        $client->setLocale('es-es');
        $client->translate('Checkout');
        $client->translateContentBlock('<p>Welcome back</p>', 'Home');
        $this->assertSame([], $client->getTranslations('es-es'));
        $this->assertFalse($client->canWrite());

        $this->assertFalse($client->hasPendingRegistrations());
        $this->assertSame(
            ['phrases' => 0, 'content_blocks' => 0, 'success' => true],
            $client->flushPendingRegistrations()
        );
        $this->assertSame([], $http->getRequests(), 'an unconfigured client must never call the API');
    }

    public function testWarnsOncePerProcessAndNamesWhatIsMissing()
    {
        $logger = new SpyLogger();

        $this->makeClient(null, null, ['logger' => $logger]);
        $this->makeClient(null, null, ['logger' => $logger]);

        $warnings = $logger->messagesAt('warning');
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('not configured', $warnings[0]);
        $this->assertStringContainsString('LANGSYS_API_KEY', $warnings[0]);
        $this->assertStringContainsString('LANGSYS_PROJECT_ID', $warnings[0]);
    }

    public function testWarningNamesOnlyTheMissingCredential()
    {
        $logger = new SpyLogger();

        $this->makeClient('api-key', null, ['logger' => $logger]);

        $warnings = $logger->messagesAt('warning');
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('LANGSYS_PROJECT_ID', $warnings[0]);
        $this->assertStringNotContainsString('LANGSYS_API_KEY', $warnings[0]);
    }

    public function testConfiguredClientDoesNotWarn()
    {
        $logger = new SpyLogger();

        $this->makeClient('api-key', 'project-id', ['logger' => $logger]);

        $this->assertSame([], $logger->messagesAt('warning'));
    }

    public function testExplicitRegistrationSaysWhyItCannot()
    {
        $client = $this->makeClient();

        $this->expectException(LangsysException::class);
        $this->expectExceptionMessage('not configured');

        $client->registerPhrases(['Checkout']);
    }

    public function testExplicitContentBlockRegistrationSaysWhyItCannot()
    {
        $client = $this->makeClient();

        $this->expectException(LangsysException::class);
        $this->expectExceptionMessage('not configured');

        $client->registerContentBlock('<p>Hi</p>');
    }

    public function testRequireCredentialsRestoresTheMissingApiKeyError()
    {
        $this->expectException(LangsysException::class);
        $this->expectExceptionMessage('API key is required');

        $this->makeClient(null, 'project-id', ['require_credentials' => true]);
    }

    public function testRequireCredentialsRestoresTheMissingProjectIdError()
    {
        $this->expectException(LangsysException::class);
        $this->expectExceptionMessage('Project ID is required');

        $this->makeClient('api-key', null, ['require_credentials' => true]);
    }
}
