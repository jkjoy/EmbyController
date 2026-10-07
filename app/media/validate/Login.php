<?php

namespace app\media\validate;

use think\Validate;

class Login extends Validate
{
    protected $rule = [
        'username|用户名' => 'require|checkUsernameOrEmail|checkLength:3,254',
        'password|密码' => 'require|checkPassword|checkLength:6,40',
    ];

    protected $message = [
        'username.require' => '用户名不能为空',
        'username.checkUsernameOrEmail' => '用户名必须为3至40位字母、数字、下划线、破折号，或有效的电子邮件地址',
        'username.checkLength' => '邮箱不能超过254个字符',
        'password.require' => '密码不能为空',
        'password.checkPassword' => '密码只能是字母、数字、下划线和破折号',
        'password.checkLength' => '密码长度必须在6到40个字符之间',
    ];

    protected $scene = [
        'login' => ['username', 'password'],
    ];

    // Username or email 检查
    protected function checkUsernameOrEmail($value, $rule, $data = [])
    {
        if (!is_string($value)) return false;
        // 如果是合法的电子邮件地址，返回true
        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return true;
        }

        // 如果是字母、数字、下划线和破折号，返回true
        if (preg_match('/\A[A-Za-z0-9_-]{3,40}\z/', $value)) {
            return true;
        }

        // 否则，返回false
        return false;
    }

    protected function checkPassword($value, $rule, $data = [])
    {

        // 如果是字母、数字、下划线和破折号，返回true
        if (preg_match('/^[A-Za-z0-9._-]+$/', $value)) {
            return true;
        }

        // 否则，返回false
        return false;
    }

    // 长度检查
    protected function checkLength($value, $rule, $data = [])
    {
        $length = explode(',', $rule);
        $min = $length[0];
        $max = $length[1];
        $len = strlen($value);
        if ($len >= $min && $len <= $max) {
            return true;
        }
        return false;
    }
}
