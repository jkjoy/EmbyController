<?php

namespace app\api\controller;

class Index
{
    public function ping()
    {
        return json([
            'code' => 200,
            'msg' => 'pong',
            'data' => [
                'time' => time()
            ]
        ]);
    }

    // 说明：原 auth() 授权验证接口依赖 app\admin\model\AuthLicense/AuthLog/VersionUpdate，
    // 这些类随 admin 模块裁剪后在本项目中并不存在，接口一旦被调用即触发致命错误，故已移除。
    // 授权相关数据表的 migration（database/migrations/*_create_auth_tables.php）仍保留；
    // 若确需该商业授权校验功能，请从原系统补回对应的 admin 模块 model 后再恢复本接口。
}
