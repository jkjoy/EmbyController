<?php

use think\facade\Route;

// 已发送的机器人链接仍可访问，新链接统一使用 /account/sign。
Route::rule('index/account/sign', 'Account/sign', 'GET|POST')->completeMatch();
