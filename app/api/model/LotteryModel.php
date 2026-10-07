<?php
namespace app\api\model;

use think\Model;

class LotteryModel extends Model
{
    protected $name = 'lottery';
    protected $pk = 'id';

    protected $schema = [
        'id' => 'int',
        'title' => 'varchar',
        'description' => 'varchar',
        'prizes' => 'text',
        'drawTime' => 'timestamp',
        'keywords' => 'varchar',
        'status' => 'int',
        'createTime' => 'timestamp',
        'chatId' => 'varchar',
    ];

    // 自动转换数据类型
    protected $type = [
        'prizes' => 'json',
        'createTime' => 'timestamp'
    ];

    // 自动完成
    protected $auto = ['createTime'];
    
    protected function setCreateTimeAttr()
    {
        return time();
    }

    // 获取器 - 状态文本
    public function getStatusTextAttr($value, $data)
    {
        $status = [
            -1 => '已禁用',
            0 => '未开始',
            1 => '进行中',
            2 => '已结束',
            3 => '开奖中',
        ];
        return $status[$data['status']] ?? '未知';
    }

    // 获取器 - 奖品列表
    public function getPrizesAttr($value)
    {
        return $value ? json_decode($value, true) : [];
    }

    // 修改器 - 奖品列表
    public function setPrizesAttr($value)
    {
        if (is_string($value)) {
            // 如果已经是JSON字符串，直接返回
            json_decode($value);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $value;
            }
        }
        // 否则转换为JSON字符串
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    // 关联参与者
    public function participants()
    {
        return $this->hasMany(LotteryParticipantModel::class, 'lotteryId', 'id');
    }
}
