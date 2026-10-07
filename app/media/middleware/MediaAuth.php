<?php
/**
 * +----------------------------------------------------------------------
 * | 后台中间件
 * +----------------------------------------------------------------------
 */
namespace app\media\middleware;

use app\api\model\EmbyUserModel;
use think\facade\Session;
use think\facade\Request;
use think\Response;
use think\facade\View;
use think\exception\HttpResponseException;

class MediaAuth
{
    public function handle($request, \Closure $next)
    {
        $url = $request->url(true);
        $path = '/' . ltrim($request->pathinfo(), '/');
        $isTokenSignRequest = preg_match('~\A/(?:index/)?account/sign(?:\.html)?/?\z~i', $path) === 1;
        $isRedeemRequest = preg_match('~\A/server/redeemCode(?:\.html)?/?\z~i', $path) === 1;
        $isProfileRequest = preg_match('~\A/user/update(?:\.html)?/?\z~i', $path) === 1
            || (preg_match('~\A/user/sendVerifyCode(?:\.html)?/?\z~i', $path) === 1 && $request->post('action') === 'update');
        $requiresJsonAuth = $isRedeemRequest || $isProfileRequest;
        $authError = null;
        // 获取当前用户
        $user = Session::get('r_user');
        // 从数据库中获取用户信息
        if ($user) {
            $userModel = new \app\media\model\UserModel();
            $user = $userModel->where('id', $user['id'])->find();
            $wskey = Session::get('wskey');
            if (!$user) {
                $authError = ['code' => 401, 'message' => '请重新登录'];
            } elseif ($user['authority'] < 0) {
                $authError = ['code' => 403, 'message' => '账号已禁用'];
            } elseif ($wskey !== null && (!is_string($wskey) || !hash_equals(md5($user->id . $user->password), $wskey))) {
                // 先校验原会话凭据，再刷新用户，避免旧会话被新密码数据重新认证。
                $authError = ['code' => 401, 'message' => '登录已失效，请重新登录'];
            } else {
                Session::set('r_user', $user);
            }
            if ($authError !== null) {
                foreach (['r_user', 'wskey', 'm_embyId', 'profileToken'] as $key) Session::delete($key);
                $user = null;
            }
        }
        View::assign('user', $user);
        if ($authError !== null && !$isTokenSignRequest) {
            if ($requiresJsonAuth) return json($authError, $authError['code']);
            Session::set('jump_url', $url);
            return redirect((string) url('/user/login'));
        }
        if (!$user && $requiresJsonAuth) return json(['code' => 401, 'message' => '请先登录'], 401);

        // 用实际路由路径校验，首页带查询参数时也允许匿名访问。
        $path = preg_replace('~(?:\.html)?/?$~i', '', $path);
        if ($path === '') $path = '/index/index';

        // 如果未登录且不是访问登录页面，则重定向到登录页面
        $allowList = [
            '/user/login',
            '/user/register',
            '/user/forgot',
            '/user/sendVerifyCode',
            '/user/terms',
            '/user/privacy',
            '/index/index',
            '/index/getPrimaryImg',
            '/index/getLineStatus',
            '/index/getLatestMedia',
            '/server/crontab',
            '/server/resolvePayment',
            '/account/sign',
            '/index/account/sign',
        ];

        $flag = in_array(strtolower($path), array_map('strtolower', $allowList), true);
        if ((empty($user)) && !$flag) {
            Session::set('jump_url', $request->url(true));
            return redirect((string)url('/user/login'));
        }

        if ($user && $user['authority'] >= 0) {
            $embyUserModel = new EmbyUserModel();
            $embyUser = $embyUserModel->where('userId', $user['id'])->select();
            if ($embyUser && count($embyUser) > 0) {
                $embyUser = $embyUser[0];

                if ($embyUser['activateTo']) {
                    if (strtotime($embyUser['activateTo']) < time()) {
                        $embyUser['isActivate'] = false;
                    } else {
                        $embyUser['isActivate'] = true;
                    }
                } else {
                    $embyUser['isActivate'] = true;
                }
            } else {
                $embyUser = null;
            }
            View::assign('embyUser', $embyUser);
        }

        return $next($request);
    }

    /**
     * 操作错误跳转
     * @param mixed $msg 提示信息
     * @param string|null $url 跳转的URL地址
     * @param mixed $data 返回的数据
     * @param integer $wait 跳转等待时间
     * @param array $header 发送的Header信息
     * @return Response
     */
    protected function error($msg = '', ?string $url = null, $data = '', int $wait = 3, array $header = []): Response
    {
        if (is_null($url)) {
            $url = request()->isAjax() ? '' : 'javascript:history.back(-1);';
        } elseif ($url) {
            $url = (strpos($url, '://') || 0 === strpos($url, '/')) ? $url : app('route')->buildUrl($url);
        }

        $result = [
            'code' => 0,
            'msg' => $msg,
            'data' => $data,
            'url' => $url,
            'wait' => $wait,
        ];

        $type = (request()->isJson() || request()->isAjax()) ? 'json' : 'html';

        // 所有form返回的都必须是json，所有A链接返回的都必须是Html
        $type = request()->isGet() ? 'html' : $type;

        if ($type == 'html') {
            $response = view(app('config')->get('app.dispatch_error_tmpl'), $result);
        } else if ($type == 'json') {
            $response = json($result);
        }
        throw new HttpResponseException($response);
    }
}
