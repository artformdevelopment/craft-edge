<?php

declare(strict_types=1);

namespace artformdev\edge\jobs;

/** HTTP failures require an explicit retry instead of an automatic request loop. */
class WarmRequestException extends \RuntimeException
{
}
