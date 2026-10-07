<?php

namespace app\media\controller;

use app\BaseController;
use app\service\SignInService;
use think\facade\Config;
use think\facade\View;

class Account extends BaseController
{
    public function sign()
    {
        if ($this->request->isGet()) {
            $token = $this->request->get('signkey', '');
            $result = SignInService::inspectToken($token);
            View::assign('signkey', $result['code'] === 200 ? $token : '');
            View::assign('errMsg', $result['code'] === 200 ? '' : $result['message']);
            $sitekey = Config::get('apiinfo.cloudflareTurnstile.noninteractive.sitekey', '');
            $secret = Config::get('apiinfo.cloudflareTurnstile.noninteractive.secret', '');
            View::assign('sitekey', $sitekey && $secret ? $sitekey : '');
            // Keep includes rooted in media even for compatibility callers from index.
            View::engine()->config(['view_path' => dirname(__DIR__) . '/view/']);
            return view('account/sign');
        }
        if (!$this->request->isPost()) return json(['code' => 405, 'message' => '请使用 POST 请求签到'], 405);
        $data = $this->request->post();
        $captcha = $data['token'] ?? '';
        if (!is_string($captcha) || !judgeCloudFlare('noninteractive', $captcha)) {
            return json(['code' => 400, 'message' => '环境异常，请重新验证后签到']);
        }
        return json(SignInService::claimToken($data['signkey'] ?? ''));
    }
}
