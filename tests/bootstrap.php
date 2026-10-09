<?php

/**
 * PHPUnit bootstrap file for Langsys SDK tests.
 */

// Ensure we have the autoloader
$autoloadFile = dirname(__DIR__) . '/vendor/autoload.php';

if (!file_exists($autoloadFile)) {
    echo "Please run 'composer install' before running tests.\n";
    exit(1);
}

require_once $autoloadFile;

/**
 * Client::checkRuntimeRequirements() emits an E_USER_WARNING once per process
 * when PHP is too old or ext-intl is missing. PHPUnit converts warnings to test
 * errors, so on a host without intl it would poison whichever test happens to
 * construct a Client first - an arbitrary, order-dependent failure.
 *
 * Trip the once-per-process flag up front so the suite is deterministic.
 * ClientRequirementsTest resets it explicitly to exercise the guard itself.
 */
$warnedFlag = new \ReflectionProperty(\Langsys\SDK\Client::class, 'requirementsWarned');
$warnedFlag->setAccessible(true);
$warnedFlag->setValue(null, true);

// Same for the missing-credentials warning: tests that build a client without
// credentials would otherwise write it to stderr. UnconfiguredClientTest resets it.
$unconfiguredFlag = new \ReflectionProperty(\Langsys\SDK\Client::class, 'unconfiguredWarned');
$unconfiguredFlag->setAccessible(true);
$unconfiguredFlag->setValue(null, true);
