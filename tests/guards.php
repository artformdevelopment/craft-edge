#!/usr/bin/env php
<?php
/**
 * Self-check for the pure cacheability guards.
 *
 * Deliberately framework-free: these are pure functions over a RequestContext and a
 * Settings model, so a plain assert script is enough and needs no Craft application.
 *
 *   php tests/guards.php
 */

require __DIR__ . '/../vendor/autoload.php';

use artformdev\edge\models\RequestContext;
use artformdev\edge\models\Settings;
use artformdev\edge\services\Cacheability;
use artformdev\edge\services\Generator;

$failures = [];

function check(string $name, bool $ok): void
{
    global $failures;
    if (!$ok) {
        $failures[] = $name;
        echo "  FAIL  $name\n";

        return;
    }
    echo "  ok    $name\n";
}

/**
 * A context that would otherwise cache, so each test isolates one branch.
 */
function ctx(array $overrides = []): RequestContext
{
    return new RequestContext(...($overrides + [
        'method' => 'GET',
        'isSiteRequest' => true,
        'uri' => 'shop',
        'host' => 'example.com',
        'siteHost' => 'example.com',
        'environment' => 'production',
    ]));
}

$cacheability = new Cacheability();
$settings = new Settings();

echo "\nhost guard (P1)\n";
check('matching host caches',
    $cacheability->evaluateRequest(ctx(), $settings)->cacheable);
check('bare IP host is skipped',
    !$cacheability->evaluateRequest(ctx(['host' => '203.0.113.7']), $settings)->cacheable);
check('forged host is skipped',
    !$cacheability->evaluateRequest(ctx(['host' => 'evil.example']), $settings)->cacheable);
check('host comparison is case-insensitive',
    $cacheability->evaluateRequest(ctx(['host' => 'EXAMPLE.COM']), $settings)->cacheable);
check('site with no absolute base URL skips the guard',
    $cacheability->evaluateRequest(ctx(['siteHost' => null, 'host' => 'anything']), $settings)->cacheable);

echo "\ndropped query params (P9)\n";
check('no query string caches',
    $cacheability->evaluateRequest(ctx(), $settings)->cacheable);
check('excluded marketing param still caches',
    $cacheability->evaluateRequest(ctx(['queryParams' => ['utm_source' => 'x']]), $settings)->cacheable);
check('meaningful param is skipped in ignore mode',
    !$cacheability->evaluateRequest(ctx(['queryParams' => ['brand' => 'x']]), $settings)->cacheable);
$respect = new Settings();
$respect->queryStringCaching = Settings::QUERY_STRINGS_RESPECT;
check('respect mode keys the param instead of skipping',
    $cacheability->evaluateRequest(ctx(['queryParams' => ['brand' => 'x']]), $respect)->cacheable);
check('respect mode puts the param in the key',
    $cacheability->getCacheSiteUri(ctx(['queryParams' => ['brand' => 'x']]), $respect)->uri === 'shop?brand=x');

echo "\nlogged-in renders (P7)\n";
check('logged-in skipped by default',
    !$cacheability->evaluateRequest(ctx(['isLoggedIn' => true]), $settings)->cacheable);
$loggedIn = new Settings();
$loggedIn->cacheLoggedInRenders = true;
check('logged-in cacheable when opted in',
    $cacheability->evaluateRequest(ctx(['isLoggedIn' => true]), $loggedIn)->cacheable);

echo "\nbaked CSRF token (P4)\n";
$token = 'CRAFT_CSRF_TOKEN';
check('rendered token refused',
    Generator::containsCsrfToken('<input type="hidden" name="CRAFT_CSRF_TOKEN" value="abc123">', $token));
check('attribute order does not matter',
    Generator::containsCsrfToken('<input value="abc123" name="CRAFT_CSRF_TOKEN" type="hidden">', $token));
check('empty value allowed',
    !Generator::containsCsrfToken('<input type="hidden" name="CRAFT_CSRF_TOKEN" value="">', $token));
check('async placeholder allowed',
    !Generator::containsCsrfToken('<craft-csrf-input></craft-csrf-input>', $token));
check('page without any token allowed',
    !Generator::containsCsrfToken('<p>ordinary copy</p>', $token));
check('single-quoted attributes match',
    Generator::containsCsrfToken("<input name='CRAFT_CSRF_TOKEN' value='abc123'>", $token));
check('renamed token param respected',
    Generator::containsCsrfToken('<input name="MY_TOKEN" value="abc">', 'MY_TOKEN'));

echo "\nidentity leak (P7)\n";
$ids = ['email' => 'person@example.com', 'username' => 'someone', 'full name' => 'A Person'];
check('email in content refused',
    Generator::identifyingFieldInContent('<a>person@example.com</a>', $ids) === 'email');
check('full name in content refused',
    Generator::identifyingFieldInContent('<p>Hello A Person</p>', $ids) === 'full name');
check('clean shell allowed',
    Generator::identifyingFieldInContent('<p>ordinary copy</p>', $ids) === null);
check('anonymous request has nothing to match',
    Generator::identifyingFieldInContent('<p>anything</p>', []) === null);
check('null values ignored',
    Generator::identifyingFieldInContent('<p>copy</p>', ['email' => null]) === null);
check('very short values do not false-positive',
    Generator::identifyingFieldInContent('<p>a boat</p>', ['username' => 'a']) === null);

echo "\n";
if ($failures !== []) {
    echo count($failures) . " FAILED: " . implode(', ', $failures) . "\n";
    exit(1);
}
echo "all guards pass\n";
