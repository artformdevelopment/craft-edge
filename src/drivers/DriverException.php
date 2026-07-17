<?php
/**
 * @copyright Copyright (c) ArtformDev
 * @license https://craftcms.github.io/license/ Craft License
 */

namespace artformdev\edge\drivers;

/**
 * A permanent driver failure (misconfiguration, missing module). Not retried.
 */
class DriverException extends \Exception
{
}
