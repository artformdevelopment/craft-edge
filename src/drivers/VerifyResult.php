<?php
/**
 * @copyright Copyright (c) ArtformDev
 * @license https://craftcms.github.io/license/ Craft License
 */

namespace artformdev\edge\drivers;

/**
 * The result of a driver verification run.
 */
final class VerifyResult
{
    /**
     * @param string[] $lines Human-readable check lines
     */
    public function __construct(
        public readonly bool $ok,
        public readonly array $lines,
    ) {
    }
}
