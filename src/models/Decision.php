<?php
/**
 * @copyright Copyright (c) ArtformDev
 * @license https://craftcms.github.io/license/ Craft License
 */

namespace artformdev\edge\models;

/**
 * The result of a cacheability evaluation.
 */
final class Decision
{
    private function __construct(
        public readonly bool $cacheable,
        public readonly string $reason,
    ) {
    }

    public static function cache(string $reason = 'cacheable'): self
    {
        return new self(true, $reason);
    }

    public static function skip(string $reason): self
    {
        return new self(false, $reason);
    }
}
