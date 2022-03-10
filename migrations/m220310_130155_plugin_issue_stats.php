<?php

namespace craft\contentmigrations;

use Craft;
use craft\db\Migration;

/**
 * m220310_130155_plugin_stats migration.
 */
class m220310_130155_plugin_issue_stats extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp()
    {
        $this->createTable('craftnet_plugin_issue_stats', [
            'pluginId' => $this->integer()->notNull(),
            'period' => $this->integer()->notNull(),
            'openIssues' => $this->integer()->notNull(),
            'closedIssues' => $this->integer()->notNull(),
            'openPulls' => $this->integer()->notNull(),
            'mergedPulls' => $this->integer()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'PRIMARY KEY([[pluginId]], [[period]])',
        ]);
        $this->addForeignKey(null, 'craftnet_plugin_issue_stats', ['pluginId'], 'craftnet_plugins', ['id']);
        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown()
    {
        $this->dropTable('craftnet_plugin_issue_stats');
        return true;
    }
}
