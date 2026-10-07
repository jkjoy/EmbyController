<?php

use think\migration\Migrator;

class ReliableLottery extends Migrator
{
    public function up()
    {
        // 原始重复行完整归档，主表优先保留中奖行；升级不会重新发历史奖励。
        $this->table('lottery_participant_archive')
            ->addColumn('originalId', 'integer')
            ->addColumn('participantData', 'text', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_MEDIUM])
            ->addColumn('archivedAt', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->create();
        $adapter = $this->getAdapter();
        $prefix = $adapter->getOption('table_prefix') ?: '';
        $participantTable = $adapter->quoteTableName($prefix . 'lottery_participant');
        $archiveTable = $adapter->quoteTableName($prefix . 'lottery_participant_archive');
        $duplicates = $this->fetchAll('SELECT lotteryId, telegramId FROM ' . $participantTable . ' GROUP BY lotteryId, telegramId HAVING COUNT(*) > 1');
        foreach ($duplicates as $duplicate) {
            $rows = $this->fetchAll('SELECT * FROM ' . $participantTable . ' WHERE lotteryId = ' . (int) $duplicate['lotteryId']
                . ' AND telegramId = ' . $adapter->getConnection()->quote($duplicate['telegramId']) . ' ORDER BY CASE WHEN status = 1 THEN 0 ELSE 1 END, id');
            array_shift($rows);
            foreach ($rows as $row) {
                $row = array_filter($row, fn($key) => is_string($key), ARRAY_FILTER_USE_KEY);
                $this->execute('INSERT INTO ' . $archiveTable . ' (originalId, participantData) VALUES (' . (int) $row['id'] . ', '
                    . $adapter->getConnection()->quote(json_encode($row, JSON_THROW_ON_ERROR)) . ')');
                $this->execute('DELETE FROM ' . $participantTable . ' WHERE id = ' . (int) $row['id']);
            }
        }
        $this->table('lottery_participant')->addIndex(['lotteryId', 'telegramId'], ['unique' => true, 'name' => 'lottery_participant_unique'])->update();

        // 短事务共用一行锁，覆盖没有现成行可锁的“首次报名”和“开始抽奖”。
        $this->table('lottery_mutex')->addColumn('revision', 'integer', ['default' => 0])->create();
        $this->table('lottery_mutex')->insert(['id' => 1, 'revision' => 0])->saveData();
        $this->table('lottery_notification')
            ->addColumn('lotteryId', 'integer')
            ->addColumn('notificationKey', 'string', ['limit' => 100])
            ->addColumn('chatId', 'string', ['limit' => 64])
            ->addColumn('message', 'text')
            ->addColumn('attempts', 'integer', ['default' => 0])
            ->addColumn('availableAt', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('deliveredAt', 'timestamp', ['null' => true])
            ->addColumn('leaseToken', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('lastError', 'string', ['limit' => 500, 'null' => true])
            ->addIndex(['lotteryId', 'notificationKey'], ['unique' => true, 'name' => 'lottery_notification_unique'])
            ->addIndex(['deliveredAt', 'availableAt'])
            ->create();
    }

    public function down()
    {
        $this->table('lottery_notification')->drop()->save();
        $this->table('lottery_mutex')->drop()->save();
        $this->table('lottery_participant')->removeIndexByName('lottery_participant_unique')->update();
        // 回滚恢复升级前的完整重复记录，但绝不覆盖升级后已经占用的ID。
        $adapter = $this->getAdapter();
        $prefix = $adapter->getOption('table_prefix') ?: '';
        $participantTable = $adapter->quoteTableName($prefix . 'lottery_participant');
        $archiveTable = $adapter->quoteTableName($prefix . 'lottery_participant_archive');
        foreach ($this->fetchAll('SELECT * FROM ' . $archiveTable . ' ORDER BY originalId') as $archive) {
            $row = json_decode($archive['participantData'], true, 512, JSON_THROW_ON_ERROR);
            $row = array_filter($row, fn($key) => is_string($key), ARRAY_FILTER_USE_KEY);
            if ($this->fetchRow('SELECT id FROM ' . $participantTable . ' WHERE id = ' . (int) $row['id'])) continue;
            $columns = array_map(fn($column) => $adapter->quoteColumnName($column), array_keys($row));
            $values = array_map(fn($value) => $value === null ? 'NULL' : $adapter->getConnection()->quote((string) $value), array_values($row));
            $this->execute('INSERT INTO ' . $participantTable . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', $values) . ')');
        }
        $this->table('lottery_participant_archive')->drop()->save();
    }
}
