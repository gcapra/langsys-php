<?php

namespace Langsys\SDK;

use Langsys\SDK\Cache\CacheInterface;
use Langsys\SDK\Cache\FileCache;
use Langsys\SDK\Cache\NullCache;
use Langsys\SDK\Cache\RedisCache;
use Langsys\SDK\Exception\ConfigurationException;
use Langsys\SDK\Exception\LangsysException;
use Langsys\SDK\Format\Interpolator;
use Langsys\SDK\Html\Canonical;
use Langsys\SDK\Html\HtmlParser;
use Langsys\SDK\Html\PageTranslator;
use Langsys\SDK\Http\HttpClient;
use Langsys\SDK\Locale\LocaleDetector;
use Langsys\SDK\Locale\RequestLocale;
use Langsys\SDK\Snapshot\Snapshot;
use Langsys\SDK\Log\Logger;
use Langsys\SDK\Log\ErrorLogLogger;
use Langsys\SDK\Log\LoggerInterface;
use Langsys\SDK\Log\LogViewer;
use Langsys\SDK\Log\NullLogger;
use Langsys\SDK\Messages\MessageTemplate;
use Langsys\SDK\Messages\ServerMessage;
use Langsys\SDK\Migration\LegacyKeys;
use Langsys\SDK\Resources\Translations;
use Langsys\SDK\Resources\TranslatableItems;
use Langsys\SDK\Resources\Utilities;

/**
 * Main client for the Langsys SDK.
 */
class Client
{
    /**
     * Key types whose write capability is fully determined by the type itself.
     * 'ip_write' is deliberately absent - its answer varies by caller.
     */
    const KEY_TYPE_READ = 'read';
    const KEY_TYPE_WRITE = 'write';

    /**
     * Local sentinel for "no category".
     */
    const UNCATEGORIZED = '__uncategorized__';

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var HttpClient
     */
    protected $http;

    /**
     * @var CacheInterface
     */
    protected $cache;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var array|null Project authorization data
     */
    protected $projectData;

    /**
     * @var Translations
     */
    protected $translations;

    /**
     * @var TranslatableItems
     */
    protected $translatableItems;

    /**
     * @var Utilities
     */
    protected $utilities;

    /**
     * @var array<string, array> Reference lists held, by list and display locale
     */
    private $referenceLists = [];

    /**
     * @var array<string, array{failures: int, until: float}> Failed reference reads, by list and display locale
     */
    private $referenceFailures = [];

    /**
     * @var string|null Target locale for translations
     */
    protected $locale;

    /**
     * A PageTranslator to use in place of the one translatePage() builds for
     * each call. The Client keeps none of its own: one holds the Client, and
     * a Client in a cycle waits for PHP's cycle collector to be freed.
     *
     * @var PageTranslator|null
     */
    protected $pageTranslator;

    /**
     * Whether THIS request may register content, as computed by the server.
     *
     * Deliberately not part of $projectData and never written to $this->cache:
     * the server derives it from the caller's address and any write grant, so
     * the same key legitimately answers true for one request and false for the
     * next. Persisting it would apply one caller's decision to every later
     * request sharing the cache. Null means "not yet resolved this request".
     *
     * @var bool|null
     */
    protected $writeEnabled = null;

    /**
     * @var array Pending phrases to register (queued during translate calls)
     */
    protected $pendingPhrases = [];

    /**
     * @var array Pending content blocks to register (queued during translateContentBlock calls)
     */
    protected $pendingContentBlocks = [];

    /**
     * Clients with registrations queued, by object id. Held weakly, so the
     * handler that flushes them when the process ends does not keep a Client
     * alive: one its framework lets go is freed, and sends its queue as it goes.
     *
     * @var \WeakReference[]
     */
    private static $queued = [];

    /**
     * @var bool Whether the process's shutdown handler has been registered
     */
    private static $shutdownHandlerRegistered = false;

    /**
     * Consecutive failed sends, and when the next send may be tried (REG-8).
     * Held by this object, not by the request: under a long-lived runtime the
     * clock outlives resetRequestState(), so a failing endpoint is not asked
     * again by every request the worker serves.
     *
     * @var int
     */
    protected $sendFailures = 0;

    /**
     * @var float
     */
    protected $nextSendAt = 0.0;

    /**
     * A snapshot seeding the catalog (SNAP-2), or null.
     *
     * @var Snapshot|null
     */
    protected $snapshot = null;

    /**
     * Locales whose live catalog has been read this request; once read, it
     * outranks the snapshot.
     *
     * @var array<string, true>
     */
    protected $liveCatalogs = [];

    /**
     * The value markers read from the block being rendered, while its walk
     * runs (VAR-3).
     *
     * @var \Langsys\SDK\Html\ValueMarkers|null
     */
    protected $blockMarkers = null;

    /**
     * Whether translate() and emitMessage() queue what the catalog lacks. Off
     * where a sync command registers instead (FRM-2); the page walk collects
     * either way.
     *
     * @var bool
     */
    protected $runtimeRegistration = true;

    /**
     * The app's declared value sets (FRM-7), or null when it declares none.
     *
     * @var \Langsys\SDK\Messages\ValueSets|null
     */
    protected $valueSets = null;

    /**
     * Answers a phrase the catalog has no translation for, before the source
     * does (FRM-3): the framework's own language files.
     *
     * @var callable|null
     */
    protected $missFallback = null;

    /**
     * Whether a translate() call has reported, this process, that it had no
     * catalog to consult (FRM-3): once, at debug.
     *
     * @var bool
     */
    protected static $catalogUnavailableNoticed = false;

    /**
     * The settings this client was built without, named in its warning and
     * its exceptions: empty when it has an API key and a project id.
     *
     * @var string[]
     */
    protected $missingSettings = [];

    /**
     * Whether a client built without credentials has said so, this process
     * (WIRE-4): once, however many clients are built.
     *
     * @var bool
     */
    protected static $unconfiguredWarned = false;

    /**
     * Locales whose last catalog read this request failed, with none
     * succeeding since. A miss cannot be decided against them, so nothing is
     * queued, and a flush reports the skip (REG-10).
     *
     * @var array<string, true>
     */
    protected $unavailableCatalogs = [];

    /**
     * Where the app keeps the request's locale (RequestLocale::DEFAULTS).
     *
     * @var array
     */
    protected $requestLocaleOptions = [];

    /**
     * This request's resolved locale, when none was set (SRV-6).
     *
     * @var array|null
     */
    protected $requestLocale = null;

    /**
     * Template-and-marker pairs already reported as carrying translatable text.
     *
     * @var array<string, true>
     */
    protected $reportedMarkerValues = [];

    /**
     * Whether an unusable write capability has been reported (OBS-1).
     *
     * @var bool
     */
    protected $unusableCapabilityReported = false;

    /** First backoff after a failed send, in seconds; it doubles up to the ceiling. */
    const SEND_BACKOFF_INITIAL = 3;

    /** Longest wait between send attempts, in seconds. */
    const SEND_BACKOFF_CEILING = 300;

    /**
     * @var array In-memory translations cache (survives across getTranslations calls within same request)
     */
    protected $translationsMemoryCache = [];

    /**
     * Locales whose catalog fetch has already failed during THIS request.
     *
     * A failed fetch is not cacheable - writing anything for it is how a bad
     * response blanks a project for a whole TTL - but it should not be retried
     * once per phrase either. Without this, a 200-phrase page during an outage
     * or a malformed-response incident issues 200 requests, each one waiting on
     * the same broken dependency, turning a degraded page into a slow one and
     * adding load to a service already in trouble.
     *
     * Held for a bounded window per locale (CACHE-2): 3s after the first
     * failure, doubling on each consecutive one to five minutes, cleared by the
     * first success - REG-8's clock on the read side. It belongs to this object,
     * not the request, so resetRequestState() keeps it: under a long-lived
     * runtime every request during an outage would otherwise wait on the failing
     * call again, and the window, not the request, is what ends the latch. It is
     * never written to the shared cache.
     *
     * @var array<string, array{failures: int, until: float}>
     */
    protected $translationFetchFailures = [];

    /**
     * @var Interpolator|null Placeholder interpolator (lazily created)
     */
    protected $interpolator;

    /**
     * @var LegacyKeys|false|null Legacy-key resolver: null until first asked, false when the mode is off
     */
    protected $legacyKeys;

    /**
     * Whether the render in progress is a legacy key hit, which prints its
     * numbers as plain digits (MIG-4).
     *
     * @var bool
     */
    protected $renderingLegacyHit = false;

    /**
     * Create a new Langsys Client.
     *
     * @param string|null $apiKey API key (or null to use env var)
     * @param string|null $projectId Project ID (or null to use env var)
     * Without an API key or project ID the client is unconfigured: its reads
     * render source text and its writes throw (WIRE-4). `require_credentials`
     * makes its first use throw instead.
     *
     * @param array $options Additional options
     * @throws LangsysException When a migration file is in a format this SDK does not read
     */
    public function __construct($apiKey = null, $projectId = null, array $options = [])
    {
        // Build config options
        $configOptions = $options;

        if ($apiKey !== null) {
            $configOptions['api_key'] = $apiKey;
        }

        if ($projectId !== null) {
            $configOptions['project_id'] = $projectId;
        }

        $this->config = new Config($configOptions);

        // WIRE-4: missing credentials make an unconfigured client, not an
        // exception. Its reads render source text through the path a catalog
        // that failed to load takes, and its writes throw. With
        // `require_credentials` on, its first use throws instead.
        if (!$this->config->hasApiKey()) {
            $this->missingSettings[] = 'API key (LANGSYS_API_KEY)';
        }

        if (!$this->config->hasProjectId()) {
            $this->missingSettings[] = 'project ID (LANGSYS_PROJECT_ID)';
        }

        // Initialize logger
        $this->logger = $this->initializeLogger($options);

        // MIG-7: a migration file in a format this SDK does not read is refused
        // here, when the configuration loads, not inside a render.
        if ($this->config->getMigration() !== null) {
            LegacyKeys::refuseUnreadFormats($this->config->getMigration());
        }

        if (isset($options['request_locale']) && is_array($options['request_locale'])) {
            $this->requestLocaleOptions = $options['request_locale'];
        }

        if (array_key_exists('runtime_registration', $options)) {
            $this->runtimeRegistration = (bool) $options['runtime_registration'];
        }

        if (isset($options['value_sets']) && is_array($options['value_sets']) && $options['value_sets'] !== []) {
            $this->valueSets = new \Langsys\SDK\Messages\ValueSets($options['value_sets']);
        }

        if (isset($options['snapshot']) && $options['snapshot'] instanceof Snapshot) {
            $this->useSnapshot($options['snapshot']);
        }

        if (array_key_exists('warn_runtime_requirements', $options)) {
            $this->warnRuntimeRequirements = (bool) $options['warn_runtime_requirements'];
        }

        // Composer enforces the PHP version and ext-intl, but the documented
        // manual autoload.php install bypasses Composer entirely - so check at
        // runtime too, or those users hit an obscure failure inside the ICU code
        // with nothing pointing at the cause. Host frameworks that surface the
        // SDK logger themselves can silence the error_log leg.
        $this->checkRuntimeRequirements();
        $this->warnUnconfigured();

        // Initialize HTTP client
        $this->http = new HttpClient($this->config, $this->logger);

        // Initialize cache
        $this->cache = $this->initializeCache($options);

        // Clear cache if requested
        if (!empty($options['cache_clear'])) {
            $this->cache->clear();
            $this->logger->info('Cache cleared on initialization');
        }

        // Initialize resources
        $this->translations = new Translations($this->http, $this->config->getProjectId(), $this->logger);
        $this->translatableItems = new TranslatableItems($this->http, $this->config->getProjectId(), $this->logger);
        $this->utilities = new Utilities($this->http, $this->config->getProjectId(), $this->logger);
    }

    /**
     * Whether this client has an API key and a project id. One without either
     * renders source text, fetches nothing and refuses every write.
     *
     * @return bool
     */
    public function isConfigured()
    {
        return $this->missingSettings === [];
    }

    /**
     * Say once per process that the client has no credentials, naming what is
     * missing. A framework builds a client on every request, so a warning per
     * client would bury the one line saying what to set. With
     * `require_credentials` on, the exception at first use says it instead.
     *
     * @return void
     */
    protected function warnUnconfigured()
    {
        if ($this->isConfigured() || $this->config->requiresCredentials() || self::$unconfiguredWarned) {
            return;
        }

        self::$unconfiguredWarned = true;

        $this->warnRequirement(
            'Langsys is not configured: missing ' . implode(' and ', $this->missingSettings)
                . '. Every translation renders its source text, and nothing is fetched from or sent to Langsys.',
            ['missing' => $this->missingSettings]
        );
    }

    /**
     * The exception for a client without credentials. With
     * `require_credentials` on it is the one the first use raises, naming the
     * setting to provide; otherwise it names what was refused and why.
     *
     * @param string $action What was refused, e.g. "register phrases"
     * @return ConfigurationException
     */
    protected function notConfigured($action)
    {
        if ($this->config->requiresCredentials()) {
            return new ConfigurationException($this->config->hasApiKey()
                ? 'Project ID is required. Set LANGSYS_PROJECT_ID environment variable or pass it to the constructor.'
                : 'API key is required. Set LANGSYS_API_KEY environment variable or pass it to the constructor.');
        }

        return new ConfigurationException('Cannot ' . $action . ': the client is not configured (missing '
            . implode(' and ', $this->missingSettings) . '). '
            . (count($this->missingSettings) === 1 ? 'Set it in the environment or pass it' : 'Set them in the environment or pass them')
            . ' to the constructor.');
    }

    /**
     * With `require_credentials` on, a read on a client without credentials
     * throws: the exception comes at first use, not when the client is built,
     * so a framework can build one while it boots without secrets.
     *
     * @return void
     * @throws ConfigurationException
     */
    protected function requireCredentialsAtUse()
    {
        if (!$this->isConfigured() && $this->config->requiresCredentials()) {
            throw $this->notConfigured('translate');
        }
    }

    /**
     * An explicit write on a client without credentials throws, saying why: the
     * caller expects it to happen.
     *
     * @param string $action
     * @return void
     * @throws ConfigurationException
     */
    protected function assertConfigured($action)
    {
        if (!$this->isConfigured()) {
            throw $this->notConfigured($action);
        }
    }

    /**
     * Log a read that failed and degraded to source. A client without
     * credentials is not logged per read: its warning said why, once.
     *
     * @param string $message
     * @param array $context
     * @param \Throwable $e
     * @return void
     */
    protected function logDegradedRead($message, array $context, \Throwable $e)
    {
        if (!$e instanceof ConfigurationException) {
            $this->logger->error($message, $context);
        }
    }

    /**
     * Minimum supported PHP version (mirrors composer.json).
     */
    const MIN_PHP_VERSION = '7.4';

    /**
     * @var bool Whether runtime requirement warnings have already been emitted.
     */
    protected static $requirementsWarned = false;

    /**
     * @var bool Whether to write requirement warnings to the PHP error log.
     */
    protected $warnRuntimeRequirements = true;

    /**
     * Warn about unmet runtime requirements.
     *
     * Deliberately warns rather than throws: a manual install on an older PHP
     * may still work for basic translation, and a missing ext-intl degrades
     * gracefully to simple placeholder substitution rather than breaking the
     * render. The goal is that the operator is TOLD, not stopped.
     *
     * Warnings go to both the SDK logger and PHP's error_log(), because a manual
     * install typically has no log path configured, which would make a
     * logger-only warning invisible to exactly the users who need it.
     *
     * @return void
     */
    protected function checkRuntimeRequirements()
    {
        // Once per process - this runs on every Client construction.
        if (self::$requirementsWarned) {
            return;
        }

        self::$requirementsWarned = true;

        if (version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '<')) {
            $this->warnRequirement(
                'Langsys SDK requires PHP ' . self::MIN_PHP_VERSION . ' or higher; running ' . PHP_VERSION
                    . '. Installed without Composer? The version constraint is not enforced on that path.',
                ['required' => self::MIN_PHP_VERSION, 'actual' => PHP_VERSION]
            );
        }

        if (!extension_loaded('intl')) {
            $this->warnRequirement(
                'Langsys SDK: ext-intl is not loaded. ICU plurals and locale-aware number/date '
                    . 'formatting are unavailable; placeholders fall back to simple substitution.',
                ['missing_extension' => 'intl']
            );
        }
    }

    /**
     * Emit an unmet-requirement warning to the SDK logger and to PHP's error
     * log, once in each: the default logger writes to the error log itself.
     *
     * Uses error_log() rather than trigger_error(). trigger_error() goes through
     * the installed error handler, and frameworks routinely convert PHP errors
     * into exceptions - Laravel's HandleExceptions turns E_USER_WARNING into a
     * thrown ErrorException - which inverted this warning into a fatal that
     * broke Client construction on any host without ext-intl. That is the exact
     * opposite of the intent. error_log() writes straight to the log and cannot
     * be escalated by any error handler.
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    protected function warnRequirement($message, array $context = [])
    {
        $this->logger->warning($message, $context);

        // The default logger has already written it to the PHP error log. A
        // subclass may write elsewhere, so only the class itself counts.
        if (!$this->warnRuntimeRequirements || get_class($this->logger) === ErrorLogLogger::class) {
            return;
        }

        // Surfaces in the PHP error log even when SDK logging is disabled,
        // which is the normal case for a manual (non-Composer) install.
        error_log($message);
    }

    /**
     * Initialize the logger based on configuration.
     *
     * @param array $options
     * @return LoggerInterface
     */
    protected function initializeLogger(array $options)
    {
        // Allow passing a logger instance directly
        if (isset($options['logger']) && $options['logger'] instanceof LoggerInterface) {
            return $options['logger'];
        }

        // With no log file, warnings and errors still go somewhere (REG-10):
        // PHP's error log, unless the app turns that off.
        $fallback = (isset($options['error_log']) && $options['error_log'] === false)
            ? new NullLogger()
            : new ErrorLogLogger();

        // Check if logging is enabled
        if (!$this->config->isLoggingEnabled()) {
            return $fallback;
        }

        $logPath = $this->config->getLogPath();
        $logLevel = $this->config->getLogLevel();

        // Validate that the directory is writable
        $dir = dirname($logPath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return $fallback;
        }
        if (!is_writable($dir)) {
            return $fallback;
        }

        return new Logger($logPath, $logLevel);
    }

    /**
     * Initialize the cache based on configuration.
     *
     * @param array $options
     * @return CacheInterface
     */
    protected function initializeCache(array $options)
    {
        // Allow passing a cache instance directly
        if (isset($options['cache']) && $options['cache'] instanceof CacheInterface) {
            return $options['cache'];
        }

        $driver = $this->config->getCacheDriver();
        $ttl = $this->config->getCacheTtl();

        switch ($driver) {
            case 'redis':
                $redisOptions = isset($options['redis']) ? $options['redis'] : [];
                return new RedisCache($redisOptions, 'langsys::', $ttl, $this->logger);

            case 'none':
            case 'null':
                return new NullCache();

            case 'file':
            default:
                return new FileCache($this->config->getCachePath(), $ttl, $this->logger);
        }
    }

    /**
     * Authorize and get project information.
     *
     * @param bool $force Force re-authorization even if cached
     * @return array Project data including key_type
     */
    public function authorize($force = false)
    {
        // No key to authorize. Callers degrade as they do when the API cannot
        // be reached, and a write refuses.
        if (!$this->isConfigured()) {
            throw $this->notConfigured('authorize the project');
        }

        if ($this->projectData !== null && !$force) {
            $this->logger->debug('Authorization from memory', [
                'project_id' => $this->config->getProjectId(),
            ]);
            return $this->projectData;
        }

        $cacheKey = $this->authCacheKey();

        if (!$force) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                // Entries written by older SDK versions may still carry the
                // write decision. Drop it rather than trust a value computed
                // for whichever request happened to populate the cache.
                if (is_array($cached)) {
                    unset($cached['write_enabled']);
                }
                $this->projectData = $cached;
                $this->syncBatchLimit();
                $this->logger->debug('Authorization from cache', [
                    'project_id' => $this->config->getProjectId(),
                ]);
                return $this->projectData;
            }
        }

        $response = $this->http->get('authorize-project/' . $this->config->getProjectId());

        if (isset($response['data'])) {
            $data = $response['data'];

            // GATE-8: the API's computed decision for this request, and only
            // that. An answer without the field is not write-enabled, whatever
            // the key's type. Captured, then stripped so it can never reach
            // $this->cache - a file/Redis store shared by every request on the
            // host, and by the fleet on Redis.
            $this->writeEnabled = array_key_exists('write_enabled', $data) && (bool) $data['write_enabled'];
            unset($data['write_enabled']);

            $this->projectData = $data;
            $this->reportUnusableCapability();
            $this->syncBatchLimit();
            $this->cache->set($cacheKey, $this->projectData);
            $keyType = isset($this->projectData['key_type']) ? $this->projectData['key_type'] : 'unknown';
            $this->logger->info('Project authorized', [
                'project_id' => $this->config->getProjectId(),
                'key_type' => $keyType,
                'write_enabled' => $this->writeEnabled,
            ]);
            return $this->projectData;
        }

        return $response;
    }

    /**
     * OBS-1: a key whose type is meant to write, answered write_enabled false,
     * is otherwise completely silent - nothing is sent and nothing fails - so
     * say so once for the life of this object, never per miss.
     *
     * @return void
     */
    protected function reportUnusableCapability()
    {
        $keyType = isset($this->projectData['key_type']) ? $this->projectData['key_type'] : null;

        if ($this->unusableCapabilityReported || $this->writeEnabled !== false
            || !in_array($keyType, [self::KEY_TYPE_WRITE, 'ip_write'], true)) {
            return;
        }

        $this->unusableCapabilityReported = true;

        $this->logger->warning('This key cannot register new text: its type writes, but the server answered write_enabled false, so nothing new will reach the catalog. For an ip_write key, check that this server\'s address is on the key\'s allow-list.', [
            'project_id' => $this->config->getProjectId(),
            'key_type' => $keyType,
        ]);
    }

    /**
     * Whether a value has the shape of a catalog: a map of category => map.
     *
     * Checked to depth 2, not depth 1. A top-level array whose SLICES are
     * scalars passes an is_array() test and then raises a TypeError the moment
     * anything indexes into a slice - which is outside the entry-point catch,
     * so it reaches the caller as a 500. The earlier guard tested only the top
     * level and was therefore satisfied by a value that still broke every
     * render.
     *
     * Deliberately not recursive past depth 2: a slice's VALUES are legitimately
     * mixed (string translation, null for untranslated, array for a content
     * block), so there is nothing further to constrain.
     *
     * @param mixed $value
     * @return bool
     */
    protected function isCatalogShape($value)
    {
        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $slice) {
            if (!is_array($slice)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Cache key for the authorization payload.
     *
     * Scoped by project AND by which key asked. The response depends on the API
     * key - key_type, and the capability derived from it - so a project-only key
     * lets two keys on one host share an entry: a read key inherits a write
     * key's cached key_type and believes it may register, and in the other
     * direction a write key inherits a read key's and silently stops
     * discovering. The default cache is a shared temp directory, so "two keys on
     * one host" is an ordinary deployment (a read key rendering, a write key in
     * a sync job), not a corner case.
     *
     * The key is HASHED and truncated: cache keys land on shared filesystems and
     * in Redis keyspaces, and raw key material must not.
     *
     * @return string
     */
    protected function authCacheKey()
    {
        return 'auth_' . $this->config->getProjectId()
            . '_' . substr(hash('sha256', (string) $this->config->getApiKey()), 0, 12);
    }

    /**
     * Sync the batch limit from project data to the TranslatableItems resource.
     *
     * @return void
     */
    protected function syncBatchLimit()
    {
        // The server nests this: langsys_settings.translatable_items.batch_limit.
        // Reading one level short silently kept the SDK on its own default, so
        // the server-provided limit never applied - and if the server lowers it,
        // oversized batches are REJECTED and registration fails wholesale.
        // Confirmed against the spec (REG-9), LangsysSettingsResource, and a live
        // authorize-project response.
        if (isset($this->projectData['langsys_settings']['translatable_items']['batch_limit'])) {
            $this->translatableItems->setBatchLimit(
                $this->projectData['langsys_settings']['translatable_items']['batch_limit']
            );
        }
    }

    /**
     * Check if the API key has write permissions.
     *
     * @return bool
     */
    public function canWrite()
    {
        if (!$this->isConfigured()) {
            return false;
        }

        if ($this->writeEnabled === null) {
            $this->resolveWriteDecision();
        }

        return $this->writeEnabled === true;
    }

    /**
     * Work out whether this request may write.
     *
     * Only the API's answer in this request decides (GATE-8). The decision
     * depends on the key, the caller's address and any grant, so the key's type
     * answers nothing, and a cached authorization holds no decision: its flag
     * was stripped before it was cached (GATE-4). So when this request has not
     * asked yet, it asks. Only a write needs the answer: the flush of what a
     * request collected, which runs after the response, and the explicit
     * registration and import calls.
     *
     * @return void
     */
    protected function resolveWriteDecision()
    {
        $this->authorize(true);

        if ($this->writeEnabled === null) {
            // Unexpected response shape. Settle so we don't re-request per call.
            $this->writeEnabled = false;
        }
    }

    /**
     * Clear state that belongs to a single request.
     *
     * Under a long-lived runtime (Octane, Swoole, RoadRunner, a queue worker)
     * this Client can outlive the request it was built for, which would carry
     * one caller's write decision and locale into the next caller's request.
     * Call this between requests. Flush pending registrations first - this
     * drops what is still queued rather than sending it. A locale set with
     * setLocale() is cleared too, so a worker that serves one fixed locale
     * sets it again after each reset.
     *
     * @return $this
     */
    public function resetRequestState()
    {
        // REG-8: the queue belongs to the request that collected it. Sending one
        // request's phrases with another's would carry request data across the
        // boundary; the next render collects its own misses again. The backoff
        // clock is not request state and stays.
        $unsent = count($this->pendingPhrases) + count($this->pendingContentBlocks);
        if ($unsent > 0) {
            $this->logger->debug('Unsent registrations dropped at the request boundary', ['count' => $unsent]);
        }
        $this->pendingPhrases = [];
        $this->pendingContentBlocks = [];

        $this->writeEnabled = null;
        $this->locale = null;
        $this->requestLocale = null;
        $this->liveCatalogs = [];
        $this->unavailableCatalogs = [];
        $this->translationsMemoryCache = [];

        return $this;
    }

    /**
     * Resolve a caller-supplied category to the local catalog key.
     *
     * The catalog keys uncategorised items under '__uncategorized__', so an
     * empty or null category has to become the sentinel on the way in. Left
     * unnormalised, a lookup under '' misses forever while registration writes
     * the phrase as uncategorised - so the same phrase is re-registered on every
     * request and never converges. Mirrors the JS SDKs'
     * `category || '__uncategorized__'`.
     *
     * @param string|null $category
     * @return string
     */
    protected function normalizeCategory($category)
    {
        if ($category === null || $category === '') {
            return self::UNCATEGORIZED;
        }

        return $category;
    }

    /**
     * A category argument read as [category, previous category] (MOVE-1).
     *
     * Wherever a category is taken, `['category' => 'Checkout',
     * 'previous_category' => 'Header']` says the content moved there from
     * Header: the miss registers it with `previous_category`, so the API
     * hands it Header's translations, and until the catalog has it under
     * Checkout it renders from Header (MOVE-4). `''` names no category
     * before, which is a value; a previous category that is null, left out
     * or the same as the category says the content did not move. A string
     * is a category that did not move.
     *
     * @param string|array|null $category
     * @return array{0: string|null, 1: string|null} The category, and the previous one or null
     */
    protected function splitCategory($category)
    {
        if (!is_array($category)) {
            return [$category, null];
        }

        $current = isset($category['category']) && is_scalar($category['category']) ? (string) $category['category'] : null;
        $previous = array_key_exists('previous_category', $category) ? $category['previous_category'] : null;

        return [$current, TranslatableItems::previousCategoryFor($previous, ($current === null || $current === '' || $current === self::UNCATEGORIZED) ? null : $current)];
    }

    /**
     * A phrase call's category argument as [category, previous category],
     * the previous one as the caller wrote it: whether it names a move is
     * decided in translateCall(), against the category a key's group gives
     * (MOVE-1), so a call naming `''` or its own group is read correctly.
     *
     * @param mixed $category
     * @return array{0: string|null, 1: string|null}
     */
    private function splitPhraseCategory($category)
    {
        if (!is_array($category)) {
            return [$category, null];
        }

        $current = isset($category['category']) && is_scalar($category['category']) ? (string) $category['category'] : null;
        $previous = isset($category['previous_category']) && is_scalar($category['previous_category']) ? (string) $category['previous_category'] : null;

        return [$current, $previous];
    }

    /**
     * The catalog's key for a previous category: `''` is the no-category
     * bucket, never a literal '' key (MOVE-4).
     *
     * @param string $previousCategory
     * @return string
     */
    protected static function previousCatalogKey($previousCategory)
    {
        return $previousCategory === '' ? self::UNCATEGORIZED : $previousCategory;
    }

    /**
     * A phrase's translation under its previous category (MOVE-4): the entry
     * for the same text, then the phrase's earlier key there (TOK-1), by the
     * display rule - null or empty is none.
     *
     * @param array $catalog category => entries
     * @param mixed $phrase
     * @param string|null $previousCategory
     * @param callable|null $earlier
     * @return string|null
     */
    protected function previousPhraseTranslation(array $catalog, $phrase, $previousCategory, ?callable $earlier = null)
    {
        if ($previousCategory === null || !(is_string($phrase) || is_int($phrase))) {
            return null;
        }

        $key = self::previousCatalogKey($previousCategory);
        if (!isset($catalog[$key]) || !is_array($catalog[$key])) {
            return null;
        }

        $slice = $catalog[$key];
        if (array_key_exists($phrase, $slice) && is_string($slice[$phrase]) && $slice[$phrase] !== '') {
            return $slice[$phrase];
        }

        return $earlier === null ? null : $earlier($slice);
    }

    /**
     * A block's translations under its previous category (MOVE-4): the
     * custom_id that category gives the same tokens, then its historical ids
     * (CID-3), in that order. Only an entry holding a translation counts.
     *
     * @param array $catalog category => entries
     * @param string|null $previousCategory
     * @param array $phrases The block's tokens
     * @param HtmlParser $parser
     * @return array{0: string, 1: array}|null The id it was found under, and its translations
     */
    public function previousContentBlockTranslations(array $catalog, $previousCategory, array $phrases, HtmlParser $parser)
    {
        if ($previousCategory === null || $phrases === []) {
            return null;
        }

        $key = self::previousCatalogKey($previousCategory);
        $slice = isset($catalog[$key]) && is_array($catalog[$key]) ? $catalog[$key] : [];
        if ($slice === []) {
            return null;
        }

        $id = $parser->generateCustomId($key, $phrases);
        $found = $id;
        $translations = array_key_exists($id, $slice) && is_array($slice[$id])
            ? $slice[$id]
            : $this->resolveLegacyContentBlock($slice, $key, $phrases, $parser, $found);

        return self::holdsTranslation($translations) ? [$found, $translations] : null;
    }

    /**
     * The translations a block host stamped with a previous custom_id was
     * filled from (MOVE-5), from whichever category holds that id.
     *
     * @param array $catalog category => entries
     * @param string $previousId
     * @return array{0: string, 1: array}|null The category holding it, and its translations
     */
    public function stampedPreviousBlock(array $catalog, $previousId)
    {
        foreach ($catalog as $category => $entries) {
            if (is_array($entries) && isset($entries[$previousId]) && is_array($entries[$previousId]) && self::holdsTranslation($entries[$previousId])) {
                return [(string) $category, $entries[$previousId]];
            }
        }

        return null;
    }

    /**
     * Whether a block's entry holds any translation: one queued in this
     * request, or registered and not yet translated, holds none (MOVE-4).
     *
     * @param mixed $translations
     * @return bool
     */
    public static function holdsTranslation($translations)
    {
        if (!is_array($translations)) {
            return false;
        }

        foreach ($translations as $value) {
            if (is_string($value) && $value !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Give an item already queued in this request the previous category a
     * later call names, when it has none (MOVE-3): the call that queued it
     * may have named no move, and every later one reads it as present.
     *
     * @param string|null $phrase A phrase's text, or null for a block
     * @param string $category
     * @param string|null $customId A block's id
     * @param string|null $previousCategory
     * @return void
     */
    public function notePreviousCategory($phrase, $category, $customId, $previousCategory)
    {
        $previousCategory = TranslatableItems::previousCategoryFor($previousCategory, ($category === null || $category === '' || $category === self::UNCATEGORIZED) ? null : $category);
        if ($previousCategory === null) {
            return;
        }

        if ($customId !== null) {
            if (isset($this->pendingContentBlocks[$customId]) && !isset($this->pendingContentBlocks[$customId]['previous_category'])) {
                $this->pendingContentBlocks[$customId]['previous_category'] = $previousCategory;
            }

            return;
        }

        $key = $category . '::' . $phrase;
        if (isset($this->pendingPhrases[$key]) && !isset($this->pendingPhrases[$key]['previous_category'])) {
            $this->pendingPhrases[$key]['previous_category'] = $previousCategory;
        }
    }

    /**
     * Whether the last translateSource() rendered from the previous category.
     *
     * @var bool
     */
    private $filledFromPrevious = false;

    /**
     * Get the key type (`read`, `write` or `ip_write`). Whether a request may
     * write is canWrite(), the API's decision, not this.
     *
     * @return string|null
     */
    public function getKeyType()
    {
        $data = $this->authorize();
        return isset($data['key_type']) ? $data['key_type'] : null;
    }

    /**
     * Get project information.
     *
     * @return array
     */
    public function getProject()
    {
        return $this->authorize();
    }

    /**
     * Get translations for a locale.
     *
     * Uses a three-tier cache: in-memory (request-scoped) → file/redis → API.
     * Items added via translate()/translateContentBlock() are added to the in-memory
     * cache immediately, avoiding re-registration within the same request.
     *
     * @param string $locale Locale code (e.g., 'es-es')
     * @param bool $useCache Whether to use cache
     * @return array [category => [phrase => translation]]
     */
    public function getTranslations($locale, $useCache = true)
    {
        $locale = LocaleDetector::normalize($locale);
        $memoryKey = $locale;
        $cacheKey = 'translations_' . $this->config->getProjectId() . '_' . $locale;

        // Unconfigured, the catalog cannot be read: the read paths render
        // source and queue nothing, as for a fetch that failed, and a flush
        // reports the skip. No cache is consulted, so what such a client
        // renders never depends on what another process left there.
        if (!$this->isConfigured()) {
            $this->unavailableCatalogs[$memoryKey] = true;
            throw $this->notConfigured('read the catalog');
        }

        // Check in-memory cache first (fastest, includes queued items)
        if ($useCache && isset($this->translationsMemoryCache[$memoryKey])) {
            $this->logger->debug('Translations cache hit', [
                'locale' => $locale,
                'source' => 'memory',
            ]);
            return $this->translationsMemoryCache[$memoryKey];
        }

        // Check file/redis cache
        if ($useCache) {
            $cached = $this->cache->get($cacheKey);

            // A hit of the WRONG SHAPE is a miss, not a hit.
            //
            // This cache is shared by every request on the host and, on Redis,
            // by the fleet - so it sees truncated writes, key collisions and
            // formats written by other SDK versions. A bad entry here reached
            // the catalog lookup and raised a TypeError out of translate(),
            // which is a 500 on a customer page. Checked to depth 2: an array
            // of SCALAR slices satisfies is_array() and then breaks at the
            // first index into a slice, past the entry-point catch.
            //
            // Treated as a miss AND invalidated: degrading on every call while a
            // poisoned entry sits there for the rest of its TTL is the lesser
            // fix. Deleting it lets the next request repopulate from the API.
            if ($cached !== null && !$this->isCatalogShape($cached)) {
                $this->logger->warning('Discarding malformed translations cache entry', [
                    'locale' => $locale,
                    'cache_key' => $cacheKey,
                    'type' => gettype($cached),
                ]);
                $this->cache->delete($cacheKey);
                $cached = null;
            }

            if ($cached !== null) {
                // Store in memory cache for this request
                $this->translationsMemoryCache[$memoryKey] = $cached;
                $this->liveCatalogs[$memoryKey] = true;
                unset($this->unavailableCatalogs[$memoryKey]);
                $this->logger->debug('Translations cache hit', [
                    'locale' => $locale,
                    'source' => 'persistent',
                ]);
                return $cached;
            }
        }

        // Inside a failed fetch's window? Fail the same way again without
        // asking the API. Re-raised rather than answered with [], because an
        // empty catalog is a VALUE and callers would cache it; the whole point
        // is that a failure produces no value at all.
        if (isset($this->translationFetchFailures[$memoryKey])
            && $this->currentTime() < $this->translationFetchFailures[$memoryKey]['until']) {
            $this->unavailableCatalogs[$memoryKey] = true;
            throw new LangsysException(sprintf(
                'Translations fetch for %s failed recently; retrying after its backoff window',
                $locale
            ));
        }

        $this->logger->debug('Translations cache miss', ['locale' => $locale]);

        // Fetch from API
        try {
            $translations = $this->translations->getTranslationMap($locale);
        } catch (\Throwable $e) {
            $failures = isset($this->translationFetchFailures[$memoryKey])
                ? $this->translationFetchFailures[$memoryKey]['failures'] + 1
                : 1;
            $this->translationFetchFailures[$memoryKey] = [
                'failures' => $failures,
                'until' => $this->currentTime() + $this->backoffDelay($failures),
            ];
            $this->unavailableCatalogs[$memoryKey] = true;
            throw $e;
        }

        unset($this->translationFetchFailures[$memoryKey]);
        $this->liveCatalogs[$memoryKey] = true;
        unset($this->unavailableCatalogs[$memoryKey]);

        // Store in both caches
        if ($useCache) {
            $this->cache->set($cacheKey, $translations);
        }
        $this->translationsMemoryCache[$memoryKey] = $translations;

        return $translations;
    }

    /**
     * Translate a phrase.
     *
     * This method both translates the phrase AND queues it for registration if
     * it doesn't exist in translations. Pending registrations are automatically
     * flushed at the end of the request, or you can call flushPendingRegistrations()
     * manually.
     *
     * IMPORTANT: pass dynamic values via $params rather than building the string
     * yourself. `translate(sprintf('Hello, %s!', $name))` registers a NEW catalog
     * phrase for every distinct value ("Hello, Sarah!", "Hello, Ahmed!", ...),
     * polluting the catalog shared with the JS SDKs. `translate('Hello, {name}!',
     * null, null, null, ['name' => $name])` registers one reusable phrase.
     *
     * @param string $phrase The phrase to translate
     * @param string|null $locale Locale code (defaults to getLocale() if not set)
     * @param string|array $category Category (default: '__uncategorized__'), or
     *                              `['category' => ..., 'previous_category' => ...]`
     *                              for content that moved (MOVE-1)
     * @param string|null $contentBlockId Content block custom_id (for content block phrases)
     * @param array $params Placeholder values, e.g. ['name' => 'Sarah']
     * @return string The translation, or the original phrase if not found
     */
    public function translate($phrase, $locale = null, $category = '__uncategorized__', $contentBlockId = null, array $params = [])
    {
        list($category, $previousCategory) = $this->splitPhraseCategory($category);

        return $this->translateCall($phrase, $locale, $category, $contentBlockId, $params, $from, null, null, $previousCategory);
    }

    /**
     * Translate a phrase and say who wrote the text returned (FRM-8):
     * `catalog` for a Langsys translation, `fallback` for the answer of the
     * miss fallback (the framework's own language files), `source` for the
     * phrase itself. A caller printing raw escapes catalog text and leaves
     * the app's own text as the framework prints it.
     *
     * @param string $phrase
     * @param string|null $locale
     * @param string|null $category
     * @param array $params
     * @return array{text: string, from: string}
     */
    public function resolve($phrase, $locale = null, $category = '__uncategorized__', array $params = [])
    {
        list($category, $previousCategory) = $this->splitPhraseCategory($category);
        $text = $this->translateCall($phrase, $locale, $category, null, $params, $from, null, null, $previousCategory);

        return ['text' => $text, 'from' => $from];
    }

    /**
     * Translate a source line that carries inline markup, safely (FRM-8).
     *
     * The line's elements become markup tokens (`Read our {m0o}terms{m0c}`),
     * the same rich-phrase encoding sync registers. A catalog translation is
     * rendered by rebuilding the source's own elements - tags and attributes
     * as the source wrote them - around the translated runs, every run a text
     * node: a translation can place the source's tags but never add one,
     * change an attribute or inject script, and a token the source lacks is
     * dropped. A line from the miss fallback is the app's own and comes back
     * as the framework prints it, a marked value in it as its marker; with
     * neither, the source line comes back
     * with its values filled. An element its author excluded is kept whole,
     * one empty token pair restored as written, and a line made only of such
     * elements registers nothing (TOK-1).
     *
     * @param string $sourceHtml
     * @param string|null $locale
     * @param string|null $category
     * @param array $params
     * @return array{html: string, from: string}
     */
    public function translateRich($sourceHtml, $locale = null, $category = '__uncategorized__', array $params = [])
    {
        $this->requireCredentialsAtUse();

        list($category, $previousCategory) = $this->splitPhraseCategory($category);

        $line = \Langsys\SDK\Html\MarkupTokenizer::readLine((string) $sourceHtml);
        if ($line === null) {
            return ['html' => (string) $sourceHtml, 'from' => 'source'];
        }

        list($doc, $wrapper, $markers, $tokenizer, $encoded) = [$line['doc'], $line['root'], $line['markers'], $line['tokenizer'], $line['encoded']];
        $slots = $encoded['slots'];

        // A line with no text of its own registers nothing, and renders as
        // given with the caller's params filled.
        if (!$tokenizer->hasText($encoded['text'])) {
            if ($params === []) {
                return ['html' => (string) $sourceHtml, 'from' => 'source'];
            }

            $tokenizer->fillExcluded($slots, $encoded['opaque'], $params);
            $rendered = $this->getInterpolator()->interpolate($encoded['text'], $tokenizer->tokenParams(count($slots)));

            return ['html' => $this->renderRichLine($wrapper, $doc, $tokenizer, $rendered, $encoded, $markers), 'from' => 'source'];
        }

        // The line's own values: never one read inside an element the
        // author excluded.
        // The miss fallback is told the line as the caller wrote it.
        $rendered = $this->translateCall($encoded['text'], $locale, $category, null, array_merge($markers->sentinels($wrapper), $params, $tokenizer->tokenParams(count($slots))), $from, $this->earlierLookup($tokenizer, $encoded['earlier']), (string) $sourceHtml, $previousCategory);

        if ($from === 'fallback') {
            return ['html' => \Langsys\SDK\Html\ValueMarkers::expandHtml($rendered), 'from' => $from];
        }

        // Every token the source has is a param, so one still in the text
        // names an element the source does not have: it is dropped, and the
        // source's own elements are still rebuilt.
        $rendered = (string) preg_replace('/\{m\d+[oc]\}/', '', $rendered);

        // An excluded element is restored from the line as written, which
        // in a call with params is a template (TOK-1).
        $tokenizer->fillExcluded($slots, $encoded['opaque'], $params);

        return ['html' => $this->renderRichLine($wrapper, $doc, $tokenizer, $rendered, $encoded, $markers), 'from' => $from];
    }

    /**
     * A rich line's elements rebuilt around its rendered text, serialised.
     *
     * @param \DOMElement $wrapper
     * @param \DOMDocument $doc
     * @param \Langsys\SDK\Html\MarkupTokenizer $tokenizer
     * @param string $rendered
     * @param array $encoded
     * @param \Langsys\SDK\Html\ValueMarkers $markers
     * @return string
     */
    private function renderRichLine(\DOMElement $wrapper, \DOMDocument $doc, \Langsys\SDK\Html\MarkupTokenizer $tokenizer, $rendered, array $encoded, \Langsys\SDK\Html\ValueMarkers $markers)
    {
        while ($wrapper->firstChild !== null) {
            $wrapper->removeChild($wrapper->firstChild);
        }
        foreach ($tokenizer->render($rendered, $encoded['slots'], $doc, $encoded['opaque']) as $node) {
            $wrapper->appendChild($node);
        }
        $markers->finish($wrapper);

        $html = '';
        foreach ($wrapper->childNodes as $child) {
            $html .= \Langsys\SDK\Html\Document::html($child);
        }

        return $html;
    }

    /**
     * Mark a page rendered in a non-base locale as resolved (GATE-10), on the
     * HTML as it is: `data-ls-resolved="<locale>"` is added to its `<html>`
     * start tag, or, when it has none - a fragment response - to each of its
     * top-level elements' start tags, read as a browser reads its tags, and
     * nothing else in the page changes. A page in the base locale, a page
     * whose base locale is unknown, and an element already carrying the
     * marker in either spelling are left as they are.
     *
     * @param string $html
     * @param string|null $locale The locale the page was rendered in
     * @return string
     */
    public function markResolved($html, $locale = null)
    {
        $html = (string) $html;
        $locale = $locale === null ? $this->getLocale() : LocaleDetector::normalize($locale);

        if ($locale === null || $locale === '') {
            return $html;
        }

        try {
            $project = $this->getProject();
        } catch (\Throwable $e) {
            return $html;
        }

        $base = (is_array($project) && isset($project['base_locale']) && is_string($project['base_locale']))
            ? LocaleDetector::normalize($project['base_locale'])
            : null;

        if ($base === null || $base === '' || $base === $locale) {
            return $html;
        }

        $tags = \Langsys\SDK\Html\Document::startTagsAt($html);
        $roots = array_values(array_filter($tags, function ($tag) {
            return $tag[0] === 'html';
        }));
        $roots = $roots !== [] ? [$roots[0]] : array_filter($tags, function ($tag) {
            return $tag[3] === 0;
        });

        $marker = ' ' . HtmlParser::RESOLVED_MARKERS[0] . '="' . htmlspecialchars($locale, ENT_QUOTES) . '"';
        foreach (array_reverse($roots) as $root) {
            list(, $offset, $length) = $root;
            $startTag = substr($html, $offset, $length);

            // One the input ends inside, and one carrying a marker, stay as written.
            if (substr($startTag, -1) !== '>' || preg_match('/\sdata-(?:ls|langsys)-resolved(?=[\s=\/>])/i', $startTag)) {
                continue;
            }

            $close = \Langsys\SDK\Html\Document::selfClosing($startTag) ? $length - 2 : $length - 1;
            $html = substr($html, 0, $offset) . substr($startTag, 0, $close) . $marker . substr($startTag, $close) . substr($html, $offset + $length);
        }

        return $html;
    }

    /**
     * Answer a phrase the catalog has no translation for from the framework's
     * own language files, before the source does (FRM-3). Called as
     * `$fallback($phrase, $locale, $category, $argument)` - the Langsys
     * phrase, the render locale, the category (null when none) and the
     * argument as the caller wrote it, a key for a key-style call - and
     * returns that line unfilled, or null. The core fills it with the call's
     * params. Its answer is never registered and never cached as catalog.
     *
     * @param callable|null $fallback
     * @return $this
     */
    public function useMissFallback(?callable $fallback = null)
    {
        $this->missFallback = $fallback;

        return $this;
    }

    /**
     * translate()'s body, with the provenance of what it returns.
     *
     * @param mixed $phrase
     * @param string|null $locale
     * @param string|null $category
     * @param string|null $contentBlockId
     * @param array $params
     * @param string|null $from Set to catalog, fallback or source
     * @param callable|null $earlier A miss's lookup under the phrase's earlier key (TOK-1)
     * @param string|null $argument What the caller wrote, for the miss fallback, when it is not $phrase
     * @param string|null $previousCategory The category the phrase moved from (MOVE-1)
     * @return string
     */
    protected function translateCall($phrase, $locale, $category, $contentBlockId, array $params, &$from, ?callable $earlier = null, $argument = null, $previousCategory = null)
    {
        $this->requireCredentialsAtUse();

        if ($argument === null) {
            $argument = $phrase;
        }

        // Legacy-key mode (MIG-2): the argument is a key first. A hit makes the
        // key's source value the phrase - never the key - and its namespace the
        // category unless the caller chose one. A miss is literal source text,
        // except a package key, which never registers as a key-shaped phrase.
        $legacy = $contentBlockId === null ? $this->getLegacyKeys() : null;
        $hit = false;

        if ($legacy !== null && is_string($phrase)) {
            $entry = $legacy->resolve($phrase);

            if ($entry === null) {
                if (LegacyKeys::isPackageKey($phrase)) {
                    $this->logger->debug('A package key is not in the migration source files; nothing is registered', ['argument' => $phrase]);

                    $from = 'source';

                    return $phrase;
                }

                $this->logger->debug('Not a key in the migration source files; treated as source text', ['argument' => $phrase]);
            } else {
                if (!$entry['recognised']) {
                    $this->logger->warning('A migration source value is registered as written: it ' . $entry['issue'], ['key' => $entry['key'], 'file' => $entry['file']]);
                }

                $phrase = $entry['phrase'];
                $hit = true;

                if (($category === null || $category === '' || $category === self::UNCATEGORIZED) && $entry['category'] !== null) {
                    $category = $entry['category'];
                }
            }
        }

        // MOVE-1: whether the call names a move is decided against the
        // category it renders under, a key's group included: `''` names one,
        // its own category names none. A call that names none takes the
        // configured map's entry for that category.
        $key = $this->normalizeCategory($category);
        $previousCategory = TranslatableItems::previousCategoryFor($previousCategory, $key === self::UNCATEGORIZED ? null : $key);
        if ($previousCategory === null && $contentBlockId === null) {
            $map = $this->config->getPreviousCategories();
            if (isset($map[$key])) {
                $previousCategory = TranslatableItems::previousCategoryFor($map[$key], $key === self::UNCATEGORIZED ? null : $key);
            }
        }

        // MIG-4: a hit prints its number params as plain digits, as the
        // source framework did, its source and its translation alike.
        $previousHit = $this->renderingLegacyHit;
        $this->renderingLegacyHit = $hit;

        try {
            return $this->translateSource($phrase, $locale, $category, $contentBlockId, $params, true, $from, $argument, $earlier, $previousCategory);
        } finally {
            $this->renderingLegacyHit = $previousHit;
        }
    }

    /**
     * TOK-1: the lookup a phrase holding an excluded element makes on a miss,
     * under the key an earlier reader gave it - one that walked into the
     * element - with that translation brought to the current slots. Lookup
     * only: the earlier key is never registered.
     *
     * @param \Langsys\SDK\Html\MarkupTokenizer $tokenizer
     * @param array|null $earlier The tokenizer's `earlier`
     * @return callable|null category slice => translation, or null
     */
    private function earlierLookup(\Langsys\SDK\Html\MarkupTokenizer $tokenizer, ?array $earlier = null)
    {
        if ($earlier === null) {
            return null;
        }

        return function (array $slice) use ($tokenizer, $earlier) {
            $value = isset($slice[$earlier['text']]) ? $slice[$earlier['text']] : null;

            return is_string($value) && $value !== '' ? $tokenizer->fromEarlier($value, $earlier) : null;
        };
    }

    /**
     * Look a source phrase up in the catalog, queue it when it is new, and
     * render it: translate() once any legacy key has been resolved, and the
     * phrase a one-phrase content-block fragment registers as (TOK-6).
     *
     * @param string $phrase
     * @param string|null $locale
     * @param string|null $category
     * @param string|null $contentBlockId
     * @param array $params
     * @param bool $fromCall Whether translate() or resolve() asked, which the
     *                       runtime registration switch governs (FRM-2)
     * @param string|null $from Set to catalog, fallback or source
     * @param mixed $argument The argument as the caller wrote it
     * @param callable|null $earlier A miss's lookup under the phrase's earlier
     *                              key: category slice => translation or null
     * @param string|null $previousCategory The category the phrase moved from:
     *                                      sent on its registration, and read
     *                                      last while the current category has
     *                                      no translation (MOVE-1, MOVE-4)
     * @return string
     */
    protected function translateSource($phrase, $locale, $category, $contentBlockId, array $params, $fromCall = false, &$from = null, $argument = null, ?callable $earlier = null, $previousCategory = null)
    {
        $from = 'source';
        $this->filledFromPrevious = false;
        if ($contentBlockId !== null) {
            $previousCategory = null;
        }

        // TOK-2: a code-registered key drops the C0 controls on lookup and on
        // register alike, as every DOM path does.
        if (is_string($phrase)) {
            $phrase = Canonical::codePhrase($phrase);
        }

        // Use set locale if not provided
        if ($locale === null) {
            $locale = $this->getLocale();
            if ($locale === null) {
                // Can't translate without locale, but placeholders still resolve.
                return $this->interpolate($phrase, $params, null);
            }
        }

        $category = $this->normalizeCategory($category);

        // FRM-7: a value from a set declared for its placeholder is written
        // in, and the written-in sentence is the phrase (MSG-3).
        $template = null;
        if ($contentBlockId === null && $this->valueSets !== null && is_string($phrase)) {
            $written = $this->valueSets->writeIn($phrase, $params);
            if ($written !== null) {
                $template = $phrase;
                list($phrase, $params) = $written;
            }
        }

        // A seeded snapshot answers a phrase it holds with no fetch and no
        // registration decision; a phrase it does not hold goes to the live
        // catalog, which decides (SNAP-2, REG-13).
        $seed = $contentBlockId === null ? $this->seedCatalog($locale) : null;
        if ($seed !== null && isset($seed[$category]) && is_array($seed[$category])
            && array_key_exists($phrase, $seed[$category]) && !is_array($seed[$category][$phrase])) {
            $value = $seed[$category][$phrase];

            if ($value !== null && $value !== '') {
                $from = 'catalog';

                return $this->interpolate($value, $params, $locale);
            }

            $value = $earlier === null ? null : $earlier($seed[$category]);
            if ($value !== null) {
                $from = 'catalog';

                return $this->interpolate($value, $params, $locale);
            }

            if (($value = $this->previousPhraseTranslation($seed, $phrase, $previousCategory, $earlier)) !== null) {
                return $this->renderFromPrevious($value, $params, $locale, $from);
            }

            return $this->answerMiss($phrase, $locale, $category, $params, $from, $argument);
        }

        try {
            $translations = $this->getTranslations($locale);
        } catch (\Throwable $e) {
            // \Throwable, not \Exception: an \Error (TypeError from a
            // wrong-shaped cache hit, ValueError, ArithmeticError) is not an
            // Exception, so an \Exception-only catch is an ENUMERATION of the
            // failures we happened to think of - and the ones we didn't take the
            // render down. WIRE-4 says this call must never throw; the catch has
            // to be as wide as the promise.
            //
            // This method sits on every render path, so the API being
            // unreachable must degrade to source text rather than turn a working
            // page into a 500. Nothing is queued: a failed catalog fetch cannot
            // tell a miss from a hit, and registering on a guess would turn every
            // outage into a write storm on the paths already failing.
            //
            // Behind the framework's own function (FRM-3) a catalog that
            // cannot be read counts as empty, and the cause is reported once
            // per process, at debug: an app's tests and CI run without the
            // API, and every call would otherwise log an error.
            if ($fromCall) {
                if (!static::$catalogUnavailableNoticed && !$e instanceof ConfigurationException) {
                    static::$catalogUnavailableNoticed = true;
                    $this->logger->debug('No catalog to consult - translate() continues to the language files, then the source', [
                        'locale' => $locale,
                        'error' => $e->getMessage(),
                    ]);
                }
            } else {
                $this->logDegradedRead('Translation lookup failed - returning source phrase', [
                    'phrase' => $phrase,
                    'category' => $category,
                    'locale' => $locale,
                    'error' => $e->getMessage(),
                ], $e);
            }

            if ($seed !== null && ($value = $this->previousPhraseTranslation($seed, $phrase, $previousCategory, $earlier)) !== null) {
                return $this->renderFromPrevious($value, $params, $locale, $from);
            }

            return $this->answerMiss($phrase, $locale, $category, $params, $from, $argument);
        }

        $categoryTranslations = isset($translations[$category]) ? $translations[$category] : [];

        // Handle content block phrase lookup (don't queue - content block handles its own registration)
        if ($contentBlockId !== null) {
            if (isset($categoryTranslations[$contentBlockId][$phrase])) {
                $from = 'catalog';

                return $this->interpolate($categoryTranslations[$contentBlockId][$phrase], $params, $locale);
            }
            return $this->interpolate($phrase, $params, $locale);
        }

        // Regular phrase lookup
        if (array_key_exists($phrase, $categoryTranslations)) {
            $value = $categoryTranslations[$phrase];
            // If it's an array (content block ID collision), return original phrase
            if (is_array($value)) {
                return $this->interpolate($phrase, $params, $locale);
            }
            // A registered-but-untranslated phrase comes back present with a
            // NULL value. Both null and '' mean "no translation yet": the miss
            // fallback answers, else the source phrase - returning the value
            // would hand the caller null from a method that contracts to return
            // a string.
            if ($value !== null && $value !== '') {
                $from = 'catalog';

                return $this->interpolate($value, $params, $locale);
            }

            // Queued earlier in this request by a call naming no move: the
            // first previous category named still travels (MOVE-3), whatever
            // this render is filled from.
            $this->notePreviousCategory(Canonical::codePhrase($phrase), $category, null, $previousCategory);

            $value = $earlier === null ? null : $earlier($categoryTranslations);
            if ($value !== null) {
                $from = 'catalog';

                return $this->interpolate($value, $params, $locale);
            }

            // Present with no translation - registered and untranslated, or
            // queued earlier in this request - renders from the previous
            // category as the first miss did (MOVE-4).
            if (($value = $this->previousPhraseTranslation($translations, $phrase, $previousCategory, $earlier)) !== null) {
                return $this->renderFromPrevious($value, $params, $locale, $from);
            }

            return $this->answerMiss($phrase, $locale, $category, $params, $from, $argument);
        }

        // Phrase not found - queue the RAW phrase (placeholders intact) for
        // registration, then interpolate only what we return to the caller.
        // Where a sync command registers (FRM-2), a translate() call registers
        // nothing, except a declared value's sentence the last sync did not
        // see (FRM-7).
        if (!$fromCall || $this->runtimeRegistration || ($template !== null && $this->templateSynced($template, $phrase, $categoryTranslations))) {
            $this->queuePhraseForRegistration($phrase, $category, $previousCategory);
        }

        // A translation under the phrase's earlier key renders it, while only
        // the current key registers (TOK-1).
        $value = $earlier === null ? null : $earlier($categoryTranslations);
        if ($value !== null) {
            $from = 'catalog';

            return $this->interpolate($value, $params, $locale);
        }

        // Last, the previous category's entry, while the miss still
        // registers under the current one (MOVE-4).
        if (($value = $this->previousPhraseTranslation($translations, $phrase, $previousCategory, $earlier)) !== null) {
            return $this->renderFromPrevious($value, $params, $locale, $from);
        }

        return $this->answerMiss($phrase, $locale, $category, $params, $from, $argument);
    }

    /**
     * Render a translation read under the previous category (MOVE-4). It is
     * catalog text, so it reports as `catalog` (FRM-8).
     *
     * @param string $value
     * @param array $params
     * @param string $locale
     * @param string|null $from
     * @return string
     */
    private function renderFromPrevious($value, array $params, $locale, &$from)
    {
        $from = 'catalog';
        $this->filledFromPrevious = true;

        return $this->interpolate($value, $params, $locale);
    }

    /**
     * What a phrase with no catalog translation renders as (FRM-3): the miss
     * fallback's line when it has one, else the source, filled either way.
     *
     * @param string $phrase
     * @param string $locale
     * @param string|null $category
     * @param array $params
     * @param string|null $from
     * @param mixed $argument
     * @return string
     */
    protected function answerMiss($phrase, $locale, $category, array $params, &$from, $argument)
    {
        if ($this->missFallback !== null) {
            try {
                $line = call_user_func($this->missFallback, $phrase, $locale, $category === self::UNCATEGORIZED ? null : $category, $argument === null ? $phrase : $argument);
            } catch (\Throwable $e) {
                $this->logger->error('The miss fallback failed - returning source phrase', ['phrase' => $phrase, 'error' => $e->getMessage()]);
                $line = null;
            }

            if (is_string($line) && $line !== '') {
                $from = 'fallback';

                return $this->interpolate($line, $params, $locale);
            }
        }

        $from = 'source';

        return $this->interpolate($phrase, $params, $locale);
    }

    /**
     * Whether a sync registered a template's written-in sentences (FRM-7): the
     * catalog holds one of them other than $phrase. The catalog is the record
     * of what sync registered.
     *
     * @param string $template
     * @param string $phrase
     * @param array $categoryTranslations
     * @return bool
     */
    protected function templateSynced($template, $phrase, array $categoryTranslations)
    {
        foreach ($this->valueSets->sentences($template) as $sentence) {
            if ($sentence !== $phrase && array_key_exists($sentence, $categoryTranslations)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Seed the catalog from a snapshot (SNAP-2). Lookups read it with no
     * network call until the live catalog for a locale has been read, which
     * then outranks it; whether a phrase is registered is decided only against
     * the live catalog. While authorization is unavailable, the snapshot also
     * gives the request-locale resolver the locales it may serve (SRV-6).
     * When to seed - at boot, per request, before hydration - is the caller's.
     * A snapshot made for another project is not used, and a warning says so.
     *
     * @param Snapshot $snapshot
     * @return $this
     */
    public function useSnapshot(Snapshot $snapshot)
    {
        // A snapshot answers only for the project it was made from: another
        // project's would serve its translations here with no request.
        $project = (string) $this->config->getProjectId();
        if ($project !== '' && $snapshot->projectId() !== $project) {
            $this->logger->warning('A snapshot made for another project is not used', [
                'project_id' => $project,
                'snapshot_project_id' => $snapshot->projectId(),
            ]);

            return $this;
        }

        $this->snapshot = $snapshot;

        return $this;
    }

    /**
     * The snapshot's catalog for a locale while it still answers - seeded,
     * holding the locale, and the live catalog not yet read - else null.
     *
     * @param string $locale
     * @return array|null category => entries
     */
    public function seedCatalog($locale)
    {
        if ($this->snapshot === null) {
            return null;
        }

        $locale = LocaleDetector::normalize($locale);

        return isset($this->liveCatalogs[$locale]) ? null : $this->snapshot->catalog($locale);
    }

    /**
     * The legacy-key resolver, or null when the migration mode is off (MIG-1).
     * Created on first use; files are read on the first lookup, never here.
     *
     * @return LegacyKeys|null
     */
    public function getLegacyKeys()
    {
        if ($this->legacyKeys === null) {
            $migration = $this->config->getMigration();
            $this->legacyKeys = $migration === null ? false : new LegacyKeys($migration);
        }

        return $this->legacyKeys === false ? null : $this->legacyKeys;
    }

    /**
     * Resolve a legacy key without translating it: its source phrase, the
     * category it registers under (the caller's, when given), the key and the
     * file that answered. Null when the mode is off or no file holds the key.
     *
     * @param string $key
     * @param string|null $category
     * @return array{phrase: string, category: string|null, key: string, file: string, recognised: bool, issue: string|null}|null
     */
    public function resolveLegacyKey($key, $category = null)
    {
        $legacy = $this->getLegacyKeys();
        $entry = $legacy === null ? null : $legacy->resolve($key);

        if ($entry === null) {
            return null;
        }

        return [
            'phrase' => $entry['phrase'],
            'category' => ($category === null || $category === '' || $category === self::UNCATEGORIZED) ? $entry['category'] : $category,
            'key' => $entry['key'],
            'file' => $entry['file'],
            'recognised' => $entry['recognised'],
            'issue' => $entry['issue'],
        ];
    }

    /**
     * Import the translations an app already has (MIG-9), once, before its
     * target-language files are deleted.
     *
     * Every key the `migration` option's files define registers its source
     * phrase, as a lookup of that key would, together with each target
     * locale's translation of the same key, converted the same way. The API
     * stores those as human translations, so work already done shows at once
     * and is never re-machine-translated.
     *
     * A target file's value that is missing, empty or does not convert is
     * not a translation: it is left for machine translation and listed in
     * `skipped`. A locale the project does not target is refused before
     * anything is sent.
     *
     * @param array<string, array> $targets locale => migration configuration
     *                                      for that locale's files, in the
     *                                      `migration` option's shape
     * @return array{success: bool, reason: string|null, phrases: int, translations: int, human_translations_saved: int, human_translations_skipped: int, skipped: array}
     * @throws LangsysException When the migration mode is off, or a locale is not a target of the project
     */
    public function importLegacyTranslations(array $targets)
    {
        $this->assertConfigured('import translations');

        $source = $this->getLegacyKeys();
        if ($source === null) {
            throw new LangsysException('Nothing to import: the migration option names no source-language files.');
        }

        $readers = $this->targetReaders($targets);

        $result = [
            'success' => false,
            'reason' => null,
            'phrases' => 0,
            'translations' => 0,
            'human_translations_saved' => 0,
            'human_translations_skipped' => 0,
            'skipped' => [],
        ];

        if (!$this->canWrite()) {
            $result['reason'] = 'not_write_enabled';

            return $result;
        }

        $items = [];

        foreach ($source->keys() as $key) {
            $entry = $this->resolveLegacyKey($key);
            if ($entry === null) {
                continue;
            }

            // Registered as translate() looks the key up; a blank line, by the
            // collapsed form, registers nothing.
            $phrase = Canonical::codePhrase($entry['phrase']);
            if (Canonical::phrase($phrase) === '') {
                continue;
            }

            $id = json_encode([$entry['category'], $phrase]);
            if (!isset($items[$id])) {
                $items[$id] = ['phrase' => $phrase, 'category' => $entry['category'], 'translations' => []];
            }

            $items[$id]['translations'] += \Langsys\SDK\Sync\Planner::lineTranslations($key, $readers, $result['skipped']);
        }

        try {
            $outcome = $this->translatableItems->importPhrases(array_values($items));
        } catch (\Throwable $e) {
            $this->logger->error('Import failed', ['error' => $e->getMessage()]);
            $result['reason'] = 'send_failed';

            return $result;
        }

        $result['success'] = true;
        $result['phrases'] = count($items);
        foreach ($items as $item) {
            $result['translations'] += count($item['translations']);
        }
        $result['human_translations_saved'] = $outcome['human_translations_saved'];
        $result['human_translations_skipped'] = $outcome['human_translations_skipped'];

        $this->logger->info('Imported existing translations', [
            'phrases' => $result['phrases'],
            'translations' => $result['translations'],
            'saved' => $result['human_translations_saved'],
            'skipped_for_quota' => $result['human_translations_skipped'],
        ]);

        return $result;
    }

    /**
     * A reader for each target locale's language files, refusing a locale the
     * project does not target before anything is read or sent.
     *
     * @param array<string, array> $targets locale => migration configuration
     * @return array<string, LegacyKeys>
     * @throws LangsysException
     */
    protected function targetReaders(array $targets)
    {
        $project = $this->getProject();
        $targeted = array_map([LocaleDetector::class, 'normalize'], isset($project['target_locales']) && is_array($project['target_locales']) ? $project['target_locales'] : []);

        $readers = [];
        foreach ($targets as $locale => $config) {
            $normalized = LocaleDetector::normalize($locale);
            if (!in_array($normalized, $targeted, true)) {
                throw new LangsysException(sprintf('The locale %s is not a target locale of this project, so its translations cannot be imported.', $locale));
            }
            $readers[$normalized] = new LegacyKeys(is_array($config) ? $config : []);
        }

        return $readers;
    }

    /**
     * Plan a sync (FRM-2): every phrase the app's translate calls and its
     * base language's files hold, each decided against a freshly read catalog
     * as already there, new with the translations the language files hold,
     * or new alone.
     *
     * The base language's files are the `migration` option's, and the
     * declared value sets the `value_sets` option's; Sync\Planner holds the
     * rules, and plans offline - with no catalog and no key - through
     * Planner::offline().
     *
     * @param array[] $hits SourceScanner hits
     * @param array<string, array> $targets locale => migration configuration of that locale's files
     * @param array $options `covered_groups`: groups whose base-language lines
     *                       register another way, so a key built at runtime in
     *                       one is covered even though the `migration` files
     *                       do not hold it (Laravel's validation lines);
     *                       `previous_categories`: category => the category it
     *                       was before, so a renamed key group moves every line
     *                       registered under it (MOVE-1); the rest as
     *                       Planner::plan() takes them
     * @return \Langsys\SDK\Sync\SyncPlan
     * @throws LangsysException When a locale is not a target of the project
     */
    public function planSync(array $hits, array $targets = [], array $options = [])
    {
        $this->assertConfigured('plan a sync');

        $planner = new \Langsys\SDK\Sync\Planner($this->getLegacyKeys(), $this->valueSets);

        // The configured map is the default (MOVE-1), and the messages
        // category's own previous one wins over any entry it has (MSG-6).
        if (!array_key_exists('previous_categories', $options)) {
            $options['previous_categories'] = $this->config->getPreviousCategories();
        }
        if (!array_key_exists('messages_previous_category', $options)) {
            $options['messages_previous_category'] = $this->config->getMessagesPreviousCategoryOption();
        }
        if (!array_key_exists('messages_category', $options)) {
            $options['messages_category'] = $this->config->getMessagesCategory();
        }

        return $planner->plan($hits, $this->targetReaders($targets), $this->syncCatalog(), $options);
    }

    /**
     * Carry out a sync plan: register every item it does not find in the
     * catalog, with its translations where it has them.
     *
     * @param \Langsys\SDK\Sync\SyncPlan $plan
     * @return array{success: bool, reason: string|null, registered: int, translations: int, human_translations_saved: int, human_translations_skipped: int}
     */
    public function applySync(\Langsys\SDK\Sync\SyncPlan $plan)
    {
        $this->assertConfigured('apply a sync');

        $items = $plan->toRegister();
        $result = ['success' => false, 'reason' => null, 'registered' => 0, 'translations' => 0, 'human_translations_saved' => 0, 'human_translations_skipped' => 0];

        if (!$this->canWrite()) {
            $result['reason'] = 'not_write_enabled';

            return $result;
        }

        if ($items !== []) {
            try {
                $outcome = $this->translatableItems->importPhrases($items);
            } catch (\Throwable $e) {
                $this->logger->error('Sync failed', ['error' => $e->getMessage()]);
                $result['reason'] = 'send_failed';

                return $result;
            }

            $result['human_translations_saved'] = $outcome['human_translations_saved'];
            $result['human_translations_skipped'] = $outcome['human_translations_skipped'];
        }

        $result['success'] = true;
        $result['registered'] = count($items);
        foreach ($items as $item) {
            $result['translations'] += count($item['translations']);
        }

        return $result;
    }

    /**
     * The catalog a sync decides against, read fresh: the first target
     * locale's, which lists every phrase the project holds.
     *
     * @return array
     */
    protected function syncCatalog()
    {
        $project = $this->getProject();
        $locales = isset($project['target_locales']) && is_array($project['target_locales']) ? $project['target_locales'] : [];
        $locale = $locales !== [] ? LocaleDetector::normalize(reset($locales)) : (isset($project['base_locale']) ? LocaleDetector::normalize($project['base_locale']) : null);

        return $locale === null ? [] : $this->translations->getTranslationMap($locale);
    }

    /**
     * Render a server message entry (MSG-5, MSG-6).
     *
     * The entry's TEMPLATE is looked up under the messages category and the
     * translation is filled from the entry's params, so a count param renders
     * through the catalog's ICU like any other phrase. With no translation - a
     * miss, a template registered but not yet translated, a failed lookup, no
     * locale - the entry's own message comes back verbatim. The server already
     * filled it; filling the template again here would format its numbers for
     * this locale and disagree with what the server sent. The message is never
     * used as a lookup key: it is filled, possibly localised, text.
     *
     * A miss queues the template for registration, as emitMessage() does,
     * unless runtime registration is off (FRM-2).
     *
     * @param ServerMessage|array $entry An entry, or its wire form
     * @param string|null $locale
     * @return string
     */
    public function translateMessage($entry, $locale = null)
    {
        $this->requireCredentialsAtUse();

        if (is_array($entry)) {
            $parsed = ServerMessage::fromArray($entry);

            if ($parsed === null) {
                return (isset($entry['message']) && is_string($entry['message'])) ? $entry['message'] : '';
            }

            $entry = $parsed;
        }

        if (!$entry instanceof ServerMessage) {
            return '';
        }

        // An entry with no template has nothing to look up: it is its message.
        if ($entry->getTemplate() === null) {
            return $entry->getMessage();
        }

        if ($locale === null) {
            $locale = $this->messageLocale();

            if ($locale === null) {
                return $entry->getMessage();
            }
        }

        $category = $this->normalizeCategory($this->config->getMessagesCategory());
        $previousCategory = $this->config->getMessagesPreviousCategory();
        $found = $this->lookupMessageTemplate($entry->getTemplate(), $category, $locale, $previousCategory);

        if ($found === null) {
            return $entry->getMessage();
        }

        $this->noteTranslatableMarkerValues($entry, $locale);

        list($listed, $translation) = $found;

        // A miss under the messages category registers there, naming where
        // the template was before; until it is translated there, the previous
        // category's translation renders (MOVE-3, MOVE-4).
        if (!$listed && $this->runtimeRegistration) {
            $this->queuePhraseForRegistration($entry->getTemplate(), $category, $previousCategory);
        }

        if ($translation === null) {
            return $entry->getMessage();
        }

        return $this->interpolate($translation, $entry->getParams(), $locale);
    }

    /**
     * Note that the server is sending an entry (MSG-8).
     *
     * A template the catalog does not list under the messages category is queued
     * on the existing registration path: it is sent after the response, once, and
     * only if this request's key may write. Nothing is sent here. When the catalog
     * cannot be read, or there is no locale to read it in, nothing is queued -
     * a miss cannot be told from a hit, and registering on a guess would turn an
     * outage into a write on every failing request.
     *
     * @param ServerMessage $message
     * @return ServerMessage The same entry, so a caller can emit it inline
     */
    public function emitMessage(ServerMessage $message)
    {
        $this->requireCredentialsAtUse();

        if ($message->getTemplate() === null) {
            return $message;
        }

        $locale = $this->messageLocale();

        if ($locale === null) {
            return $message;
        }

        $category = $this->normalizeCategory($this->config->getMessagesCategory());
        $previousCategory = $this->config->getMessagesPreviousCategory();
        $found = $this->lookupMessageTemplate($message->getTemplate(), $category, $locale, $previousCategory);

        if ($found !== null && !$found[0] && $this->runtimeRegistration) {
            $this->queuePhraseForRegistration($message->getTemplate(), $category, $previousCategory);
        }

        if ($found !== null) {
            $this->noteTranslatableMarkerValues($message, $locale);
        }

        return $message;
    }

    /**
     * MSG-3 / MSG-11: a marker carries only a value that is not translatable.
     * A template cannot show what its markers will hold, but a render can: a
     * marker whose value is itself a phrase in the project's catalog is carrying
     * translatable text, which never reaches the translator and cannot inflect
     * the sentence around it. Warned once per template and marker, naming both
     * and the value. A translatable value the catalog has never seen is not
     * detectable here; keeping it out of markers is the template author's.
     *
     * @param ServerMessage $message
     * @param string $locale
     * @return void
     */
    protected function noteTranslatableMarkerValues(ServerMessage $message, $locale)
    {
        $params = $message->getParams();

        foreach (MessageTemplate::markers($message->getTemplate()) as $marker) {
            $key = $message->getTemplate() . "\0" . $marker;

            if (isset($this->reportedMarkerValues[$key]) || !isset($params[$marker]) || !is_string($params[$marker])) {
                continue;
            }

            $value = Canonical::phrase($params[$marker]);

            if ($value === '' || is_numeric($value)) {
                continue;
            }

            // The catalog the message was rendered from: a seeded snapshot's
            // while it answers, else the live one.
            $translations = $this->seedCatalog($locale);
            if ($translations === null) {
                try {
                    $translations = $this->getTranslations($locale);
                } catch (\Throwable $e) {
                    return;
                }
            }

            foreach ($translations as $items) {
                if (is_array($items) && array_key_exists($value, $items) && !is_array($items[$value])) {
                    $this->reportedMarkerValues[$key] = true;
                    $this->logger->warning('A server message carries translatable text in a marker: the value is a phrase in the catalog, so it is never translated with the sentence. Write it into the template and list one template per value.', [
                        'template' => $message->getTemplate(),
                        'marker' => $marker,
                        'value' => $params[$marker],
                    ]);
                    break;
                }
            }
        }
    }

    /**
     * The locale a server message is read in, or null. Resolving it can reach the
     * network (the project's base locale), and a render path must not throw.
     *
     * @return string|null
     */
    protected function messageLocale()
    {
        try {
            return $this->getLocale();
        } catch (\Throwable $e) {
            $this->logger->error('Could not resolve a locale for a server message', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Whether the catalog lists a template, and its translation.
     *
     * Listed is decided under the messages category alone (MOVE-4: a miss
     * there still registers). With no translation there, the template's entry
     * under the category the messages were in before is the translation, read
     * last, from the same catalog.
     *
     * @param string $template
     * @param string $category
     * @param string $locale
     * @param string|null $previousCategory The messages category's previous one (MSG-6, MOVE-1)
     * @return array|null [bool $listed, string|null $translation], or null when the catalog could not be read
     */
    protected function lookupMessageTemplate($template, $category, $locale, $previousCategory = null)
    {
        $template = Canonical::codePhrase($template);
        $previousKey = $previousCategory === null ? null : self::previousCatalogKey($previousCategory);
        if ($previousKey === $category) {
            $previousKey = null;
        }

        $seed = $this->seedCatalog($locale);
        if ($seed !== null && isset($seed[$category]) && is_array($seed[$category]) && array_key_exists($template, $seed[$category])) {
            return [true, self::messageTranslationIn($seed, $category, $template, $previousKey)];
        }

        try {
            $translations = $this->getTranslations($locale);
        } catch (\Throwable $e) {
            $this->logDegradedRead('Server message lookup failed - using the message the server sent', [
                'template' => $template,
                'category' => $category,
                'locale' => $locale,
                'error' => $e->getMessage(),
            ], $e);

            return null;
        }

        $listed = isset($translations[$category]) && is_array($translations[$category]) && array_key_exists($template, $translations[$category]);

        return [$listed, self::messageTranslationIn($translations, $category, $template, $previousKey)];
    }

    /**
     * A template's translation in one catalog: its entry under the messages
     * category, else under the previous one (MOVE-4); null or empty is none.
     *
     * @param array $catalog category => entries
     * @param string $category
     * @param string $template
     * @param string|null $previousKey The previous category's catalog key
     * @return string|null
     */
    private static function messageTranslationIn(array $catalog, $category, $template, $previousKey)
    {
        foreach ($previousKey === null ? [$category] : [$category, $previousKey] as $key) {
            if (isset($catalog[$key]) && is_array($catalog[$key]) && array_key_exists($template, $catalog[$key])) {
                $value = $catalog[$key][$template];
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * Get the placeholder interpolator.
     *
     * @return Interpolator
     */
    public function getInterpolator()
    {
        if ($this->interpolator === null) {
            $this->interpolator = new Interpolator($this->logger);
        }

        return $this->interpolator;
    }

    /**
     * Interpolate placeholder values into a string.
     *
     * @param string $text
     * @param array $params
     * @param string|null $locale
     * @return string
     */
    protected function interpolate($text, array $params, $locale)
    {
        // Deliberately NOT short-circuiting on empty params. A catalog value can
        // contain an ICU construct the caller knows nothing about - the backend
        // promotes a plain {name} into a gendered select for locales that need
        // one - so "no params" is precisely the case that needs recovery, not
        // the case that can skip it. Returning early here shipped raw
        // MessageFormat source to the page. The interpolator has its own fast
        // path for text with no construct in it.
        return $this->getInterpolator()->interpolate($text, \Langsys\SDK\Html\ValueMarkers::forText($params, $text), $locale, $this->renderingLegacyHit);
    }

    /**
     * Register new phrases with the API.
     *
     * @param array $phrases Array of phrases (strings or arrays with phrase, category, translatable)
     * @return array API response
     * @throws ConfigurationException If the client is not configured
     * @throws LangsysException If API key doesn't have write permissions
     */
    public function registerPhrases(array $phrases)
    {
        $this->assertConfigured('register phrases');

        if (!$this->canWrite()) {
            throw new LangsysException('Cannot register phrases: API key does not have write permissions');
        }

        return $this->translatableItems->createPhrases($phrases);
    }

    /**
     * Register a content block with the API.
     *
     * Phrases are automatically extracted from the HTML content.
     * Relative URLs (src, srcset, poster) are automatically resolved to absolute
     * URLs using the configured base_url or detected from $_SERVER.
     *
     * @param string $content HTML content of the content block
     * @param string|array|null $category Category for the content block, or
     *                                   `['category' => ..., 'previous_category' => ...]`
     *                                   for a block that moved (MOVE-1)
     * @param string|null $label Human-readable label for the content block
     * @param string|null $customId Custom ID (auto-generated from content hash if null)
     * @return array API response
     * @throws ConfigurationException If the client is not configured
     * @throws LangsysException If API key doesn't have write permissions
     */
    public function registerContentBlock($content, $category = null, $label = null, $customId = null)
    {
        $this->assertConfigured('register a content block');

        if (!$this->canWrite()) {
            throw new LangsysException('Cannot register content block: API key does not have write permissions');
        }

        // Resolve relative URLs before registration
        $content = $this->resolveContentBlockUrls($content);

        list($category, $previousCategory) = $this->splitCategory($category);

        return $this->translatableItems->createContentBlock($content, $category, $label, $customId, $previousCategory);
    }

    /**
     * Resolve relative URLs in content block HTML.
     *
     * @param string $html HTML content
     * @return string HTML with resolved URLs
     */
    protected function resolveContentBlockUrls($html)
    {
        $baseUrl = $this->config->getBaseUrl();
        if ($baseUrl === null) {
            return $html;
        }

        $parser = new HtmlParser($this->translatableItems->getTranslatableAttributes());
        return $parser->resolveRelativeUrls($html, $baseUrl);
    }

    /**
     * Sync local phrases with the remote API.
     *
     * This method:
     * 1. Fetches existing translations
     * 2. Compares with local phrases
     * 3. POSTs any new phrases (if write key)
     * 4. Returns updated translations
     *
     * @param array $localPhrases Array of local phrases [['phrase' => '...', 'category' => '...'], ...]
     * @param string $locale Locale to sync (for fetching existing translations)
     * @return array Sync result with 'translations', 'new_phrases', and 'synced' keys
     */
    public function sync(array $localPhrases, $locale)
    {
        $this->assertConfigured('sync');

        // Get existing translations
        $translations = $this->getTranslations($locale, false);

        // Build a set of existing phrases
        $existingPhrases = [];
        foreach ($translations as $category => $items) {
            foreach ($items as $phrase => $translation) {
                if (is_array($translation)) {
                    // Content block
                    foreach ($translation as $blockPhrase => $blockTranslation) {
                        $existingPhrases[$category . '::' . $blockPhrase] = true;
                    }
                } else {
                    $existingPhrases[$category . '::' . $phrase] = true;
                }
            }
        }

        // Find new phrases
        $newPhrases = [];
        foreach ($localPhrases as $phraseData) {
            $phrase = Canonical::codePhrase(is_string($phraseData) ? $phraseData : $phraseData['phrase']);
            $category = is_string($phraseData)
                ? '__uncategorized__'
                : (isset($phraseData['category']) ? $phraseData['category'] : '__uncategorized__');

            $key = $category . '::' . $phrase;

            if (!isset($existingPhrases[$key])) {
                $newPhrases[] = is_string($phraseData)
                    ? ['phrase' => $phrase, 'category' => $category]
                    : $phraseData;
            }
        }

        $result = [
            'translations' => $translations,
            'new_phrases' => $newPhrases,
            'synced' => false,
        ];

        // If we have new phrases and can write, register them
        if (!empty($newPhrases) && $this->canWrite()) {
            $this->registerPhrases($newPhrases);
            $result['synced'] = true;

            // Invalidate cache and fetch fresh translations. clearCache()
            // normalises the locale, so es-ES clears the es-es entry.
            $this->clearCache($locale);
            $result['translations'] = $this->getTranslations($locale, false);
        }

        return $result;
    }

    /**
     * Clear the translation cache.
     *
     * @param string|null $locale Specific locale to clear, or null for all
     * @return bool
     */
    public function clearCache($locale = null)
    {
        $locale = LocaleDetector::normalize($locale);

        if ($locale !== null) {
            // Clear in-memory cache for this locale
            unset($this->translationsMemoryCache[$locale]);

            $cacheKey = 'translations_' . $this->config->getProjectId() . '_' . $locale;
            return $this->cache->delete($cacheKey);
        }

        // Clear all in-memory cache
        $this->translationsMemoryCache = [];

        return $this->cache->clear();
    }

    /**
     * Get the Translations resource for advanced usage.
     *
     * @return Translations
     */
    public function translations()
    {
        return $this->translations;
    }

    /**
     * Get the TranslatableItems resource for advanced usage.
     *
     * @return TranslatableItems
     */
    public function translatableItems()
    {
        return $this->translatableItems;
    }

    /**
     * Get the Utilities resource for countries, locales, and dial codes.
     *
     * @return Utilities
     */
    public function utilities()
    {
        return $this->utilities;
    }

    /**
     * The currencies, each named in a display locale: `code`, `name`, `symbol`,
     * `symbol_native`, `decimal_digits` and `rounding`.
     *
     * Each reference list (REF-1) is named in a display locale: the one given,
     * else the current one, the project's base locale when nothing chose one. A
     * list the API answered is held per display locale, in memory and in the
     * persistent cache with the catalog's lifetime, so a request after the
     * first sends nothing for it. A list the API cannot give, or an answer not
     * of the list's shape, is empty, with the cause logged, never an exception
     * (WIRE-4), and the failure is remembered for CACHE-2's window, inside which
     * the call answers empty without a request. A client built without
     * credentials sends nothing and answers an empty list.
     *
     * @param string|null $locale
     * @return array
     */
    public function getCurrencies($locale = null)
    {
        return $this->referenceList('currencies', 'currencies/', $locale, false);
    }

    /**
     * The countries, each named in a display locale: `label`, `code` and `eu`.
     *
     * @param string|null $locale
     * @return array
     */
    public function getCountries($locale = null)
    {
        return $this->referenceList('countries', 'countries/', $locale, false);
    }

    /**
     * The countries' dial codes, each named in a display locale:
     * `country_code`, `dial_code` and `name`.
     *
     * @param string|null $locale
     * @return array
     */
    public function getDialCodes($locale = null)
    {
        return $this->referenceList('dial-codes', 'countries/dial-codes/', $locale, false);
    }

    /**
     * The locales, named in a display locale, in one of the API's three forms:
     * `''` grouped by language name (`['Español' => [['code' => 'es-cr', 'name'
     * => ...], ...]]`), `'flat'` a list of `code` and `name`, `'data'` a list of
     * `code`, `locale_name` and `lang_name`. Every code is in the lowercase,
     * hyphenated form (`es-cr`) whatever form the organisation writes codes in.
     * Any other format sends nothing.
     *
     * @param string $format
     * @param string|null $locale
     * @return array
     */
    public function getLocalesFormat($format = '', $locale = null)
    {
        $this->requireCredentialsAtUse();

        if (!in_array($format, ['', 'flat', 'data'], true)) {
            $this->logger->warning('Unknown locales format; expected "", "flat" or "data"', ['format' => $format]);

            return [];
        }

        return $this->referenceList($format === '' ? 'locales' : 'locales-' . $format, $format === '' ? 'locales' : 'locales/' . $format, $locale, true);
    }

    /**
     * One reference list, held or read.
     *
     * A locale list asks for its one display locale with the project's target
     * locales left out and no project named, since the API otherwise appends
     * them in an order nothing can rely on, and answers the entry keyed by the
     * display locale. So a list depends on nothing but its display locale.
     *
     * @param string $list The list's name in the cache
     * @param string $route The route, or for a locale list the whole of it
     * @param string|null $locale
     * @param bool $localeList Whether the display locale goes in the query
     * @return array
     */
    private function referenceList($list, $route, $locale, $localeList)
    {
        $this->requireCredentialsAtUse();

        if (!$this->isConfigured()) {
            return [];
        }

        $display = $this->displayLocale($locale);
        if ($display === null) {
            return [];
        }

        $key = $list . '|' . $display;
        if (array_key_exists($key, $this->referenceLists)) {
            return $this->referenceLists[$key];
        }

        // A cache that fails - a Redis connection dropped - is a miss here and
        // nothing on the write below: no reference call throws.
        $cacheKey = 'reference_' . $this->config->getProjectId() . '_' . $list . '_' . $display;
        try {
            $cached = $this->cache->get($cacheKey);
        } catch (\Throwable $e) {
            $cached = null;
        }
        if (is_array($cached)) {
            return $this->referenceLists[$key] = $cached;
        }

        if (isset($this->referenceFailures[$key]) && $this->currentTime() < $this->referenceFailures[$key]['until']) {
            return [];
        }

        $endpoint = $localeList ? $route : $route . $display;
        $params = $localeList ? ['locales' => [$display], 'append_target_locales' => 0] : [];

        try {
            $response = $this->http->get($endpoint, $params);
        } catch (\Throwable $e) {
            $this->logger->error('Reference list could not be read', ['route' => $endpoint, 'locale' => $display, 'error' => $e->getMessage()]);

            return $this->referenceFailed($key);
        }

        $data = is_array($response) && isset($response['data']) && is_array($response['data']) ? $response['data'] : null;
        if ($data !== null) {
            $data = $localeList ? self::displayLocaleEntry($data, $display) : (self::isList($data) ? $data : null);
        }

        if ($data === null) {
            $this->logger->error('Reference list answer is not of the list\'s shape', ['route' => $endpoint, 'locale' => $display]);

            return $this->referenceFailed($key);
        }

        unset($this->referenceFailures[$key]);
        try {
            $this->cache->set($cacheKey, $data);
        } catch (\Throwable $e) {
            $this->logger->debug('Reference list not cached', ['route' => $endpoint, 'error' => $e->getMessage()]);
        }

        return $this->referenceLists[$key] = $data;
    }

    /**
     * Remember a failed reference read for CACHE-2's window, doubling on each
     * consecutive failure, and answer the list's empty value.
     *
     * @param string $key
     * @return array
     */
    private function referenceFailed($key)
    {
        $failures = isset($this->referenceFailures[$key]) ? $this->referenceFailures[$key]['failures'] + 1 : 1;
        $this->referenceFailures[$key] = [
            'failures' => $failures,
            'until' => $this->currentTime() + $this->backoffDelay($failures),
        ];

        return [];
    }

    /**
     * A locale list's answer: a map keyed by locale, in whatever code format
     * the organisation uses. Answers the display locale's entry with every
     * code in the lowercase, hyphenated form, an empty list when the API holds
     * no entry for it, or null when the answer is not of the shape.
     *
     * @param array $data
     * @param string $display
     * @return array|null
     */
    private static function displayLocaleEntry(array $data, $display)
    {
        if ($data !== [] && self::isList($data)) {
            return null;
        }

        foreach ($data as $locale => $entry) {
            if (LocaleDetector::normalize((string) $locale) !== $display) {
                continue;
            }
            if (!is_array($entry)) {
                return null;
            }

            $rows = function (array $list) {
                foreach ($list as $i => $row) {
                    if (is_array($row) && isset($row['code']) && is_string($row['code'])) {
                        $list[$i]['code'] = LocaleDetector::normalize($row['code']);
                    }
                }

                return $list;
            };

            if (self::isList($entry)) {
                return $rows($entry);
            }
            foreach ($entry as $group => $list) {
                $entry[$group] = is_array($list) ? $rows($list) : $list;
            }

            return $entry;
        }

        return [];
    }

    /**
     * @param array $value
     * @return bool
     */
    private static function isList(array $value)
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * The locale a reference list is named in: the one given, else the current
     * one, which a request with no locale of its own resolves to the project's
     * base locale, both normalised as the current locale is. One that is not
     * shaped as a locale - a path, a query - is refused, since it is written
     * into the request path, and the API does not check it.
     *
     * @param string|null $locale
     * @return string|null
     */
    private function displayLocale($locale)
    {
        $display = is_string($locale) && $locale !== '' ? LocaleDetector::normalize($locale) : $this->getLocale();

        if (!is_string($display) || !preg_match('/^[a-z]{2,8}(?:-[a-z0-9]{1,8})*$/', $display)) {
            if ($display !== null && $display !== '') {
                $this->logger->warning('A reference list was asked for in something that is not a locale; nothing was sent', ['locale' => $display]);
            }

            return null;
        }

        return $display;
    }

    /**
     * Get the cache instance.
     *
     * @return CacheInterface
     */
    public function getCache()
    {
        return $this->cache;
    }

    /**
     * Get the logger instance.
     *
     * @return LoggerInterface
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * Get a log viewer instance.
     *
     * Returns null if logging is not enabled.
     *
     * @param int $maxEntries Maximum entries to display (0 = unlimited)
     * @return LogViewer|null
     */
    public function getLogViewer($maxEntries = 500)
    {
        if (!$this->config->isLoggingEnabled()) {
            return null;
        }

        return new LogViewer($this->config->getLogPath(), $maxEntries);
    }

    /**
     * Display the log viewer page.
     *
     * Outputs HTML directly to the browser. Returns false if logging is not enabled.
     * Level filter is read from ?level= query parameter (default: debug).
     *
     * @return bool Whether the log viewer was displayed
     */
    public function displayLogs()
    {
        $viewer = $this->getLogViewer();

        if ($viewer === null) {
            return false;
        }

        $viewer->display();
        return true;
    }

    /**
     * Get the config instance.
     *
     * @return Config
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * Get the translatable attributes used for HTML content block parsing.
     *
     * @return array
     */
    public function getTranslatableAttributes()
    {
        return $this->translatableItems->getTranslatableAttributes();
    }

    /**
     * Set the translatable attributes for HTML content block parsing.
     *
     * This replaces all default attributes. Use addTranslatableAttributes()
     * to add to the defaults instead.
     *
     * @param array $attributes Array of attribute names (e.g., ['placeholder', 'alt', 'data-custom'])
     * @return $this
     */
    public function setTranslatableAttributes(array $attributes)
    {
        $this->translatableItems->setTranslatableAttributes($attributes);
        return $this;
    }

    /**
     * Add additional translatable attributes to the default list.
     *
     * @param array $attributes Array of attribute names to add
     * @return $this
     */
    public function addTranslatableAttributes(array $attributes)
    {
        $this->translatableItems->addTranslatableAttributes($attributes);
        return $this;
    }

    /**
     * Reset translatable attributes to the default list.
     *
     * @return $this
     */
    public function resetTranslatableAttributes()
    {
        $this->translatableItems->resetTranslatableAttributes();
        return $this;
    }

    /**
     * Set the target locale for translations.
     *
     * @param string $locale Locale code (e.g., 'es-es', 'fr-ca')
     * @return $this
     */
    public function setLocale($locale)
    {
        $this->locale = LocaleDetector::normalize($locale);
        return $this;
    }

    /**
     * Get the current target locale.
     *
     * The locale set with setLocale(), or else this request's locale as
     * resolveRequestLocale() chooses it, once per request.
     *
     * @return string|null The locale, or null if unable to determine
     */
    public function getLocale()
    {
        if ($this->locale !== null) {
            return $this->locale;
        }

        if ($this->requestLocale === null) {
            $this->requestLocale = $this->resolveRequestLocale();
        }

        return $this->requestLocale['locale'];
    }

    /**
     * Choose this request's locale (SRV-6): the URL, then a cookie or session
     * value, then Accept-Language negotiated against the project's locales,
     * else the base locale. Every candidate is validated against the project's
     * base and target locales, and an unsupported one falls through. Sends the
     * Vary header the choice requires, unless `send_vary` is false - for a
     * binding that puts the returned `vary` on its own response - and never
     * writes a cookie.
     *
     * @param array|null $request framework (a locale the framework or app
     *                            already resolved, which is then the one served),
     *                            path, host, query, cookies, session,
     *                            accept_language; read from PHP's superglobals when null
     * @param array|null $options Overrides the `request_locale` option; takes
     *                            RequestLocale's keys plus `send_vary`
     * @return array{locale: string|null, source: string, vary: string|null}
     */
    public function resolveRequestLocale(?array $request = null, ?array $options = null)
    {
        $this->requireCredentialsAtUse();

        try {
            $project = $this->getProject();
            $base = isset($project['base_locale']) ? $project['base_locale'] : null;
            $served = array_merge($base === null ? [] : [$base], isset($project['target_locales']) && is_array($project['target_locales']) ? $project['target_locales'] : []);
        } catch (\Throwable $e) {
            // Offline, a loaded snapshot answers for authorization: its base
            // and locales are the served set until authorization answers.
            if ($this->snapshot === null) {
                $this->logDegradedRead('Could not read the project\'s locales to choose the request locale', ['error' => $e->getMessage()], $e);

                return ['locale' => null, 'source' => 'none', 'vary' => null];
            }

            $base = $this->snapshot->baseLocale();
            $served = array_merge([$base], $this->snapshot->locales());
            $project = [];
        }

        $options = $options !== null ? $options : $this->requestLocaleOptions;

        $defaultLocales = (isset($project['default_locales']) && is_array($project['default_locales'])) ? $project['default_locales'] : [];

        $result = RequestLocale::resolve(
            $served,
            $base,
            $request !== null ? $request : $this->currentRequest(),
            $options,
            $defaultLocales
        );

        if ($result['vary'] !== null && !(isset($options['send_vary']) && $options['send_vary'] === false)) {
            $this->sendVaryHeader($result['vary']);
        }

        return $result;
    }

    /**
     * The request as RequestLocale reads it, from PHP's superglobals.
     *
     * @return array
     */
    protected function currentRequest()
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';

        return [
            'path' => (string) parse_url($uri, PHP_URL_PATH),
            'host' => isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : null,
            'query' => $_GET,
            'cookies' => $_COOKIE,
            'session' => (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE && isset($_SESSION) && is_array($_SESSION)) ? $_SESSION : [],
            'accept_language' => isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? (string) $_SERVER['HTTP_ACCEPT_LANGUAGE'] : null,
        ];
    }

    /**
     * Add a Vary header, keeping any the app already sent.
     *
     * @param string $value
     * @return void
     */
    protected function sendVaryHeader($value)
    {
        if (!headers_sent()) {
            header('Vary: ' . $value, false);
        }
    }

    /**
     * Translate an entire HTML page.
     *
     * Parses the HTML document, extracts translatable content (head meta tags,
     * text blocks, content blocks), registers new phrases if write permission,
     * and returns the translated HTML with fallback to source content.
     *
     * Optionally, CSS selectors can be mapped to categories for fine-grained control:
     * ```php
     * $client->translatePage($html, 'homepage', [
     *     'button, .btn, .button' => [
     *         'category' => 'UI Elements',
     *         'overrideParentElementCategory' => true,
     *     ],
     *     'nav li' => ['category' => 'Navigation', 'overrideParentElementCategory' => true],
     * ]);
     * ```
     *
     * A selector without `overrideParentElementCategory` applies only where no
     * category reaches the element: none passed here and none on an ancestor.
     * ```php
     * $client->translatePage($html, null, ['.promo' => ['category' => 'Promotions']]);
     * ```
     *
     * @param string $html Full HTML document
     * @param string|null $category Page category/name (e.g., 'homepage', 'contact')
     * @param array $selectorCategories Map of CSS selector => category config
     * @param array $params Placeholder values applied page-wide
     * @return string Translated HTML
     */
    public function translatePage($html, $category = null, array $selectorCategories = [], array $params = [])
    {
        $this->requireCredentialsAtUse();

        $locale = $this->getLocale();
        if ($locale === null) {
            // No locale - return original HTML
            return $html;
        }

        $translator = $this->pageTranslator !== null
            ? $this->pageTranslator
            : new PageTranslator($this, $this->translatableItems->getTranslatableAttributes());

        return $translator->translate($html, $locale, $category, $selectorCategories, $params);
    }

    /**
     * Translate an HTML content block.
     *
     * This method extracts phrases from the HTML, looks up translations for each
     * phrase, applies them, and returns the translated HTML. If the content block
     * doesn't exist in translations, it's queued for registration.
     *
     * A marked host inside the fragment - a phrase marker, or a content-block
     * marker that is not an opt-out - is excised from the fragment's phrases
     * and translated as a unit of its own (MARK-4).
     *
     * @param string $html HTML content block
     * @param string|array $category Category for the content block (default: '__uncategorized__'),
     *                              or `['category' => ..., 'previous_category' => ...]`
     *                              for content that moved (MOVE-1)
     * @param array $params Placeholder values applied to text nodes and translatable attributes
     * @return string Translated HTML
     */
    public function translateContentBlock($html, $category = '__uncategorized__', array $params = [])
    {
        $this->requireCredentialsAtUse();

        list($category, $previousCategory) = $this->splitCategory($category);

        if (empty($html)) {
            return $html;
        }

        // Which hosts are nested is read from the fragment as written: the
        // render stamps a single host with the block's id, and that stamp is
        // this unit's identity, not a nested declaration.
        // Attribute names are case-insensitive in HTML: DATA-LS-PHRASE marks a host too.
        $source = stripos($html, 'data-ls-') === false && stripos($html, 'data-langsys-') === false ? null : $this->fragmentDocument($html);
        $sourceRoot = \Langsys\SDK\Html\Document::fragmentRoot($source);

        $rendered = $this->translateBlockUnit($html, $category, $params, false, $resolvedId, $previousCategory);

        if ($sourceRoot === null || $this->outermostMarkedHosts($sourceRoot) === []) {
            return $rendered;
        }

        $elements = array_values(array_filter(iterator_to_array($sourceRoot->childNodes), function ($node) {
            return $node instanceof \DOMElement;
        }));
        $singleUnmarkedHost = count($elements) === 1 && !HtmlParser::isMarkedHost($elements[0]);

        return $this->renderNestedHostsIn($rendered, $category, $params, $singleUnmarkedHost, $previousCategory);
    }

    /**
     * Translate one fragment as one unit, leaving any marked host inside it
     * untouched.
     *
     * @param string $html
     * @param string|null $category
     * @param array $params
     * @param bool $declared Whether a content-block marker declared this unit a
     *                       block; a declaration outranks the TOK-6 shape
     * @param string|null $resolvedId Set to the id the unit rendered under
     * @param string|null $previousCategory The category the unit moved from (MOVE-1)
     * @param string|null $previousId Set to the previous category's id the
     *                                block was filled from, if it was (MOVE-5)
     * @return string
     */
    protected function translateBlockUnit($html, $category, array $params, $declared, &$resolvedId = null, $previousCategory = null, &$previousId = null)
    {
        $resolvedId = null;
        $previousId = null;

        $locale = $this->getLocale();
        if ($locale === null) {
            // Can't translate without locale, but placeholders still resolve.
            return empty($params)
                ? $html
                : $this->applyBlockTranslations($html, [], new HtmlParser($this->translatableItems->getTranslatableAttributes()), $params, null);
        }

        $category = $this->normalizeCategory($category);

        // Parse HTML and extract phrases
        $parser = new HtmlParser($this->translatableItems->getTranslatableAttributes());
        $unit = $parser->fragmentUnit($html);
        $phrases = $unit['tokens'];

        if (empty($phrases)) {
            return $html; // No translatable content
        }

        // A unit made only of value markers has no text of its own, and one
        // holding a value the reader cannot name must not register it: either
        // way nothing is looked up or registered (VAR-3, VAR-7).
        $markers = $unit['markers'];
        if ($markers !== null && $markers->unnamedWithin()) {
            if (\Langsys\SDK\Html\ValueMarkers::firstNotice()) {
                $this->logger->debug('A value marker names its value outside [a-z][a-z0-9_]*, so text holding it is not registered');
            }

            return $html;
        }
        if ($markers !== null && $markers->markerOnly($phrases)) {
            return $html;
        }

        // TOK-6: a fragment whose one token is its one text node is a phrase,
        // looked up, registered and rendered as one, and written back into
        // that text node in place. A value read from a marker renders as the
        // param of its name, under any the caller sets.
        if (!$declared && HtmlParser::isPhraseUnit($unit)) {
            $runParams = $markers === null ? [] : $markers->sentinels($unit['root']);
            $rendered = $this->translateSource($phrases[0], $locale, $category, null, array_merge($runParams, $params), false, $from, null, null, $previousCategory);

            return $this->replaceFragmentText($html, $phrases[0], $rendered, $this->filledFromPrevious ? $previousCategory : null);
        }

        // Placeholders inside a content block are part of the phrase text, so the
        // raw HTML is what gets registered; params only affect what we render.

        // Generate customId for this content block
        $customId = $parser->generateCustomId($category, $phrases);

        // A seeded snapshot holding this block renders it with no fetch and no
        // registration decision (SNAP-2).
        $seed = $this->seedCatalog($locale);
        if ($seed !== null && isset($seed[$category][$customId]) && is_array($seed[$category][$customId])) {
            $resolvedId = $customId;

            return $this->applyBlockTranslations($html, $seed[$category][$customId], $parser, $params, $locale, $customId);
        }

        // Get translations. As in translate(), an unreachable API degrades to
        // the source HTML rather than throwing into the caller's render.
        try {
            $translations = $this->getTranslations($locale);
        } catch (\Throwable $e) {
            // \Throwable for the same reason as translate() - see the note there.
            $this->logDegradedRead('Content block lookup failed - returning source HTML', [
                'custom_id' => $customId,
                'category' => $category,
                'locale' => $locale,
                'error' => $e->getMessage(),
            ], $e);

            // Deliberately NOT stamped: the lookup failed, so we do not know
            // that this id is the one the catalog holds. Stamping a guess would
            // publish an identity claim built on an outage.
            return empty($params)
                ? $html
                : $this->applyBlockTranslations($html, [], $parser, $params, $locale);
        }

        $categoryTranslations = isset($translations[$category]) ? $translations[$category] : [];

        // Current id first, then the shapes this SDK produced before the
        // JSON-form change - a block registered by an older SDK is still in the
        // catalog, filed under the old key.
        $blockTranslations = $this->resolveContentBlockTranslations(
            $categoryTranslations, $category, $phrases, $customId, $parser
        );

        // With no translation under the current category - none of its ids,
        // or an entry still empty - the previous category's is read last
        // (MOVE-4), and the host says it was filled from it (MOVE-5).
        $previous = $previousCategory !== null && !self::holdsTranslation($blockTranslations)
            ? $this->previousContentBlockTranslations($translations, $previousCategory, $phrases, $parser)
            : null;

        if ($blockTranslations === null) {
            // Content block doesn't exist - queue for registration
            $this->queueContentBlockForRegistration($html, $category, $customId, $phrases, $previousCategory);

            // No translations yet, but the block still HAS an identity, and
            // stamping it is most valuable here: this is the block a later
            // reader would otherwise re-derive and re-register. Routed through
            // applyBlockTranslations even with no params, which the early
            // return used to skip.
            $resolvedId = $customId;

            if ($previous !== null) {
                $previousId = $previous[0];

                return $this->applyBlockTranslations($html, $previous[1], $parser, $params, $locale, $customId, $previousId);
            }

            return $this->applyBlockTranslations($html, [], $parser, $params, $locale, $customId);
        }

        $this->notePreviousCategory(null, $category, $customId, $previousCategory);

        if ($previous !== null) {
            $resolvedId = $customId;
            $previousId = $previous[0];

            return $this->applyBlockTranslations($html, $previous[1], $parser, $params, $locale, $customId, $previousId);
        }

        // Apply translations to HTML. A legacy-resolved block reaches here
        // WITHOUT having been queued: queuing is exactly what would create a
        // second block under the new id and strand these translations.
        $resolvedId = $customId;

        return $this->applyBlockTranslations($html, $blockTranslations, $parser, $params, $locale, $customId);
    }

    /**
     * Resolve a content block's translations: current id first, then legacy.
     *
     * The single resolution path for content blocks. PageTranslator and
     * translateContentBlock BOTH route through this - duplicating the rules in
     * two places is what let the page path miss the legacy fallback, and the
     * same duplication previously let it drift on bookkeeping and on
     * presence-vs-structure checks.
     *
     * @param array $categoryTranslations Catalog slice for this category
     * @param string $category
     * @param array $phrases Phrases extracted from this block, in order
     * @param string $customId Current-form id
     * @param HtmlParser $parser
     * @return array|null Translation map, or null when the block is genuinely new
     */
    public function resolveContentBlockTranslations(array $categoryTranslations, $category, array $phrases, $customId, HtmlParser $parser)
    {
        if (array_key_exists($customId, $categoryTranslations) && is_array($categoryTranslations[$customId])) {
            return $categoryTranslations[$customId];
        }

        return $this->resolveLegacyContentBlock($categoryTranslations, $category, $phrases, $parser);
    }

    /**
     * Find a content block under a pre-JSON-form (legacy) id.
     *
     * Lookup only: legacy ids are never registered, never emitted and never
     * written back. A hit means the block predates the id change and its
     * translations are filed under the old key.
     *
     * The phrase set is verified before the block is accepted. All three known
     * ways two ids can coincide - the old form's unescaped '|' delimiter, the
     * JS SDK's truncating hash, and a joined string that happens to spell a
     * JSON document - are collisions over DIFFERENT content, so none survives
     * comparing the content itself. That check is the guard, and it must fail
     * toward "no match": attaching the wrong translations is visible to a
     * reader, whereas silently serving nothing looks exactly like a block that
     * was never registered.
     *
     * @param array $categoryTranslations Catalog slice for this category
     * @param string $category
     * @param array $phrases Phrases extracted from this block, in order
     * @param HtmlParser $parser
     * @param string|null $foundId Set to the legacy id the block was found under
     * @return array|null Translation map, or null when there is no legacy block
     */
    protected function resolveLegacyContentBlock(array $categoryTranslations, $category, array $phrases, HtmlParser $parser, &$foundId = null)
    {
        foreach ($parser->legacyCustomIds($category, $phrases) as $legacyId) {
            if (!array_key_exists($legacyId, $categoryTranslations)) {
                continue;
            }

            $candidate = $categoryTranslations[$legacyId];

            if (!is_array($candidate) || empty($candidate)) {
                continue;
            }

            // Guard: the block we found must be THIS block. Compare the content
            // the id was supposed to encode, not the id.
            if (!$this->legacyBlockMatchesPhrases($candidate, $phrases)) {
                $this->logger->warning('Legacy content block id resolved to different content - ignoring', [
                    'legacy_custom_id' => $legacyId,
                    'category' => $category,
                ]);
                continue;
            }

            $this->logger->info('Content block resolved under a legacy id', [
                'legacy_custom_id' => $legacyId,
                'category' => $category,
            ]);

            $foundId = $legacyId;

            return $candidate;
        }

        return null;
    }

    /**
     * Whether a candidate legacy block carries exactly this block's phrases.
     *
     * Compares the phrase set rather than order: the catalog returns a block as
     * a phrase-keyed map, so ordering is not recoverable from it. Every known
     * collision differs in the phrases themselves, so set equality is enough to
     * separate them.
     *
     * @param array $candidate Catalog block, keyed by source phrase
     * @param array $phrases
     * @return bool
     */
    protected function legacyBlockMatchesPhrases(array $candidate, array $phrases)
    {
        // array_keys() int-casts numeric-string keys, so an all-numeric phrase
        // set would never compare equal to the string phrases extracted from the
        // markup. Cast both sides back to strings before comparing.
        $found = array_map('strval', array_keys($candidate));
        $expected = array_map('strval', array_values(array_unique($phrases)));

        sort($found);
        sort($expected);

        return $found === $expected;
    }

    /**
     * Apply translations to an HTML content block.
     *
     * @param string $html Original HTML
     * @param array $translations Map of [phrase => translation]
     * @param HtmlParser $parser HTML parser instance
     * @param array $params Placeholder values
     * @param string|null $locale Locale for placeholder formatting
     * @return string Translated HTML
     */
    /**
     * Stamp the resolved id onto a rendered block's host element (MARK-1).
     *
     * The point is that the identity survives the round trip. Once the served
     * HTML carries its own id, a later reader - this SDK, a JS core hydrating
     * over it, anything - reads the id instead of re-deriving it from the text,
     * and a block whose text was edited or canonicalised differently is still
     * recognised as the same block rather than registered afresh.
     *
     * Stamped ONLY when the fragment has exactly one element root. A multi-root
     * fragment has no single host to carry the identity, and picking the first
     * child would claim the whole block's id for one of its siblings - which
     * then reads as that sibling's identity the next time anything parses it.
     * Silence is the honest outcome there.
     *
     * Never overwrites an existing marker: if the block already carries one,
     * that value is another writer's identity claim and outranks ours.
     *
     * A block filled from its previous category also carries that
     * category's id, and a phrase filled from it that category (MOVE-5).
     *
     * @param \DOMElement $wrapper
     * @param string|null $customId
     * @param string|null $previousId
     * @param string|null $previousCategory
     * @return void
     */
    protected function stampContentBlockId($wrapper, $customId, $previousId = null, $previousCategory = null)
    {
        if (($customId === null || $customId === '') && $previousCategory === null) {
            return;
        }

        // Whitespace-only text nodes at the edges do not count as siblings.
        // A template that emits "<strong>Buy now</strong>\n" produces a
        // trailing text node, and counting it made the fragment look
        // multi-node - so the ordinary template-emitted shape silently stopped
        // being stamped. Real text beside the element still blocks stamping;
        // that is the case this guard exists for.
        $elements = [];
        $significant = 0;
        foreach ($wrapper->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $elements[] = $child;
                $significant++;
                continue;
            }

            if ($child instanceof \DOMText && \Langsys\SDK\Html\Whitespace::collapse($child->textContent) === '') {
                continue;
            }

            $significant++;
        }

        // The fragment must be a single node ENTIRE, not merely a single
        // element among text siblings. Counting elements alone stamped
        // `Buy <strong>now</strong>` on the <strong>, giving it the id of the
        // WHOLE fragment (["Buy","now"]) while that element's own subtree
        // derives ["now"] - a false identity claim in the served bytes, which
        // any later reader then believes.
        if (count($elements) !== 1 || $significant !== 1) {
            $this->logger->debug('Not stamping a content block id: the fragment has no single host element', [
                'custom_id' => $customId,
                'element_roots' => count($elements),
                'significant_nodes' => $significant,
            ]);

            return;
        }

        HtmlParser::stampIdentity($elements[0], $customId);
        HtmlParser::stampPrevious($elements[0], $previousId, $previousCategory);
    }

    /**
     * Render every marked host in a rendered fragment as its own unit (MARK-4).
     *
     * @param string $html
     * @param string|null $category
     * @param array $params
     * @param bool $belowSingleHost Whether to start below the fragment's one
     *                              element, which the render may have stamped
     * @param string|null $previousCategory
     * @return string
     */
    protected function renderNestedHostsIn($html, $category, array $params, $belowSingleHost, $previousCategory = null)
    {
        $doc = $this->fragmentDocument($html);
        $wrapper = \Langsys\SDK\Html\Document::fragmentRoot($doc);

        if ($wrapper === null) {
            return $html;
        }

        $root = $wrapper;
        if ($belowSingleHost) {
            foreach ($wrapper->childNodes as $child) {
                if ($child instanceof \DOMElement) {
                    $root = $child;
                    break;
                }
            }
        }

        $this->renderNestedHosts($root, $category, $params, $previousCategory);

        $result = '';
        foreach ($wrapper->childNodes as $child) {
            $result .= \Langsys\SDK\Html\Document::html($child);
        }

        return $result;
    }

    /**
     * Render each outermost marked host below $root: a content-block host as a
     * declared block of its content, a phrase host as one tokenized phrase.
     * A host's own nested hosts are rendered first, so a phrase host keeps
     * them translated when its markup is rebuilt.
     *
     * @param \DOMElement $root
     * @param string|null $category
     * @param array $params
     * @param string|null $previousCategory Inherited with the category: a host
     *                                      naming its own category names its
     *                                      own previous one, or none (MOVE-1)
     * @return void
     */
    protected function renderNestedHosts(\DOMElement $root, $category, array $params, $previousCategory = null)
    {
        foreach ($this->outermostMarkedHosts($root) as $host) {
            $hostCategory = $host->hasAttribute('data-langsys-category') ? $host->getAttribute('data-langsys-category') : $category;
            $hostPrevious = $host->hasAttribute('data-langsys-category') ? null : $previousCategory;
            if (($named = HtmlParser::previousCategoryOf($host)) !== null) {
                $hostPrevious = $named;
            }
            $hostPrevious = TranslatableItems::previousCategoryFor($hostPrevious, ($hostCategory === null || $hostCategory === '' || $hostCategory === self::UNCATEGORIZED) ? null : $hostCategory);

            if (HtmlParser::isPhraseMarked($host)) {
                $this->renderNestedHosts($host, $hostCategory, $params, $hostPrevious);
                $this->renderTokenizedHost($host, $hostCategory, $params, $hostPrevious);
                continue;
            }

            $inner = '';
            foreach ($host->childNodes as $child) {
                $inner .= \Langsys\SDK\Html\Document::html($child);
            }

            $marker = HtmlParser::contentBlockMarker($host);

            if ($marker !== null && $marker['kind'] === 'identity') {
                // MARK-3: a stamped id is this host's custom_id. It renders from
                // the catalog entry under that id. Inside a resolved scope a
                // server rendered it from the catalog, so it registers nothing;
                // outside one it registers its content under that id when the
                // catalog lacks it.
                $this->replaceChildrenWithHtml($host, $this->renderStampedBlock($inner, $marker['id'], $hostCategory, $params, !HtmlParser::isResolvedScope($host), HtmlParser::previousContentBlockOf($host), $hostPrevious));
                $this->renderNestedHosts($host, $hostCategory, $params, $hostPrevious);
                continue;
            }

            $rendered = $this->translateBlockUnit($inner, $hostCategory, $params, true, $resolvedId, $hostPrevious, $previousId);
            $this->replaceChildrenWithHtml($host, $rendered);
            HtmlParser::stampIdentity($host, $resolvedId);
            HtmlParser::stampPrevious($host, $previousId);
            $this->renderNestedHosts($host, $hostCategory, $params, $hostPrevious);
        }
    }

    /**
     * Render a stamped block's content from the catalog entry filed under its
     * stamped id; source text when the catalog holds no such entry or cannot
     * be read. A block that may register is queued under its stamped id when
     * the live catalog lacks it.
     *
     * @param string $html
     * @param string $customId
     * @param string|null $category
     * @param array $params
     * @param bool $mayRegister Whether the host is outside a resolved scope
     * @param string|null $previousId The previous custom_id the host was
     *                                stamped with, read while the current id
     *                                has no translation (MOVE-5)
     * @param string|null $previousCategory The previous category its author named
     * @return string
     */
    protected function renderStampedBlock($html, $customId, $category, array $params, $mayRegister, $previousId = null, $previousCategory = null)
    {
        $locale = $this->getLocale();
        $category = $this->normalizeCategory($category);
        $blockTranslations = [];

        $seed = $locale === null ? null : $this->seedCatalog($locale);

        if ($seed !== null && isset($seed[$category][$customId]) && is_array($seed[$category][$customId])) {
            $blockTranslations = $seed[$category][$customId];
        } elseif ($locale !== null) {
            try {
                $translations = $this->getTranslations($locale);
                if (isset($translations[$category][$customId]) && is_array($translations[$category][$customId])) {
                    $blockTranslations = $translations[$category][$customId];
                }

                // The id the host was filled from, while its own has no
                // translation; the category holding it is the one it moved
                // from (MOVE-5).
                $filledFrom = $previousId !== null && !self::holdsTranslation($blockTranslations) ? $this->stampedPreviousBlock($translations, $previousId) : null;
                if ($filledFrom !== null) {
                    $blockTranslations = $filledFrom[1];
                    if ($previousCategory === null) {
                        $previousCategory = $filledFrom[0] === self::UNCATEGORIZED ? '' : $filledFrom[0];
                    }
                }

                if ($mayRegister && !(isset($translations[$category]) && is_array($translations[$category]) && array_key_exists($customId, $translations[$category]))) {
                    $parser = new HtmlParser($this->translatableItems->getTranslatableAttributes());
                    $unit = $parser->fragmentUnit($html);
                    $phrases = $unit['tokens'];
                    if ($phrases !== [] && !($unit['markers'] !== null && ($unit['markers']->markerOnly($phrases) || $unit['markers']->unnamedWithin()))) {
                        $this->queueContentBlockForRegistration($html, $category, $customId, $phrases, $previousCategory);
                    }
                }
            } catch (\Throwable $e) {
                $this->logDegradedRead('Content block lookup failed - returning source HTML', [
                    'custom_id' => $customId,
                    'category' => $category,
                    'error' => $e->getMessage(),
                ], $e);
            }
        }

        if ($blockTranslations === [] && $params === []) {
            return $html;
        }

        return $this->applyBlockTranslations($html, $blockTranslations, new HtmlParser($this->translatableItems->getTranslatableAttributes()), $params, $locale);
    }

    /**
     * Marked hosts below $root that no other marked host below $root contains,
     * skipping subtrees excluded from translation or that are not prose.
     *
     * @param \DOMElement $root
     * @return \DOMElement[]
     */
    protected function outermostMarkedHosts(\DOMElement $root)
    {
        $found = [];

        foreach ($root->childNodes as $child) {
            if (!$child instanceof \DOMElement
                || HtmlParser::isTranslationExcluded($child)
                || in_array(strtolower($child->nodeName), HtmlParser::NON_PROSE_ELEMENTS, true)) {
                continue;
            }

            if (HtmlParser::isMarkedHost($child)) {
                $found[] = $child;
                continue;
            }

            foreach ($this->outermostMarkedHosts($child) as $host) {
                $found[] = $host;
            }
        }

        return $found;
    }

    /**
     * Translate a phrase-marked host as one tokenized phrase: its markup
     * becomes tokens, the phrase is looked up and registered like any other,
     * and the host's children are rebuilt from the translation. Its own
     * translatable attributes, and those of its unmarked descendants, are
     * phrases of their own.
     *
     * @param \DOMElement $host
     * @param string|null $category
     * @param array $params
     * @return void
     */
    protected function renderTokenizedHost(\DOMElement $host, $category, array $params, $previousCategory = null)
    {
        $tokenizer = new \Langsys\SDK\Html\MarkupTokenizer();
        $markers = \Langsys\SDK\Html\ValueMarkers::read($host);
        $encoded = $tokenizer->encode($host, $markers);

        if ($tokenizer->hasText($encoded['text']) && !$markers->markerOnly([$encoded['text']]) && !$markers->unnamedWithin()) {
            $slots = $encoded['slots'];
            $rendered = $this->translateSource($encoded['text'], null, $category, null, array_merge($markers->sentinels($host), $params, $tokenizer->tokenParams(count($slots))), false, $from, null, $this->earlierLookup($tokenizer, $encoded['earlier']), $previousCategory);
            if ($this->filledFromPrevious) {
                HtmlParser::stampPrevious($host, null, $previousCategory);
            }

            if ($tokenizer->hasTokens($rendered)) {
                $rendered = (string) preg_replace('/\{m\d+[oc]\}/', '', $rendered);
                $slots = [];
            }

            $nodes = $tokenizer->render($rendered, $slots, $host->ownerDocument, $encoded['opaque']);

            while ($host->firstChild !== null) {
                $host->removeChild($host->firstChild);
            }

            foreach ($nodes as $node) {
                $host->appendChild($node);
            }
        }

        $markers->finish($host);

        $targets = [$host];
        self::attributeTargets($host, $targets);

        foreach ($targets as $target) {
            foreach ($this->translatableItems->getTranslatableAttributes() as $attr) {
                if (!$target->hasAttribute($attr)) {
                    continue;
                }

                $value = \Langsys\SDK\Html\Canonical::phrase($target->getAttribute($attr));
                if ($value !== '') {
                    $target->setAttribute($attr, $this->translateSource($value, null, $category, null, $params, false, $from, null, null, $previousCategory));
                }
            }
        }
    }

    /**
     * The elements below $element whose attributes its host translates, in
     * document order, down to a marked host, which translates its own. An
     * element its author excluded, or one whose text is not prose, is read
     * for nothing, attributes and descendants alike.
     *
     * @param \DOMElement $element
     * @param \DOMElement[] $targets
     * @return void
     */
    private static function attributeTargets(\DOMElement $element, array &$targets)
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMElement && !HtmlParser::isMarkedHost($child) && !HtmlParser::isTranslationExcluded($child)
                && !in_array(strtolower($child->nodeName), HtmlParser::NON_PROSE_ELEMENTS, true)) {
                $targets[] = $child;
                self::attributeTargets($child, $targets);
            }
        }
    }

    /**
     * @param string $html
     * @return \DOMDocument|null
     */
    protected function fragmentDocument($html)
    {
        $internalErrors = libxml_use_internal_errors(true);

        $doc = new \DOMDocument();
        $doc->encoding = 'UTF-8';
        $loaded = \Langsys\SDK\Html\Document::loadFragment($doc, $html);

        libxml_clear_errors();
        libxml_use_internal_errors($internalErrors);

        return $loaded ? $doc : null;
    }

    /**
     * @param \DOMElement $element
     * @param string $html
     * @return void
     */
    protected function replaceChildrenWithHtml(\DOMElement $element, $html)
    {
        $source = $this->fragmentDocument($html);
        $wrapper = \Langsys\SDK\Html\Document::fragmentRoot($source);

        if ($wrapper === null) {
            return;
        }

        while ($element->firstChild !== null) {
            $element->removeChild($element->firstChild);
        }

        foreach ($wrapper->childNodes as $child) {
            $element->appendChild($element->ownerDocument->importNode($child, true));
        }
    }

    /**
     * Whether a node sits inside a marked host below $root.
     *
     * @param \DOMNode $node
     * @param \DOMElement $root
     * @return bool
     */
    protected function insideMarkedHost(\DOMNode $node, \DOMElement $root)
    {
        for ($parent = $node->parentNode; $parent !== null && !$parent->isSameNode($root); $parent = $parent->parentNode) {
            if ($parent instanceof \DOMElement && HtmlParser::isMarkedHost($parent)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a node sits inside an element below $root whose text
     * registration does not read: one excluded from translation, or a
     * script, style, noscript, template or math.
     *
     * @param \DOMNode $node
     * @param \DOMElement $root
     * @return bool
     */
    private static function insideUnreadElement(\DOMNode $node, \DOMElement $root)
    {
        for ($parent = $node->parentNode; $parent !== null && !$parent->isSameNode($root); $parent = $parent->parentNode) {
            if ($parent instanceof \DOMElement && (HtmlParser::isTranslationExcluded($parent) || in_array(strtolower($parent->nodeName), HtmlParser::NON_PROSE_ELEMENTS, true))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Write a rendered phrase into the one text node of a fragment that holds
     * it - the node registration read - keeping the node's surrounding
     * whitespace and every element around it.
     *
     * @param string $html
     * @param string $phrase The canonical text the node holds
     * @param string $rendered
     * @param string|null $previousCategory Stamped on the fragment's one host
     *                                      when the phrase was filled from it (MOVE-5)
     * @return string
     */
    protected function replaceFragmentText($html, $phrase, $rendered, $previousCategory = null)
    {
        $internalErrors = libxml_use_internal_errors(true);

        $doc = new \DOMDocument();
        $doc->encoding = 'UTF-8';
        \Langsys\SDK\Html\Document::loadFragment($doc, $html);

        libxml_clear_errors();
        libxml_use_internal_errors($internalErrors);

        $wrapper = \Langsys\SDK\Html\Document::fragmentRoot($doc);
        if ($wrapper === null) {
            return $html;
        }

        $markers = \Langsys\SDK\Html\ValueMarkers::read($wrapper);

        $xpath = new \DOMXPath($doc);
        foreach ($xpath->query('.//text()', $wrapper) as $node) {
            if (\Langsys\SDK\Html\Canonical::phrase($node->textContent) !== $phrase || $this->insideMarkedHost($node, $wrapper) || self::insideUnreadElement($node, $wrapper)) {
                continue;
            }

            $leading = preg_match('/^' . \Langsys\SDK\Html\Whitespace::JS_WHITESPACE . '/u', $node->textContent) ? ' ' : '';
            $trailing = preg_match('/' . \Langsys\SDK\Html\Whitespace::JS_WHITESPACE . '$/u', $node->textContent) ? ' ' : '';
            $node->textContent = $leading . $rendered . $trailing;
            break;
        }

        $markers->finish($wrapper);

        if ($previousCategory !== null) {
            $this->stampContentBlockId($wrapper, null, null, $previousCategory);
        }

        $result = '';
        foreach ($wrapper->childNodes as $child) {
            $result .= \Langsys\SDK\Html\Document::html($child);
        }

        return $result;
    }

    protected function applyBlockTranslations($html, array $translations, HtmlParser $parser, array $params = [], $locale = null, $customId = null, $previousId = null)
    {
        // Use DOMDocument to properly apply translations
        $internalErrors = libxml_use_internal_errors(true);

        $doc = new \DOMDocument();
        $doc->encoding = 'UTF-8';
        \Langsys\SDK\Html\Document::loadFragment($doc, $html);

        libxml_clear_errors();
        libxml_use_internal_errors($internalErrors);

        // Value markers read as placeholders, so each text node is looked up
        // as it registered and renders with its own values (VAR-3).
        $this->blockMarkers = \Langsys\SDK\Html\ValueMarkers::read($doc->documentElement);

        try {
            // Walk DOM and apply translations
            $this->walkAndTranslateBlock($doc->documentElement, $translations, $parser->getTranslatableAttributes(), $params, $locale);
            $this->blockMarkers->finish($doc->documentElement);
        } finally {
            $this->blockMarkers = null;
        }

        // Extract inner HTML of the wrapper div
        $wrapper = \Langsys\SDK\Html\Document::fragmentRoot($doc);
        if ($wrapper === null) {
            return $html;
        }

        $this->stampContentBlockId($wrapper, $customId, $previousId);

        $result = '';
        foreach ($wrapper->childNodes as $child) {
            $result .= \Langsys\SDK\Html\Document::html($child);
        }

        return $result;
    }

    /**
     * Walk DOM and apply translations to text nodes and attributes.
     *
     * @param \DOMNode $node Node to process
     * @param array $translations Translation map
     * @param array $translatableAttributes Attributes to translate
     * @param array $params Placeholder values
     * @param string|null $locale Locale for placeholder formatting
     * @return void
     */
    protected function walkAndTranslateBlock(\DOMNode $node, array $translations, array $translatableAttributes, array $params = [], $locale = null)
    {
        // Handle text nodes
        if ($node instanceof \DOMText) {
            // Must normalise IDENTICALLY to HtmlParser, which registered these
            // phrases: this is the lookup side, so any divergence is a
            // permanent miss that re-registers on every render.
            $normalizedText = \Langsys\SDK\Html\Canonical::phrase($node->textContent);
            if ($normalizedText !== '') {
                $translated = isset($translations[$normalizedText]) ? $translations[$normalizedText] : null;

                // Fall back to the original text so placeholders still resolve
                // in blocks that have no translation yet.
                if ($translated === null || $translated === '') {
                    $translated = $normalizedText;
                }

                $nodeParams = $this->blockMarkers !== null && $this->blockMarkers->any()
                    ? $this->blockMarkers->params($node, $translated, $params)
                    : $params;
                $translated = $this->interpolate($translated, $nodeParams, $locale);

                if ($translated !== $normalizedText) {
                    // Preserve whitespace pattern
                    $leadingSpace = preg_match('/^' . \Langsys\SDK\Html\Whitespace::JS_WHITESPACE . '/u', $node->textContent) ? ' ' : '';
                    $trailingSpace = preg_match('/' . \Langsys\SDK\Html\Whitespace::JS_WHITESPACE . '$/u', $node->textContent) ? ' ' : '';
                    $node->textContent = $leadingSpace . $translated . $trailingSpace;
                }
            }
            return;
        }

        // Handle element nodes
        if ($node instanceof \DOMElement) {
            // Skip elements excluded from translation entirely, and those
            // registration reads no prose in: script, style, noscript,
            // template and math.
            if (HtmlParser::isTranslationExcluded($node) || in_array(strtolower($node->nodeName), HtmlParser::NON_PROSE_ELEMENTS, true)) {
                return;
            }

            // Translate attributes
            foreach ($translatableAttributes as $attr) {
                if ($node->hasAttribute($attr)) {
                    $this->translateAttributeValue($node, $attr, $translations, $params, $locale);
                }
            }

            // Handle button/input values
            $tagName = strtolower($node->tagName);
            if ($tagName === 'button' && $node->hasAttribute('value')) {
                $this->translateAttributeValue($node, 'value', $translations, $params, $locale);
            }
            if ($tagName === 'input' && $node->hasAttribute('value')) {
                $type = strtolower($node->getAttribute('type'));
                if ($type === 'submit' || $type === 'button') {
                    $this->translateAttributeValue($node, 'value', $translations, $params, $locale);
                }
            }
        }

        // Recurse into children. A marked host is its own unit (MARK-4) and is
        // rendered on its own, never with this block's translations.
        if ($node->hasChildNodes()) {
            foreach ($node->childNodes as $child) {
                if ($child instanceof \DOMElement && HtmlParser::isMarkedHost($child)) {
                    continue;
                }

                $this->walkAndTranslateBlock($child, $translations, $translatableAttributes, $params, $locale);
            }
        }
    }

    /**
     * Translate a single attribute value, then interpolate placeholders.
     *
     * Falls back to the original attribute value when no translation exists so
     * placeholders still resolve in not-yet-translated blocks.
     *
     * @param \DOMElement $node
     * @param string $attr Attribute name
     * @param array $translations Translation map
     * @param array $params Placeholder values
     * @param string|null $locale
     * @return void
     */
    protected function translateAttributeValue(\DOMElement $node, $attr, array $translations, array $params, $locale)
    {
        // Canonicalised, because this is the LOOKUP side of the attribute pair.
        // HtmlParser::extractAttributePhrases() collapses before registering,
        // so an alt wrapped across source lines is filed under the collapsed
        // form; looking up the raw value could never find it.
        $value = \Langsys\SDK\Html\Canonical::phrase($node->getAttribute($attr));

        if ($value === '') {
            return;
        }

        $translated = isset($translations[$value]) ? $translations[$value] : null;

        if ($translated === null || $translated === '') {
            $translated = $value;
        }

        $translated = $this->interpolate($translated, $params, $locale);

        if ($translated !== $value) {
            $node->setAttribute($attr, $translated);
        }
    }

    /**
     * Queue a phrase for registration.
     *
     * Also adds to in-memory cache so subsequent translate() calls for the
     * same phrase don't re-queue it.
     *
     * @param string $phrase The phrase to register
     * @param string $category Category for the phrase
     * @return void
     */
    /**
     * Queue a phrase for registration at end of request.
     *
     * Public because PageTranslator routes page registration through the same
     * queue: the two entry points used to differ, with translate() deferring and
     * translatePage() issuing blocking HTTP calls mid-render.
     *
     * @param string $phrase
     * @param string|null $category
     * @param string|null $previousCategory The category it moved from (MOVE-1);
     *                                      never part of the queue's key (MOVE-2)
     * @return void
     */
    public function queuePhraseForRegistration($phrase, $category, $previousCategory = null)
    {
        // Nothing could ever send it (WIRE-4).
        if (!$this->isConfigured()) {
            return;
        }

        $phrase = Canonical::codePhrase($phrase);
        $key = $category . '::' . $phrase;
        $previousCategory = TranslatableItems::previousCategoryFor($previousCategory, ($category === null || $category === '' || $category === self::UNCATEGORIZED) ? null : $category);

        // Skip if already queued. The first previous category named stays,
        // and a later call naming none never clears it (MOVE-3).
        if (isset($this->pendingPhrases[$key])) {
            if ($previousCategory !== null && !isset($this->pendingPhrases[$key]['previous_category'])) {
                $this->pendingPhrases[$key]['previous_category'] = $previousCategory;
            }

            return;
        }

        if ($this->isTruncationOfAKnownPhrase($phrase, $category)) {
            return;
        }

        $this->pendingPhrases[$key] = [
            'phrase' => $phrase,
            'category' => $category,
        ] + ($previousCategory === null ? [] : ['previous_category' => $previousCategory]);

        $this->logger->debug('Phrase queued for registration', [
            'phrase' => $phrase,
            'category' => $category,
        ] + ($previousCategory === null ? [] : ['previous_category' => $previousCategory]));

        // Add to in-memory cache (empty string = no translation yet)
        // This prevents re-queueing the same phrase within the same request
        $locale = $this->getLocale();
        if ($locale !== null) {
            if (!isset($this->translationsMemoryCache[$locale])) {
                $this->translationsMemoryCache[$locale] = [];
            }
            if (!isset($this->translationsMemoryCache[$locale][$category])) {
                $this->translationsMemoryCache[$locale][$category] = [];
            }
            // Only add if not already present (don't overwrite existing translations)
            if (!array_key_exists($phrase, $this->translationsMemoryCache[$locale][$category])) {
                $this->translationsMemoryCache[$locale][$category][$phrase] = '';
            }
        }

        // Register shutdown handler on first queue
        $this->registerShutdownHandler();
    }

    /**
     * REG-11: a phrase ending in an ellipsis may be an upstream truncation of a
     * longer one. It is always reported at debug, and suppressed only on the
     * second signal - a longer phrase already known in its category that starts
     * with the same text - since "Loading..." alone is legitimate copy.
     *
     * Known means the catalog this request read plus what it has queued, which
     * is where the full form of a truncated teaser appears first.
     *
     * @param string $phrase
     * @param string $category
     * @return bool Whether to skip registering it
     */
    protected function isTruncationOfAKnownPhrase($phrase, $category)
    {
        if (!preg_match('/^(.*?)\s*(?:\x{2026}|\.\.\.)$/us', $phrase, $m)) {
            return false;
        }

        $prefix = $m[1];
        $longer = null;

        if ($prefix !== '') {
            foreach ($this->translationsMemoryCache as $catalog) {
                foreach (isset($catalog[$category]) && is_array($catalog[$category]) ? $catalog[$category] : [] as $known => $unused) {
                    $known = (string) $known;
                    if ($known !== $phrase && strlen($known) > strlen($prefix) && strpos($known, $prefix) === 0) {
                        $longer = $known;
                        break 2;
                    }
                }
            }
        }

        $this->logger->debug($longer === null
            ? 'A phrase ends in an ellipsis; if the text was truncated before reaching the SDK, register the full text instead'
            : 'A phrase ending in an ellipsis was not registered: a longer phrase starting with the same text is already known, so this looks like a truncation of it', [
            'phrase' => $phrase,
            'category' => $category,
            'longer' => $longer,
        ]);

        return $longer !== null;
    }

    /**
     * Queue a content block for registration.
     *
     * Also adds to in-memory cache so subsequent translateContentBlock() calls for the
     * same content don't re-queue it.
     *
     * @param string $html HTML content
     * @param string $category Category
     * @param string $customId Custom ID for the block
     * @param array $phrases Extracted phrases
     * @return void
     */
    /**
     * Queue a content block for registration at end of request.
     *
     * @param string $html
     * @param string|null $category
     * @param string $customId
     * @param array $phrases
     * @param string|null $previousCategory The category it moved from (MOVE-1);
     *                                      never part of its id (MOVE-2)
     * @return void
     */
    public function queueContentBlockForRegistration($html, $category, $customId, array $phrases, $previousCategory = null)
    {
        // Nothing could ever send it (WIRE-4).
        if (!$this->isConfigured()) {
            return;
        }

        $previousCategory = TranslatableItems::previousCategoryFor($previousCategory, ($category === null || $category === '' || $category === self::UNCATEGORIZED) ? null : $category);

        // Skip if already queued, keeping the first previous category named
        // (MOVE-3).
        if (isset($this->pendingContentBlocks[$customId])) {
            if ($previousCategory !== null && !isset($this->pendingContentBlocks[$customId]['previous_category'])) {
                $this->pendingContentBlocks[$customId]['previous_category'] = $previousCategory;
            }

            return;
        }

        // Registered with each value marker read as its placeholder (VAR-3):
        // a user's value is never part of the source sent for translation.
        $html = \Langsys\SDK\Html\ValueMarkers::placeholderHtml($html);

        // Resolve relative URLs before queuing
        $html = $this->resolveContentBlockUrls($html);

        $this->pendingContentBlocks[$customId] = [
            'html' => $html,
            'category' => $category,
            'customId' => $customId,
            'phrases' => $phrases,
        ] + ($previousCategory === null ? [] : ['previous_category' => $previousCategory]);

        $this->logger->debug('Content block queued for registration', [
            'custom_id' => $customId,
            'category' => $category,
            'phrase_count' => count($phrases),
        ] + ($previousCategory === null ? [] : ['previous_category' => $previousCategory]));

        // Add to in-memory cache (empty strings = no translations yet)
        // This prevents re-queueing the same content block within the same request
        $locale = $this->getLocale();
        if ($locale !== null) {
            if (!isset($this->translationsMemoryCache[$locale])) {
                $this->translationsMemoryCache[$locale] = [];
            }
            if (!isset($this->translationsMemoryCache[$locale][$category])) {
                $this->translationsMemoryCache[$locale][$category] = [];
            }
            // Only add if not already present (don't overwrite existing translations)
            if (!array_key_exists($customId, $this->translationsMemoryCache[$locale][$category])) {
                // Create empty translation map for all phrases
                $emptyTranslations = [];
                foreach ($phrases as $phrase) {
                    $emptyTranslations[$phrase] = '';
                }
                $this->translationsMemoryCache[$locale][$category][$customId] = $emptyTranslations;
            }
        }

        // Register shutdown handler on first queue
        $this->registerShutdownHandler();
    }

    /**
     * Have this Client's queue sent when the process ends, if it is still
     * alive then. One handler serves every Client in the process.
     *
     * @return void
     */
    protected function registerShutdownHandler()
    {
        // An id is reused once its object is freed, so an entry under it may
        // be a Client gone before this one.
        $id = spl_object_id($this);
        if (!isset(self::$queued[$id]) || self::$queued[$id]->get() !== $this) {
            self::$queued[$id] = \WeakReference::create($this);
        }

        if (!self::$shutdownHandlerRegistered) {
            self::$shutdownHandlerRegistered = true;
            register_shutdown_function(static function () {
                self::flushAtShutdown();
            });
        }
    }

    /**
     * Send the queue of every Client still alive.
     *
     * @return void
     */
    private static function flushAtShutdown()
    {
        foreach (self::$queued as $id => $reference) {
            unset(self::$queued[$id]);
            $client = $reference->get();
            if ($client !== null) {
                $client->flushPendingRegistrations();
            }
        }
    }

    /**
     * A Client let go with registrations queued sends them now: nothing else
     * will. A best-effort send, so nothing it raises escapes the free.
     */
    public function __destruct()
    {
        unset(self::$queued[spl_object_id($this)]);

        if (empty($this->pendingPhrases) && empty($this->pendingContentBlocks)) {
            return;
        }

        try {
            $this->flushPendingRegistrations();
        } catch (\Throwable $e) {
            $this->logger->error('Flush failed as the client was freed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Flush all pending phrase and content block registrations to the API.
     *
     * This is called automatically at the end of the request, but you can
     * call it manually if needed.
     *
     * 'success' means every queued item was accepted by the API. It is false
     * whenever work was discarded or failed - a skipped write is not a
     * successful one, and a caller checking this must not be told otherwise.
     *
     * 'skipped' counts items never sent, and splits into 'dropped' (discarded;
     * nothing will retry them) and 'retained' (still queued; a later flush can
     * send them). The two need opposite responses from a caller.
     *
     * 'reason' names why a flush did not succeed, and is null when it did:
     * 'not_write_enabled' (this request may not write), 'catalog_unavailable'
     * (the catalog could not be read, so no miss could be decided and nothing
     * was queued), 'backing_off' (waiting after a failed send),
     * 'decision_unavailable' (whether this request may write could not be
     * read) or 'send_failed' (the API refused or never received the batch).
     *
     * @return array ['phrases' => count, 'content_blocks' => count, 'skipped' => count, 'dropped' => count, 'retained' => count, 'success' => bool, 'reason' => string|null]
     */
    public function flushPendingRegistrations()
    {
        $result = [
            'phrases' => 0,
            'content_blocks' => 0,
            'skipped' => 0,
            'dropped' => 0,
            'retained' => 0,
            'success' => true,
            'reason' => null,
        ];

        // Nothing to register. When that is because the catalog could not be
        // read, no miss was decided: the write was skipped, and says so.
        if (empty($this->pendingPhrases) && empty($this->pendingContentBlocks)) {
            // The locale this request already resolved; never resolved here,
            // at the end of the request.
            $locale = $this->locale !== null ? $this->locale : ($this->requestLocale !== null ? $this->requestLocale['locale'] : null);
            if ($locale !== null && isset($this->unavailableCatalogs[$locale])) {
                $result['success'] = false;
                $result['reason'] = 'catalog_unavailable';
            }

            return $result;
        }

        // REG-8: after a failed send, wait before asking the endpoint again.
        // The queue stays for a flush after the wait.
        if ($this->currentTime() < $this->nextSendAt) {
            $pendingCount = count($this->pendingPhrases) + count($this->pendingContentBlocks);
            $this->logger->debug('Flush deferred - backing off after a failed send', [
                'pending' => $pendingCount,
                'retry_in_seconds' => round($this->nextSendAt - $this->currentTime(), 1),
            ]);
            $result['skipped'] = $pendingCount;
            $result['retained'] = $pendingCount;
            $result['success'] = false;
            $result['reason'] = 'backing_off';
            return $result;
        }

        // Skip if we can't write
        try {
            if (!$this->canWrite()) {
                $pendingCount = count($this->pendingPhrases) + count($this->pendingContentBlocks);
                // Debug, not warning: a key that may not write never sends, by
                // design, and would otherwise log on every request with misses.
                // A write-type key refused by the server is reported once
                // (OBS-1).
                $this->logger->debug('Flush skipped - this request may not write', [
                    'pending_phrases' => count($this->pendingPhrases),
                    'pending_content_blocks' => count($this->pendingContentBlocks),
                ]);
                // Nothing can send these, so drop them - but report it, rather
                // than returning a success-shaped result for discarded work.
                $this->pendingPhrases = [];
                $this->pendingContentBlocks = [];
                $result['skipped'] = $pendingCount;
                $result['dropped'] = $pendingCount;
                $result['success'] = false;
                $result['reason'] = 'not_write_enabled';
                return $result;
            }
        } catch (\Throwable $e) {
            $pendingCount = count($this->pendingPhrases) + count($this->pendingContentBlocks);
            $this->logger->error('Flush failed - authorization error', [
                'error' => $e->getMessage(),
                'pending' => $pendingCount,
            ]);
            // Left queued deliberately: a manual flush can still retry these.
            $result['skipped'] = $pendingCount;
            $result['retained'] = $pendingCount;
            $result['success'] = false;
            $result['reason'] = 'decision_unavailable';
            $this->noteSendFailure();
            return $result;
        }

        // Register phrases in a single batch
        if (!empty($this->pendingPhrases)) {
            try {
                $phrases = array_values($this->pendingPhrases);
                $this->translatableItems->createPhrases($phrases);
                $result['phrases'] = count($phrases);
                $this->pendingPhrases = [];
            // \Throwable, not \Exception: this usually runs from the shutdown
            // handler, after the response is sent, where an \Error escaping is
            // a fatal - appended to the page on some SAPIs, and invisible on
            // the rest. Registration is best-effort by design, so EVERY way
            // this can fail has to end up in $result rather than unwinding.
            } catch (\Throwable $e) {
                $this->logger->error('Failed to register phrases', [
                    'count' => count($this->pendingPhrases),
                    'error' => $e->getMessage(),
                ]);
                $result['skipped'] += count($this->pendingPhrases);
                $result['retained'] += count($this->pendingPhrases);
                $result['success'] = false;
                $result['reason'] = 'send_failed';
            }
        }

        // Register content blocks in a single batch
        if (!empty($this->pendingContentBlocks)) {
            try {
                $blocks = array_values($this->pendingContentBlocks);
                $this->translatableItems->createContentBlocks($blocks);
                $result['content_blocks'] = count($blocks);
                $this->pendingContentBlocks = [];
            } catch (\Throwable $e) {
                $this->logger->error('Failed to register content blocks', [
                    'count' => count($this->pendingContentBlocks),
                    'error' => $e->getMessage(),
                ]);
                $result['skipped'] += count($this->pendingContentBlocks);
                $result['retained'] += count($this->pendingContentBlocks);
                $result['success'] = false;
                $result['reason'] = 'send_failed';
            }
        }

        if ($result['success']) {
            $this->sendFailures = 0;
            $this->nextSendAt = 0.0;
        } else {
            $this->noteSendFailure();
        }

        // Clear translation cache if we registered anything
        if ($result['phrases'] > 0 || $result['content_blocks'] > 0) {
            $this->logger->info('Pending registrations flushed', [
                'phrases' => $result['phrases'],
                'content_blocks' => $result['content_blocks'],
                'success' => $result['success'],
            ]);
            try {
                $locale = $this->getLocale();
                if ($locale !== null) {
                    $this->clearCache($locale);
                }
            } catch (\Throwable $e) {
                // Ignore cache clear errors
            }
        }

        return $result;
    }

    /**
     * Record a failed send and set when the next may be tried: 3s, doubling,
     * up to five minutes.
     *
     * @return void
     */
    protected function noteSendFailure()
    {
        $this->sendFailures++;
        $this->nextSendAt = $this->currentTime() + $this->backoffDelay($this->sendFailures);
    }

    /**
     * The wait after the Nth consecutive failure: 3s, doubling, to five
     * minutes. Shared by the send side (REG-8) and the catalog read (CACHE-2).
     *
     * @param int $failures
     * @return float Seconds
     */
    protected function backoffDelay($failures)
    {
        return (float) min(self::SEND_BACKOFF_INITIAL * pow(2, $failures - 1), self::SEND_BACKOFF_CEILING);
    }

    /**
     * @return float Seconds since the epoch
     */
    protected function currentTime()
    {
        return microtime(true);
    }

    /**
     * Check if there are pending registrations.
     *
     * @return bool
     */
    public function hasPendingRegistrations()
    {
        return !empty($this->pendingPhrases) || !empty($this->pendingContentBlocks);
    }

    /**
     * Get pending phrases (for debugging/testing).
     *
     * @return array
     */
    public function getPendingPhrases()
    {
        return $this->pendingPhrases;
    }

    /**
     * Get pending content blocks (for debugging/testing).
     *
     * @return array
     */
    public function getPendingContentBlocks()
    {
        return $this->pendingContentBlocks;
    }

    /**
     * Clear pending registrations without sending to API.
     *
     * @return void
     */
    public function clearPendingRegistrations()
    {
        $this->pendingPhrases = [];
        $this->pendingContentBlocks = [];
    }
}
