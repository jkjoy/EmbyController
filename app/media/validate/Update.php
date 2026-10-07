<?php

namespace app\media\validate;

use think\Validate;

class Update extends Validate
{
    protected $rule = [
        'username' => 'require|checkUsername',
        'nickname' => 'require|length:2,20',
        'password' => 'require|checkPassword',
        'email' => 'require|email|max:254',
    ];

    protected $message = [
        'username.require' => '用户名不能为空',
        'username.checkUsername' => '用户名必须为3至40位字母、数字、下划线或破折号',
        'nickname.require' => '昵称不能为空',
        'nickname.length' => '昵称长度必须在2至20个字符之间',
        'password.require' => '密码不能为空',
        'password.checkPassword' => '密码必须为6至40位字母、数字、点、下划线或破折号',
        'email.require' => '邮箱不能为空',
        'email.email' => '邮箱格式不正确',
        'email.max' => '邮箱不能超过254个字符',
    ];

    protected $scene = [
        'update' => ['username', 'nickname', 'password', 'email'],
        'reset' => ['email', 'password'],
    ];

    protected function checkUsername($value, $rule, $data = [])
    {
        return is_string($value) && preg_match('/\A[A-Za-z0-9_-]{3,40}\z/', $value) === 1;
    }

    protected function checkPassword($value, $rule, $data = [])
    {
        return is_string($value) && preg_match('/\A[A-Za-z0-9._-]{6,40}\z/', $value) === 1;
    }
}
