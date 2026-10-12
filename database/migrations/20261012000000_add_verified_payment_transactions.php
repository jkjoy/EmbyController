<?php

use think\migration\Migrator;

class AddVerifiedPaymentTransactions extends Migrator
{
    public function change()
    {
        $this->table('pay_record')
            ->addColumn('paymentGateway', 'string', ['limit' => 32, 'default' => ''])
            ->addColumn('paymentMethod', 'string', ['limit' => 16, 'default' => ''])
            ->addColumn('providerTransactionId', 'string', ['limit' => 128, 'null' => true])
            ->addIndex(['paymentMethod', 'providerTransactionId'], ['unique' => true, 'name' => 'payment_provider_transaction_unique'])
            ->update();
    }
}
