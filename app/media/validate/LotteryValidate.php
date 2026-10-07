<?php
namespace app\media\validate;

use think\Validate;

class LotteryValidate extends Validate
{
    protected $rule = [
        'title' => 'require|max:100',
        'description' => 'require|max:500',
        'id' => 'require|integer|gt:0',
        'drawTime' => 'require|checkDrawTime',
        'keywords' => 'max:100',
        'chatId' => 'checkChatId',
        'prizes' => 'require|checkPrizes',
    ];

    protected $message = [
        'title.require' => '标题不能为空',
        'title.max' => '标题最多不能超过100个字符',
        'description.require' => '描述不能为空', 
        'description.max' => '描述最多不能超过500个字符',
        'drawTime.require' => '开奖时间不能为空',
        'id' => '抽奖ID必须是正整数',
        'keywords.max' => '关键词最多不能超过100个字符',
        'prizes.require' => '奖品不能为空',
    ];

    protected $scene = [
        'add' => ['title', 'description', 'drawTime', 'keywords', 'chatId', 'prizes'],
        'edit' => ['id', 'title', 'description', 'drawTime', 'keywords', 'chatId', 'prizes']
    ];

    protected function checkDrawTime($value)
    {
        if (!is_string($value) || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}(?:T[0-9]{2}:[0-9]{2}| [0-9]{2}:[0-9]{2}:[0-9]{2})$/D', $value) !== 1) return '开奖时间格式不正确';
        $format = str_contains($value, 'T') ? 'Y-m-d\TH:i' : 'Y-m-d H:i:s';
        $date = \DateTimeImmutable::createFromFormat('!' . $format, $value);
        if (!$date || $date->format($format) !== $value) return '开奖时间格式不正确';
        return $date->getTimestamp() > time() ? true : '开奖时间必须大于当前时间';
    }

    protected function checkChatId($value)
    {
        if ($value === '' || $value === null) return true;
        return is_scalar($value) && preg_match('/^-[1-9][0-9]{0,18}$/D', (string) $value)
            ? true : '群组ID必须是负整数';
    }

    protected function checkPrizes($value)
    {
        if (!is_string($value)) return '奖品数据必须是JSON字符串';
        try {
            \app\service\LotteryService::prizes($value);
            return true;
        } catch (\DomainException $error) {
            return $error->getMessage();
        }
    }
}
