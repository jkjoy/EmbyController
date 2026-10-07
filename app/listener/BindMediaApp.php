<?php

namespace app\listener;

/** 媒体应用直接使用根路由，API 保留多应用入口。 */
class BindMediaApp
{
    public function handle($event): void
    {
        $app = app();
        $prefix = explode('/', $app->request->pathinfo(), 2)[0];
        $prefix = explode('.', $prefix, 2)[0];

        if ($prefix !== 'api') {
            $app->http->name('media');
        }
    }
}
