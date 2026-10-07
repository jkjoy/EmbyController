<?php

namespace app\listener;

/** 媒体应用直接使用根路由，API 保留多应用入口。 */
class BindMediaApp
{
    public function handle($event): void
    {
        $app = app();
        $path = '/' . ltrim($app->request->pathinfo(), '/');
        $canonical = \app\service\RootRoutes::removeMediaPrefix($path);
        if ($canonical !== $path) {
            if (preg_match('~[\x00-\x20\\\\]~', $path)) {
                throw new \think\exception\HttpResponseException(response('', 400));
            }
            // Redirect before authentication can save a legacy path in the session.
            // 308 preserves POST bodies in old forms and callback URLs.
            $canonical = '/' . ltrim($canonical, '/');
            [, $query] = \app\service\RootRoutes::pathinfoQuery($app->request->server('QUERY_STRING', ''));
            throw new \think\exception\HttpResponseException(redirect($canonical . ($query !== '' ? '?' . $query : ''), 308));
        }
        $prefix = explode('/', $app->request->pathinfo(), 2)[0];
        $prefix = explode('.', $prefix, 2)[0];

        if ($prefix !== 'api') {
            $app->http->name('media');
        }
    }
}
