<?php
/**
 * @copyright Copyright (c) ArtformDev
 * @license https://craftcms.github.io/license/ Craft License
 */

namespace artformdev\edge\migrations;

use artformdev\edge\db\Table;
use craft\db\Migration;

/**
 * Records when a cached page is next due to change status on its own (a scheduled post
 * going live, an entry expiring), so `edge/cache/refresh-expired` can purge it. Nothing
 * else fires at that moment: element status is derived at query time.
 */
class m260721_000000_cache_expiry_date extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        if (!$this->db->columnExists(Table::CACHES, 'expiryDate')) {
            $this->addColumn(Table::CACHES, 'expiryDate', $this->dateTime()->null());
            $this->createIndex(null, Table::CACHES, 'expiryDate');
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        if ($this->db->columnExists(Table::CACHES, 'expiryDate')) {
            $this->dropColumn(Table::CACHES, 'expiryDate');
        }

        return true;
    }
}
