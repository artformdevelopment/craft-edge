<?php
/**
 * @copyright Copyright (c) ArtformDev
 * @license https://craftcms.github.io/license/ Craft License
 */

namespace artformdev\edge\migrations;

use artformdev\edge\db\Table;
use craft\db\Migration;

/**
 * Creates the dependency-tracking tables: cached URIs to element IDs to element-query tags.
 */
class Install extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        if (!$this->db->tableExists(Table::CACHES)) {
            $this->createTable(Table::CACHES, [
                'id' => $this->primaryKey(),
                'siteId' => $this->integer()->notNull(),
                'uri' => $this->string(500)->notNull(),
                'dateCached' => $this->dateTime(),
            ]);
        }

        if (!$this->db->tableExists(Table::CACHE_ELEMENTS)) {
            $this->createTable(Table::CACHE_ELEMENTS, [
                'cacheId' => $this->integer()->notNull(),
                'elementId' => $this->integer()->notNull(),
                'PRIMARY KEY([[cacheId]], [[elementId]])',
            ]);
        }

        if (!$this->db->tableExists(Table::CACHE_TAGS)) {
            $this->createTable(Table::CACHE_TAGS, [
                'cacheId' => $this->integer()->notNull(),
                'tag' => $this->string()->notNull(),
                'PRIMARY KEY([[cacheId]], [[tag]])',
            ]);
        }

        $uriColumn = $this->db->getIsPgsql() ? 'LEFT([[uri]], 255)' : 'uri(255)';
        $this->createIndex(null, Table::CACHES, ['siteId', $uriColumn]);
        $this->createIndex(null, Table::CACHE_ELEMENTS, 'elementId');
        $this->createIndex(null, Table::CACHE_TAGS, 'tag');

        $this->addForeignKey(null, Table::CACHES, 'siteId', \craft\db\Table::SITES, 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, Table::CACHE_ELEMENTS, 'cacheId', Table::CACHES, 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, Table::CACHE_ELEMENTS, 'elementId', \craft\db\Table::ELEMENTS, 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, Table::CACHE_TAGS, 'cacheId', Table::CACHES, 'id', 'CASCADE', 'CASCADE');

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::CACHE_TAGS);
        $this->dropTableIfExists(Table::CACHE_ELEMENTS);
        $this->dropTableIfExists(Table::CACHES);

        return true;
    }
}
