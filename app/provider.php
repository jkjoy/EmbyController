<?php
use app\ExceptionHandle;
use app\Request;

// 容器Provider定义文件
return [
    'think\DbManager'        => \EmbyDatabase\DbManager::class,
    'think\Request'          => Request::class,
    'think\exception\Handle' => ExceptionHandle::class,
];
