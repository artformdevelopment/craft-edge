<?php
/**
 * @copyright Copyright (c) ArtformDev
 * @license https://craftcms.github.io/license/ Craft License
 */

namespace artformdev\edge\drivers;

/**
 * A transient driver failure (429 / 5xx / network). The purge job retries with backoff.
 */
class RetryableDriverException extends DriverException
{
}
