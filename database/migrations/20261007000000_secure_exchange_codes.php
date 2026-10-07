<?php

use think\migration\Migrator;

class SecureExchangeCodes extends Migrator
{
    public function up()
    {
        // 保留历史发行码，包括重复码；兑换时会拒绝有歧义的历史码。
        $this->table('exchange_code')
            ->changeColumn('exchangeCount', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => 1, 'comment' => '会员整数时长或两位小数余额'])
            ->addIndex(['code'], ['name' => 'exchange_code_lookup'])
            ->update();
    }

    public function down()
    {
        $this->table('exchange_code')
            ->removeIndexByName('exchange_code_lookup')
            ->changeColumn('exchangeCount', 'integer', ['default' => 1, 'comment' => '兑换数量'])
            ->update();
    }
}
