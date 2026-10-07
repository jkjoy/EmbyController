<?php

namespace app\media\model;

use think\Model;

class ExchangeCodeModel extends Model
{
    // 数据表名（不含前缀）
    protected $name = 'exchange_code';

    // 设置字段信息
    protected $schema = [
        'id' => 'int',
        'createdAt' => 'timestamp',
        'updatedAt' => 'timestamp',
        'code' => 'varchar',
        'type' => 'int',  // 0未使用，1已使用，-1已禁用
        'exchangeType' => 'int',  // 可兑换类型（1激活，2按天续期，3按月续期，4充值余额）
        'exchangeCount' => 'decimal',  // 会员时长为整数，余额支持两位小数
        'exchangeDate' => 'timestamp',
        'usedByUserId' => 'int',  // 被用户（ID）使用时间
        'codeInfo' => 'text',
    ];

    // 设置JSON字段（如果有的话）
    protected $json = ['codeInfo'];

    // 自动写入时间戳字段
    protected $autoWriteTimestamp = 'timestamp'; // 自动写入时间戳
    protected $createTime = 'createdAt'; // 创建时间字段名
    protected $updateTime = 'updatedAt'; // 更新时间字段名
    protected $dateFormat = 'Y-m-d H:i:s'; // 时间字段取出后的默认时间格式

    // 数据表主键 复合主键使用数组定义 不设置则自动获取
    protected $pk = 'id';

    public function updateCodeInfoById($id, $codeInfo)
    {
        $code = $this->where([
            'id' => $id,
        ])->find();
        if ($code) {
            $code->codeInfo = $codeInfo;
            $code->save();
        }
    }

    public function updateUsedByCode($code, $usedByUserId)
    {
        $code = $this->where([
            'code' => $code,
        ])->find();
        if ($code) {
            $code->usedByUserId = $usedByUserId;
            $code->save();
        }
    }

}
