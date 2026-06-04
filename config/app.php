<?php
// +----------------------------------------------------------------------
// | 应用设置
// +----------------------------------------------------------------------

$appHost = env('APP_HOST', '');

if (strpos($appHost, 'http://') === false && strpos($appHost, 'https://') === false) {
    $appHost = 'http://' . $appHost;
}

if (substr($appHost, -1) == '/') {
    $appHost = substr($appHost, 0, -1);
}

if (strpos($appHost, '/media') !== false) {
    $appHost = substr($appHost, 0, strpos($appHost, '/media'));
}

return [
    // 应用地址
    'app_host'         => $appHost,
    // 应用的命名空间
    'app_namespace'    => '',
    // 是否启用路由
    'with_route'       => true,
    // 默认应用
    'default_app'      => 'index',
    // 默认时区
    'default_timezone' => 'Asia/Shanghai',

    // 应用映射（自动多应用模式有效）
    'app_map'          => [],
    // 域名绑定（自动多应用模式有效）
    'domain_bind'      => [],
    // 禁止URL访问的应用列表（自动多应用模式有效）
    'deny_app_list'    => [],

    // 异常页面的模板文件
    'exception_tmpl'   => __DIR__ . '/../view/error.tpl',

    // 错误显示信息,非调试模式有效
    'error_message'    => '页面错误啦！快去找Randall，让他去修bug～',
    // 显示错误信息
    'show_error_msg'   => false,

    // 网站名称：默认值，运行时会被后台设置（数据库 rc_config.siteName）覆盖，见 app\listener\InitSiteConfig
    'app_name' => env('APP_NAME', '算艺轩'),

    // 网站副标题/描述（原硬编码的"影视管理站"）：同样可被后台设置（rc_config.siteSubtitle）覆盖
    'app_subtitle' => env('APP_SUBTITLE', '影视管理站'),

    // 技术支持/版权署名（用于各页 meta author 及首页"由 X 提供技术支持"）：可被后台设置（rc_config.poweredBy）覆盖
    'powered_by' => env('POWERED_BY', 'RandallAnjie.com'),
];
