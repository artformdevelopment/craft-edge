<?php

declare(strict_types=1);

namespace artformdev\edge\migrations;

use artformdev\edge\db\Table;
use craft\db\Migration;

/** Stores pending warming independently of evictable application caches. */
class m261030_000000_warm_requests extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists(Table::WARM_REQUESTS)) {
            $this->createTable(Table::WARM_REQUESTS, [
                'id' => $this->primaryKey(), 'siteId' => $this->integer()->notNull(),
                'uri' => $this->text()->notNull(), 'urlKey' => $this->char(64)->notNull(),
                'generation' => $this->integer()->notNull()->defaultValue(1),
                'claimToken' => $this->char(32), 'leaseUntil' => $this->integer(), 'lastError' => $this->text(),
            ]);
            $this->createIndex(null, Table::WARM_REQUESTS, 'urlKey', true);
            $this->addForeignKey(null, Table::WARM_REQUESTS, 'siteId', \craft\db\Table::SITES, 'id', 'CASCADE');
        }
        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::WARM_REQUESTS);
        return true;
    }
}
