<?php
/**
 * @copyright Copyright (c) ArtformDev
 * @license https://craftcms.github.io/license/ Craft License
 */

namespace artformdev\edge\drivers;

use artformdev\edge\models\Settings;

/**
 * Builds the exact, ordered cache rules JSON that `edge/cloudflare/setup` PUTs to the
 * zone's http_request_cache_settings entrypoint. Pure: unit-tested without the live API.
 */
final class CloudflareRulesBuilder
{
    public const BYPASS_DESCRIPTION = 'Edge: bypass cache on personalization cookie';
    public const CACHE_DESCRIPTION = 'Edge: cache HTML, respect origin headers';
    public const DESCRIPTION_PREFIX = 'Edge: ';

    /**
     * The ordered rules: a bypass-on-cookie rule FIRST (only when at least one bypass
     * cookie is configured), then the cache-eligible HTML rule.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function buildRules(Settings $settings): array
    {
        $rules = [];

        if (self::buildBypassExpression($settings) !== null) {
            $rules[] = [
                'description' => self::BYPASS_DESCRIPTION,
                'expression' => self::buildBypassExpression($settings),
                'action' => 'set_cache_settings',
                'action_parameters' => [
                    'cache' => false,
                ],
            ];
        }

        $rules[] = [
            'description' => self::CACHE_DESCRIPTION,
            'expression' => '(http.request.method eq "GET")',
            'action' => 'set_cache_settings',
            'action_parameters' => [
                'cache' => true,
                // Never override the origin: pages the origin marks private/no-store
                // (personalized, mutations, Set-Cookie responses) are never stored.
                'edge_ttl' => [
                    'mode' => 'respect_origin',
                ],
                // Cookies are deliberately NOT part of the cache key. Marketing params
                // never fragment the cache.
                'cache_key' => [
                    'ignore_query_strings_order' => true,
                    'custom_key' => [
                        'query_string' => [
                            'exclude' => self::expandedExcludedParams($settings),
                        ],
                    ],
                ],
            ],
        ];

        return $rules;
    }

    /**
     * `(http.cookie contains "cart" or http.cookie contains "edge_bypass" ...)`
     * built from the configured bypass cookies, or null when none are configured
     * (no bypass rule is written in that case).
     */
    public static function buildBypassExpression(Settings $settings): ?string
    {
        $clauses = [];
        foreach ($settings->bypassCookies as $cookie) {
            if ($cookie !== '') {
                $clauses[] = sprintf('http.cookie contains "%s"', addcslashes($cookie, '"\\'));
            }
        }

        if (empty($clauses)) {
            return null;
        }

        return '(' . implode(' or ', $clauses) . ')';
    }

    /**
     * Cloudflare's query_string.exclude list takes literal param names; `utm_*` style
     * wildcards are expanded to the known marketing params.
     *
     * @return string[]
     */
    public static function expandedExcludedParams(Settings $settings): array
    {
        $params = [];
        foreach ($settings->excludedQueryStringParams as $param) {
            if ($param === 'utm_*') {
                array_push($params, 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content');
            } elseif ($param !== '' && !str_contains($param, '*')) {
                $params[] = $param;
            }
        }

        return array_values(array_unique($params));
    }

    /**
     * Merges our rules into an existing entrypoint rule list: previous Edge rules are
     * replaced, everything else is preserved, Edge rules go first (bypass before cache).
     *
     * @param array<int, array<string, mixed>> $existingRules
     * @return array<int, array<string, mixed>>
     */
    public static function mergeIntoExisting(array $existingRules, Settings $settings): array
    {
        $others = array_values(array_filter(
            $existingRules,
            fn(array $rule) => !str_starts_with($rule['description'] ?? '', self::DESCRIPTION_PREFIX),
        ));

        // Strip read-only fields Cloudflare rejects on write.
        $others = array_map(function(array $rule): array {
            unset($rule['id'], $rule['version'], $rule['last_updated'], $rule['ref']);

            return $rule;
        }, $others);

        return array_merge(self::buildRules($settings), $others);
    }

    /**
     * Sanitizes a Craft cache tag for use as a Cloudflare Cache-Tag value.
     */
    public static function sanitizeTag(string $tag): string
    {
        return str_replace(['\\', ' ', ','], ['-', '', ''], $tag);
    }
}
