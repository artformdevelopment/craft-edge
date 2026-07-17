<?php
/**
 * @copyright Copyright (c) ArtformDev
 * @license https://craftcms.github.io/license/ Craft License
 */

namespace artformdev\edge\db;

/**
 * Database table name constants.
 */
abstract class Table
{
    public const CACHES = '{{%edge_caches}}';
    public const CACHE_ELEMENTS = '{{%edge_cache_elements}}';
    public const CACHE_TAGS = '{{%edge_cache_tags}}';
}
