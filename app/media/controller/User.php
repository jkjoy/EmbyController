<?php

namespace app\media\controller;

use app\BaseController;
use app\media\model\FinanceRecordModel;
use app\media\model\MediaCommentModel;
use app\media\model\MediaHistoryModel;
use app\media\model\NotificationModel;
use app\media\model\PayRecordModel;
use app\media\model\SysConfigModel;
use app\media\model\TelegramModel;
use think\facade\Request;
use think\facade\Session;
use app\media\model\UserModel as UserModel;
use app\media\model\EmbyUserModel as EmbyUserModel;
use app\media\model\RequestModel as RequestModel;
use app\media\validate\Login as LoginValidate;
use app\media\validate\Register as RegisterValidate;
use app\media\validate\Update as UpdateValidate;
use think\facade\View;
use think\facade\Config;
use mailer\Mailer;
use think\facade\Cache;
use Telegram\Bot\Api;
use WebSocket\Client;
use think\facade\Db;
use Ip2Region;

class User extends BaseController
{
    public function index()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }

        // 获取emby用户信息
        $embyUserModel = new EmbyUserModel();
        $embyUserFromDatabase = $embyUserModel->where('userId', Session::get('r_user')->id)->find();
        if ($embyUserFromDatabase && $embyUserFromDatabase['embyId'] != null) {
            $embyId = $embyUserFromDatabase['embyId'];
            $activateTo = $embyUserFromDatabase['activateTo'];
        } else {
            $embyId = null;
            $activateTo = null;
        }

        $userModel = new UserModel();
        $rUser = $userModel->where('id', Session::get('r_user')->id)->find();
        $userInfoArray = json_decode(json_encode($rUser['userInfo']), true);

        if (isset($userInfoArray['lastSeenItem']) && $userInfoArray['lastSeenItem'] != null) {
            View::assign('lastSeenItem', $userInfoArray['lastSeenItem']);
        } else {
            View::assign('lastSeenItem', null);
        }

        if (!isset($userInfoArray['lastSignTime']) || (isset($userInfoArray['lastSignTime']) && $userInfoArray['lastSignTime'] != date('Y-m-d')) ) {
            View::assign('canSign', true);
        } else {
            View::assign('canSign', false);
        }

        $sysnotificiations = new SysConfigModel();
        $sysnotificiations = $sysnotificiations->where('key', 'sysnotificiations')->find();
        if ($sysnotificiations) {
            View::assign('sysnotificiations', $sysnotificiations['value']);
        } else {
            View::assign('sysnotificiations', '');
        }

        View::assign('sitekey', Config::get('apiinfo.cloudflareTurnstile.invisible.sitekey'));

        View::assign('rUser', $rUser);
        View::assign('embyId', $embyId);
        View::assign('activateTo', $activateTo);
        return view();
    }

    public function getLatestSeen()
    {
        if (Session::get('r_user') == null) {
            return json(['code' => 400, 'message' => '未登录']);
        }
        if (Request::isPost()) {
            $data = Request::post();
            $page = $data['page']??1;
            $pageSize = $data['pageSize']??10;
            $userModel = new UserModel();
            $rUser = $userModel->where('id', Session::get('r_user')->id)->find();
            $mediaHistoryModel = new MediaHistoryModel();
            $myLastSeen = $mediaHistoryModel
                ->where('userId', Session::get('r_user')->id)
                ->order('updatedAt', 'desc')
                ->page($page, $pageSize)
                ->select();
            return json(['code' => 200, 'message' => '获取成功', 'data' => $myLastSeen]);
        }
    }

    public function login()
    {
        // 已登录自动跳转
        if (Session::has('r_user')) {
            return redirect((string) url('media/user/index'));
        }
        // 初始返回参数
        $results = '';
        // 处理POST请求
        if (Request::isPost()) {
            // 验证输入数据
            $data = Request::post();
            $validate = new LoginValidate();
            if (!$validate->scene('login')->check($data)) {
                // 验证不通过
                $results = $validate->getError();
            } else {
                if (!judgeCloudFlare('noninteractive', $data['cf-turnstile-response']??'')) {
                    $results = "环境异常，请重新验证后点击登录";
                } else {
                    $userModel = new UserModel();
                    $user = $userModel->judgeUser($data['username'], $data['password']);
                    if ($user && $user->authority >= 0) {
                        $embyUserModel = new EmbyUserModel();
                        $embyUserFromDatabase = $embyUserModel->getEmbyId($user->id);
                        if ($embyUserFromDatabase) {
                            $user->embyId = $embyUserFromDatabase;
                        } else {
                            $user->embyId = null;
                        }
                        $userInfoArray = json_decode(json_encode($user['userInfo']), true);
                        $userInfoArray['lastLoginIp'] = getRealIp();
                        if (Config::get('map.enable')) {
                            $userInfoArray['lastLoginLocation'] = getLocation();
                        }
                        $userInfoArray['lastLoginTime'] = date('Y-m-d H:i:s');
                        if (!isset($userInfoArray['loginIps'])) {
                            $userInfoArray['loginIps'] = [];
                        }
                        if (!in_array(getRealIp(), $userInfoArray['loginIps'])) {
                            $notifyMessage = '检测到您的账户在新IP地址：' . getRealIp() . '登录，此地址您从未登录过，请检查您的账户安全。现在该地址已经被记录，可以用于签到/找回密码等操作。';
                            $userInfoArray['loginIps'][] = getRealIp();
                            $notifyMessage .= PHP_EOL . "浏览器：" . $_SERVER['HTTP_USER_AGENT'];
                            sendTGMessage($user->id, $notifyMessage);
                            sendStationMessage($user->id, $notifyMessage);
                        }

                        $userJson = json_encode($userInfoArray);
                        $userModel->updateUserInfo($user->id, $userJson);

                        // 跳转到之前访问的页面或默认页面
                        $jumpUrl = Session::get('jump_url');
                        if (empty($jumpUrl)) {
                            $jumpUrl = (string)url('media/user/index');
                        } else {
                            Session::delete('jump_url');
                        }

                        if (isset($data['remember']) && ($data['remember'] == 'on'  || $data['remember'] == 'true' || $data['remember'] == '1')) {
                            // 保存登录状态
                            Session::set('expire', 86400 * 30);
                            Session::set('wskey', md5($user->id . $user->password));
                            Session::set('m_embyId', $user->embyId);
                            Session::set('r_user', $user);
                        } else {
                            Session::set('expire', 86400);
                            Session::set('wskey', md5($user->id . $user->password));
                            Session::set('m_embyId', $user->embyId);
                            Session::set('r_user', $user);
                        }

                        return redirect($jumpUrl);
                    } else {
                        $results = "登录名或密码错误或该用户被禁用";
                    }
                }
            }
        }
        // 渲染登录页面
        View::assign('result', $results);
        View::assign('sitekey', Config::get('apiinfo.cloudflareTurnstile.noninteractive.sitekey'));
        View::assign('enableEmail', Config::get('mailer.enable'));
        return view();
    }

    public function register()
    {
        // 已登录自动跳转
        if (Session::has('r_user')) {
            return redirect((string) url('media/user/index'));
        }

        $sysConfigModel = new SysConfigModel();
        $avableRegisterCount = $sysConfigModel->where('key', 'avableRegisterCount')->find();
        if ($avableRegisterCount) {
            $avableRegisterCount = $avableRegisterCount['value'];
        } else {
            $avableRegisterCount = 0;
        }

        // 初始返回参数
        $results = '';
        $data = [];
        // 处理POST请求
        if (Request::isPost()) {
            if ($avableRegisterCount <= 0 && $avableRegisterCount != -1) {
                $results = "注册人数已达上限";
            } else {
                // 验证输入数据
                $data = Request::post();

                if (!judgeCloudFlare('noninteractive', $data['cf-turnstile-response']??'')) {
                    $results = "环境异常，请重新验证后点击注册";
                } else {
                    $validate = new RegisterValidate();
                    if (!$validate->scene('register')->check($data)) {
                        // 验证不通过
                        $results = $validate->getError();
                    } else {
                        // 验证通过，进行注册逻辑
                        $flag = true;
                        if (Config::get('mailer.enable')) {
                            // 验证邮箱验证码
                            $cacheKey = 'verifyCode_register_' . $data['email'];
                            $verifyCode = Cache::get($cacheKey);
                            if ($verifyCode != $data['verify'] && Config::get('mailer.enable')) {
                                $flag = false;
                                $results = "邮箱验证码错误";
                            }
                        }
                        if ($flag) {
                            $userModel = new UserModel();
                            $result = $userModel->registerUser($data['username'], $data['password'], $data['email']);
                            if ($result['error']) {
                                $user = null;
                            } else {
                                $user = $result['user'];
                            }
                            if ($user) {
                                Session::set('r_user', $user);
                                $results = "注册成功";
                                if (is_string($user->userInfo)) {
                                    $userInfoArray = json_decode(json_encode($user->userInfo), true);
                                } else {
                                    $userInfoArray = [];
                                }
                                $userInfoArray['lastLoginIp'] = getRealIp();
                                if (Config::get('map.enable')) {
                                    $userInfoArray['lastLoginLocation'] = getLocation();
                                }
                                $userInfoArray['lastLoginTime'] = date('Y-m-d H:i:s');
                                if (!isset($userInfoArray['loginIps'])) {
                                    $userInfoArray['loginIps'] = [];
                                }
                                $userInfoArray['loginIps'][] = getRealIp();
                                $userJson = json_encode($userInfoArray);
                                $userModel->updateUserInfo($user->id, $userJson);

                                // 跳转到之前访问的页面或默认页面
                                $jumpUrl = Session::get('jump_url');
                                if (empty($jumpUrl)) {
                                    $jumpUrl = (string)url('media/user/index');
                                } else {
                                    Session::delete('jump_url');
                                }
                                Session::set('wskey', md5($user->id . $user->password));
                                return redirect($jumpUrl);
                            } else {
                                $results = "注册失败：" . $result['error'];
                            }
                        }
                    }
                }
            }
        }

        // 渲染注册页面
        View::assign('data', $data);
        View::assign('result', $results);
        View::assign('sitekey', Config::get('apiinfo.cloudflareTurnstile.noninteractive.sitekey'));
        View::assign('enableEmail', Config::get('mailer.enable'));
        View::assign('avableRegisterCount', $avableRegisterCount);
        return view();
    }

    public function update()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }
        // 处理POST请求
        if (Request::isPost()) {
            $data = Request::post();
            $userModel = new UserModel();
            $validate = new UpdateValidate();
            if (!$validate->scene('update')->check(['id' => Session::get('r_user')->id, 'username' => $data['username'], 'email' => $data['email'], 'password' => $data['password']])) {
                return json(['code' => 400, 'message' => $validate->getError()]);
            }
            $user = $userModel->where('id', Session::get('r_user')->id)->find();
            if ($user->email != $data['email']) {
                $code = Cache::get('verifyCode_update_' . $data['email']);
                if ($code != $data['verify']) {
                    return json(['code' => 400, 'message' => '邮箱验证码错误']);
                }
            }
            $results = $userModel->updateUser(Session::get('r_user')->id, $data);
            if ($results['user']) {
                Session::set('r_user', $results['user']);
                return json(['code' => 200, 'message' => '更新成功']);
            } else {
                return json(['code' => 400, 'message' => '更新失败：' . $results['error']]);
            }
        }
    }

    public function forgot()
    {
        // 已登录自动跳转
        if (Session::has('r_user')) {
            return redirect((string) url('media/user/index'));
        }
        if (!Config::get('mailer.enable')) {
            return redirect((string) url('media/user/login'));
        }

        $results = '';
        $code = '';
        $email = '';
        if (Request::isGet()) {

            $data = Request::get();
            if (isset($data['code'])) {
                $code = $data['code'];
            }
            if (isset($data['email'])) {
                $email = $data['email'];
            }
            View::assign('email', $email);
            View::assign('result', $results);
            View::assign('code', $code);
            View::assign('sitekey', Config::get('apiinfo.cloudflareTurnstile.noninteractive.sitekey'));
            return view();
        } elseif (Request::isPost()) {
            $data = Request::post();

            if (!judgeCloudFlare('noninteractive', $data['cf-turnstile-response']??'')) {
                $results = "环境异常，请重新验证后重试";
            } else {
                if (isset($data['email']) && (!isset($data['code']))) {
                    $userModel = new UserModel();
                    $user = $userModel->where('email', $data['email'])->find();
                    if (!$user) {
                        $user = $userModel->where('userName', $data['email'])->find();
                    }
                    if ($user && $user->email) {
                        $code = rand(100000, 999999);
                        Cache::set('verifyCode_forgot_' . $user->email, $code, 300);

                        $Url = (Config::get('app.app_host')??Request::domain()) . '/media/user/forgot?email=' . $user->email . '&code=' . $code;
                        $Email = $user->email;
                        $SiteUrl = (Config::get('app.app_host')??Request::domain()) . '/media';

                        $sysConfigModel = new SysConfigModel();
                        $findPasswordTemplate = $sysConfigModel->where('key', 'findPasswordTemplate')->find();
                        if ($findPasswordTemplate) {
                            $findPasswordTemplate = $findPasswordTemplate['value'];
                        } else {
                            $findPasswordTemplate = '您的找回密码链接是：<a href="{Url}">{Url}</a>';
                        }

                        $findPasswordTemplate = str_replace('{Url}', $Url, $findPasswordTemplate);
                        $findPasswordTemplate = str_replace('{Email}', $Email, $findPasswordTemplate);
                        $findPasswordTemplate = str_replace('{SiteUrl}', $SiteUrl, $findPasswordTemplate);

//                        sendEmailForce($user->email, '找回密码——' . Config::get('app.app_name'), $findPasswordTemplate);

                        \think\facade\Queue::push('app\api\job\SendMailMessage', [
                            'to' => $user->email,
                            'subject' => '找回密码——' . Config::get('app.app_name'),
                            'content' => $findPasswordTemplate,
                            'isHtml' => true
                        ], 'main');

                        sendTGMessage($user->id, "您正在尝试找回密码，如果不是您本人操作，请忽略此消息。");
                        sendStationMessage($user->id, "您正在尝试找回密码，如果不是您本人操作，请忽略此消息。");
                        $code = '';
                    }
                    $results = '如果该用户存在，重置密码链接已发送到您的邮箱';
                }
                if (isset($data['email']) && isset($data['code'])) {
                    $email = $data['email'];
                    $code = $data['code'];
                    $userModel = new UserModel();
                    $user = $userModel->where('email', $data['email'])->find();
                    if(!$user){
                        return json(['code' => 400, 'message' => '用户不存在']);
                    }

                    $validate = new UpdateValidate();
                    if (!$validate->scene('update')->check([
                        'id' => $user->id,
                        'username' => null,
                        'email' => $data['email'],
                        'password' => $data['password']
                    ])) {
                        $results = $validate->getError();
                    } else {
                        $verifyCode = Cache::get('verifyCode_forgot_' . $data['email']);
                        if ($verifyCode != $data['code']) {
                            $results = '验证码错误';
                        } else {
                            $user = $userModel->where('email', $data['email'])->find();
                            if ($user){
                                $results = $userModel->updateUser($user->id, ['password' => $data['password']]);
                            } else {
                                $results = ['error' => '用户不存在'];
                            }
                            if ($results['user']) {
                                sendTGMessage($user->id, "您的密码已修改，请注意账户安全");
                                $results = '密码重置成功，请重新登录';
                            } else {
                                $results = '密码重置失败：' . $results['error'];
                            }
                        }
                    }
                }
            }
            View::assign('email', $email);
            View::assign('result', $results);
            View::assign('code', $code);
            View::assign('sitekey', Config::get('apiinfo.cloudflareTurnstile.noninteractive.sitekey'));
            return view();
        }
    }

    public function logout()
    {
        // 退出登录
        Session::delete('r_user');
        Session::delete('m_embyId');
        Session::delete('wskey');
        return redirect('/media/index/index');
    }

    public function userconfig()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }
        $userModel = new UserModel();
        $user = $userModel->where('id', Session::get('r_user')->id)->find();
        if(!$user){
            return redirect('/media/user/login');
        } else {
            $user->password = '';
        }
        $userInfoArray = json_decode(json_encode($user['userInfo']), true);
        if (isset($userInfoArray['banEmail']) && ($userInfoArray['banEmail'] == 1 || $userInfoArray['banEmail'] == "1")) {
            View::assign('emailNotification', false);
        } else {
            View::assign('emailNotification', true);
        }
        View::assign('userInfo', $userInfoArray);

        if (!Config::get('telegram.botConfig.bots.randallanjie_bot.token') ||
            Config::get('telegram.botConfig.bots.randallanjie_bot.token') == '' ||
            Config::get('telegram.botConfig.bots.randallanjie_bot.token') == null ||
            Config::get('telegram.botConfig.bots.randallanjie_bot.token') == 'notgbot') {
            View::assign('enableTelegram', false);
            View::assign('tgNotification', false);
            View::assign('tgUser', null);
        } else {
            View::assign('enableTelegram', true);
            $telegramModel = new TelegramModel();
            $telegramUser = $telegramModel->where('userId', Session::get('r_user')->id)->find();
            if ($telegramUser) {
                $tgUserInfoArray = json_decode(json_encode($telegramUser['userInfo']), true);

                if (isset($tgUserInfoArray['notification']) && ($tgUserInfoArray['notification'] == 1 || $tgUserInfoArray['notification'] == "1")) {
                    View::assign('tgNotification', true);
                } else {
                    View::assign('tgNotification', false);
                }

                // 获取tg用户信息
                $telegram = new Api(Config::get('telegram.botConfig.bots.randallanjie_bot.token'));
                $tgUser = $telegram->getChat(['chat_id' => $telegramUser['telegramId']]);
                if ($tgUser) {
                    $tgUserInfoArray['tgUser'] = $tgUser;
                } else {
                    $tgUserInfoArray['tgUser'] = null;
                }

                View::assign('tgUser', $tgUser);
            } else {
                $tgUserInfoArray = [];
                View::assign('tgNotification', false);
                View::assign('tgUser', null);
            }
        }
        View::assign('enableEmail', Config::get('mailer.enable'));
        return view();
    }

    public function request()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }
        $page = input('page', 1, 'intval');
        $pagesize = input('pagesize', 10, 'intval');
        $requestModel = new RequestModel();
        $requestModel = $requestModel
            ->where('requestUserId', Session::get('r_user')->id)
            ->order('id', 'desc');
        $pageCount = ceil($requestModel->count() / $pagesize);
        $requestsList = $requestModel
            ->page($page, $pagesize)
            ->select();
        View::assign('page', $page);
        View::assign('pageCount', $pageCount);
        View::assign('requestsList', $requestsList);
        View::assign('request', true);
        return view();
    }

    public function newRequest()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }
        if (Request::isGet()) {
            return view();
        } else if (Request::isPost()) {
            $data = Request::post();
            $requestModel = new RequestModel();
            $message[] = [
                'role' => 'user',
                'time' => date('Y-m-d H:i:s'),
                'content' => $data['content'],
            ];
            $requestModel->save([
                'type' => 1,
                'requestUserId' => Session::get('r_user')->id,
                'message' => json_encode($message),
                'requestInfo' => json_encode([
                    'title' => $data['title'],
                ]),
            ]);

            sendTGMessage(Session::get('r_user')->id, "您提交标题为 <strong>" . $data['title'] . "</strong> 的工单已经被记录，请耐心等待管理员处理");
            $messageId = sendTGMessageToGroup("用户提交了标题为 <strong>" . $data['title'] . "</strong> 的工单，请及时处理");

            $requestModel->where('id', $requestModel->id)->update(['requestInfo' => [
                'title' => $data['title'],
                'messageId' => $messageId,
            ]]);

            return json(['code' => 200, 'message' => '请求已提交']);
        }
    }

    public function requestDetail()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }
        $data = Request::get();
        $requestModel = new RequestModel();
        $request = $requestModel->where('id', $data['id'])->find();

        if (!$request || $request->requestUserId != Session::get('r_user')->id) {
            return redirect('/media/user/request');
        }

        $request['message'] = json_decode($request['message'], true);
        View::assign('request', $request);
        return view();
    }

    public function requestAddReply()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }
        if (Request::isPost()) {
            $data = Request::post();
            if ($data['content'] == '') {
                return json(['code' => 400, 'message' => '回复内容不能为空']);
            }
            $requestModel = new RequestModel();
            $request = $requestModel->where('id', $data['requestId'])->find();

            if (!$request || $request->requestUserId != Session::get('r_user')->id) {
                return json(['code' => 400, 'message' => '工单不存在']);
            }

            $message = json_decode($request['message'], true);
            if ($request->type == -1) {
                $message[] = [
                    'role' => 'system',
                    'time' => date('Y-m-d H:i:s'),
                    'content' => '用户重新开启工单',
                ];
            }
            $message[] = [
                'role' => 'user',
                'time' => date('Y-m-d H:i:s'),
                'content' => $data['content'],
            ];
            $request->message = json_encode($message);
            $request->type = 1;
            $request->save();
            if ($request->replyUserId) {
                $user = Session::get('r_user');
                $requestInfoArray = json_decode(json_encode($request['requestInfo']), true);
                sendTGMessage($request->replyUserId, "用户 <strong>". ($user->nickNamw??$user->userName)   . "(#" . $user->id . ")" ."</strong> 回复了标题为 <strong>" . $requestInfoArray['title'] . "</strong> 的工单，请及时处理");
            }
            return json(['code' => 200, 'message' => '回复已提交', 'messageRecord' => json_encode($message)]);
        }
    }

    public function requestClose()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }
        if (Request::isPost()) {
            $data = Request::post();
            $requestModel = new RequestModel();
            $request = $requestModel->where('id', $data['requestId'])->find();

            if (!$request || $request->requestUserId != Session::get('r_user')->id) {
                return json(['code' => 400, 'message' => '工单不存在']);
            }

            if ($request->type != -1) {
                $message = json_decode($request['message'], true);
                $message[] = [
                    'role' => 'system',
                    'time' => date('Y-m-d H:i:s'),
                    'content' => '用户手动关闭工单',
                ];
                $request->message = json_encode($message);
                $request->type = -1;
                $request->save();
                return json(['code' => 200, 'message' => '工单已关闭', 'messageRecord' => json_encode($message)]);
            } else {
                return json(['code' => 400, 'message' => '工单已关闭']);
            }
        }
    }

    public function createNewEmbyUser()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }
        if (Request::isPost()) {
            $data = Request::post();
            $embyName = $data['userName'];
            $embyPassword = $data['password'];
        }
    }

    public function sendVerifyCode()
    {
        $data = Request::post();
        $email = $data['email'];
        $action = $data['action'];
        // 判断邮箱是否合法
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return json(['code' => 400, 'message' => '邮箱格式不正确']);
        }

        $sysConfigModel = new SysConfigModel();
        $avableRegisterCount = $sysConfigModel->where('key', 'avableRegisterCount')->find();
        if ($avableRegisterCount) {
            $avableRegisterCount = $avableRegisterCount['value'];
        } else {
            $avableRegisterCount = 0;
        }

        if ($action == 'register' && $avableRegisterCount <= 0 && $avableRegisterCount != -1) {
            return json(['code' => 400, 'message' => '注册功能已关闭']);
        }

        $code = rand(100000, 999999);
        $cacheKey = 'verifyCode_' . $action . '_' . $email;
        if (Cache::get($cacheKey)) {
            return json(['code' => 400, 'message' => '验证码未过期，请勿重复发送']);
        }
        Cache::set($cacheKey, $code, 300);

        $SiteUrl = Config::get('app.app_host').'/media';

        $sysConfigModel = new SysConfigModel();
        $verifyCodeTemplate = $sysConfigModel->where('key', 'verifyCodeTemplate')->find();

        if ($verifyCodeTemplate) {
            $verifyCodeTemplate = $verifyCodeTemplate['value'];
        } else {
            $verifyCodeTemplate = '您的验证码是：{Code}';
        }

        $verifyCodeTemplate = str_replace('{Code}', $code, $verifyCodeTemplate);
        $verifyCodeTemplate = str_replace('{Email}', $email, $verifyCodeTemplate);
        $verifyCodeTemplate = str_replace('{SiteUrl}', $SiteUrl, $verifyCodeTemplate);

//        sendEmailForce($email, '【' . $code . '】' . Config::get('app.app_name') . '验证码', $verifyCodeTemplate);

        \think\facade\Queue::push('app\api\job\SendMailMessage', [
            'to' => $email,
            'subject' => '【' . $code . '】' . Config::get('app.app_name') . '验证码',
            'content' => $verifyCodeTemplate,
            'isHtml' => true
        ], 'main');

        return json(['code' => 200, 'message' => '验证码已尝试发送']);
    }

    public function sign()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }

        if (Request::isPost()) {
            $data = Request::post();
            $userModel = new UserModel();
            $user = $userModel->where('id', Session::get('r_user')->id)->find();
            if ($user) {
                $userInfoArray = json_decode(json_encode($user['userInfo']), true);

                if (!judgeCloudFlare('invisible', $data['token']??'')) {
                    return json(['code' => 400, 'message' => '签到失败，如果今天未签到请重新登录后重试']);
                }

                $flag = false;
                if (isset($userInfoArray['loginIps']) && ((isset($userInfoArray['lastSignTime']) && in_array(getRealIp(), $userInfoArray['loginIps']) && $userInfoArray['lastSignTime'] != date('Y-m-d')) || (!isset($userInfoArray['lastSignTime']) && in_array(getRealIp(), $userInfoArray['loginIps'])))){
                    $flag = true;
                } else {
                    if (config('map.enable') && isset($userInfoArray['lastLoginLocation'])) {
                        $lastloginLocation = json_decode(json_encode($userInfoArray['lastLoginLocation']), true);
                        $thinLocation = getLocation();
                        if ($lastloginLocation == $thinLocation) {
                            $flag = true;
                        } else if ($lastloginLocation['nation'] == $thinLocation['nation'] && $lastloginLocation['city'] == $thinLocation['city']) {
                            $flag = true;
                        }
                    }
                }

                if ($flag) {
                    $userId = Session::get('r_user')->id;
                    $sysConfigModel = new SysConfigModel();
                    $signInMaxAmount = $sysConfigModel->where('key', 'signInMaxAmount')->find();
                    if ($signInMaxAmount) {
                        $signInMaxAmount = $signInMaxAmount['value'];
                    } else {
                        $signInMaxAmount = 0;
                    }
                    $signInMinAmount = $sysConfigModel->where('key', 'signInMinAmount')->find();
                    if ($signInMinAmount) {
                        $signInMinAmount = $signInMinAmount['value'];
                    } else {
                        $signInMinAmount = 0;
                    }
                    if ($signInMaxAmount > 0 && $signInMinAmount >= 0 && $signInMaxAmount > $signInMinAmount) {
                        $score = mt_rand($signInMinAmount*100, $signInMaxAmount*100) / 100;
                    } else {
                        $score = 0;
                    }

                    Db::startTrans();
                    try {
                        // 悲观锁串行化，并在锁内统一校验“今日是否已签到”，防止并发或不同判定分支重复领取
                        $lockedUser = (new UserModel())->where('id', $userId)->lock(true)->find();
                        $lockedInfo = json_decode(json_encode($lockedUser['userInfo']), true);
                        if (isset($lockedInfo['lastSignTime']) && $lockedInfo['lastSignTime'] == date('Y-m-d')) {
                            Db::rollback();
                            return json(['code' => 400, 'message' => '今日已签到，请明天再来']);
                        }

                        $lockedInfo['lastSignTime'] = date('Y-m-d');
                        $lockedUser->userInfo = json_encode($lockedInfo);
                        $lockedUser->rCoin = $lockedUser->rCoin + $score;
                        $lockedUser->save();

                        $financeRecordModel = new FinanceRecordModel();
                        $financeRecordModel->save([
                            'userId' => $userId,
                            'action' => 4,
                            'count' => $score,
                            'recordInfo' => [
                                'message' => '签到获取' . $score . 'R币',
                            ]
                        ]);
                        Db::commit();
                    } catch (\Exception $e) {
                        Db::rollback();
                        return json(['code' => 400, 'message' => '签到失败，请稍后重试']);
                    }

                    // 更新Session
                    $user = (new UserModel())->where('id', $userId)->find();
                    Session::set('r_user', $user);

                    sendTGMessage($userId, "签到成功！今天签到获取" . $score . "R币");

                    return json(['code' => 200, 'message' => '签到成功！今天签到获取' . $score . 'R币']);
                } else {
                    return json(['code' => 400, 'message' => '签到失败，如果今天未签到请重新登录后重试']);
                }

            } else {
                return json(['code' => 400, 'message' => '签到失败，如果今天未签到请重新登录后重试']);
            }
        }
    }

    public function tgUnbind()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }
        if (Request::isPost()) {
            $telegramModel = new TelegramModel();
            $telegramUser = $telegramModel->where('userId', Session::get('r_user')->id)->find();
            if ($telegramUser) {
                $telegramUser->delete();
                return json(['code' => 200, 'message' => '解绑成功']);
            } else {
                return json(['code' => 400, 'message' => '解绑失败']);
            }
        }
    }

    public function getTGBindCode()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }
        if (Request::isPost()) {
            $telegramModel = new TelegramModel();
            $telegramUser = $telegramModel->where('userId', Session::get('r_user')->id)->find();
            if ($telegramUser) {
                return json(['code' => 400, 'message' => '您已经绑定了Telegram']);
            } else {
                $code = rand(100000, 999999);
                $cacheKey = 'tgBindKey_' . $code;
                Cache::set($cacheKey, Session::get('r_user')->id, 120);
                return json(['code' => 200, 'message' => '获取成功', 'data' => $code]);
            }
        }
    }

    public function setEmailNotification()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }
        if (Request::isPost()) {
            $data = Request::post();
            $userModel = new UserModel();
            $user = $userModel->where('id', Session::get('r_user')->id)->find();
            if ($user) {
                $userInfoArray = json_decode(json_encode($user['userInfo']), true);
                if ($data['emailNotification'] == 'true' || $data['emailNotification'] == 1 || $data['emailNotification'] == "1") {
                    $userInfoArray['banEmail'] = 0;
                } else {
                    $userInfoArray['banEmail'] = 1;
                }
                $user->userInfo = json_encode($userInfoArray);
                $user->save();
                return json(['code' => 200, 'message' => '设置成功']);
            } else {
                return json(['code' => 400, 'message' => '设置失败']);
            }
        }
    }

    public function setTGNotification()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }
        if (Request::isPost()) {
            $data = Request::post();
            $telegramModel = new TelegramModel();
            $telegramUser = $telegramModel->where('userId', Session::get('r_user')->id)->find();
            if ($telegramUser) {
                $userInfoArray = json_decode(json_encode($telegramUser['userInfo']), true);
                if ($data['tgNotification'] == 'true' || $data['tgNotification'] == 1 || $data['tgNotification'] == "1") {
                    $userInfoArray['notification'] = 1;
                } else {
                    $userInfoArray['notification'] = 0;
                }
                $telegramUser->userInfo = json_encode($userInfoArray);
                $telegramUser->save();
                return json(['code' => 200, 'message' => '设置成功']);
            } else {
                return json(['code' => 400, 'message' => '设置失败']);
            }
        }
    }

    public function seek()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }
        View::assign('seek', true);
        return view();
    }

    public function comment()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }
        if (Request::isGet()) {
            $page = input('page', 1, 'intval');
            $pagesize = input('pagesize', 10, 'intval');

        }
        return view();
    }

    public function getCommentList()
    {
        if (Session::get('r_user') == null) {
            return json(['code' => 400, 'message' => '请先登录']);
        }
        if (Request::isPost()) {
            $data = Request::post();
            $page = $data['page'] ?? 1;
            $pagesize = $data['pageSize'] ?? 10;
            $offset = ($page - 1) * $pagesize;

            $commentModel = new MediaCommentModel();
            $comments = $commentModel
                ->alias('c')
                ->join('rc_media_info m', 'm.id = c.mediaId')
                ->field('c.mediaId, m.mediaName, m.mediaYear, m.mediaType, m.mediaMainId, AVG(c.rating) as averageRating, COUNT(c.id) as commentCount')
                ->group('c.mediaId')
                ->order('c.id', 'desc')
                ->limit($offset, $pagesize)
                ->select()
                ->toArray();

            return json(['code' => 200, 'message' => '获取成功', 'data' => $comments]);
        }
    }

    public function getComments()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }
        if (Request::isPost()) {
            $data = Request::post();
            $mediaId = $data['mediaId']??0;
            $page = $data['page']??1;
            $pagesize = $data['pagesize']??10;
            if ($mediaId == 0) {
                return json(['code' => 400, 'message' => '参数错误']);
            }
            $commentModel = new MediaCommentModel();
            $commentList = $commentModel
                ->where('mediaId', $mediaId)
                ->order('id', 'desc')
                ->page($page, $pagesize)
                ->select();
            foreach ($commentList as $key => $comment) {
                if ($comment['userId'] && $comment['userId'] != 0) {
                    $userModel = new UserModel();
                    $user = $userModel->where('id', $comment['userId'])->find();
                    $commentList[$key]['username'] = $user->nickName??$user->userName;
                }
                if ($comment['mentions'] && $comment['mentions'] != '[]') {
                    $mentions = json_decode($comment['mentions'], true);
                    $mentionsUser = [];
                    foreach ($mentions as $mention) {
                        $userModel = new UserModel();
                        $user = $userModel->where('id', $mention)->find();
                        if ($user) {
                            $mentionsUser[] = [
                                'id' => $user->id,
                                'username' => $user->nickName??$user->userName,
                            ];

                        }
                    }
                    $commentList[$key]['mentions'] = $mentionsUser;
                }
                if ($comment['quotedComment'] && $comment['quotedComment'] != 0) {
                    $quotedComment = $commentModel->where('id', $comment['quotedComment'])->find();
                    if ($quotedComment) {
                        $user = $userModel->where('id', $quotedComment['userId'])->find();
                        $quotedComment['username'] = $user->nickName ?? $user->userName;
                        $commentList[$key]['quotedComment'] = json_decode($quotedComment, true);
                        if ($quotedComment['mentions'] && $quotedComment['mentions'] != '[]') {
                            $quotedComment['mentions'] = json_decode($quotedComment['mentions'], true);
                            $mentionsUser = [];
                            foreach ($quotedComment['mentions'] as $mention) {
                                $userModel = new UserModel();
                                $user = $userModel->where('id', $mention)->find();
                                if ($user) {
                                    $mentionsUser[] = [
                                        'id' => $user->id,
                                        'username' => $user->userName,
                                    ];
                                }
                            }
                            $quotedComment['mentions'] = $mentionsUser;
                        }
                        $comment['quotedComment'] = json_decode($quotedComment, true);
                    }
                }
            }
            return json(['code' => 200, 'message' => '获取成功', 'data' => $commentList]);
        }
    }

    public function getOneComment()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }
        if (Request::isPost()) {
            $data = Request::post();
            $id = $data['id'] ?? 0;
            if ($id == 0) {
                return json(['code' => 400, 'message' => '参数错误']);
            }
            $commentModel = new MediaCommentModel();
            $comment = $commentModel->where('id', $id)->find();
            if ($comment) {
                if ($comment['userId'] && $comment['userId'] != 0) {
                    $userModel = new UserModel();
                    $user = $userModel->where('id', $comment['userId'])->find();
                    $comment['username'] = $user->userName;
                }
                if ($comment['mentions'] && $comment['mentions'] != '[]') {
                    $mentions = json_decode($comment['mentions'], true);
                    $mentionsUser = [];
                    foreach ($mentions as $mention) {
                        $userModel = new UserModel();
                        $user = $userModel->where('id', $mention)->find();
                        if ($user) {
                            $mentionsUser[] = [
                                'id' => $user->id,
                                'username' => $user->userName,
                            ];

                        }
                    }
                    $comment['mentions'] = $mentionsUser;
                }
                return json(['code' => 200, 'message' => '获取成功', 'data' => $comment]);
            } else {
                return json(['code' => 400, 'message' => '获取失败']);
            }
        }
    }


    public function commentDetail()
    {

        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }
        if (Request::isGet()) {
            $mediaId = input('id', 0, 'intval');
            View::assign('mediaId', $mediaId);
            View::assign('comment', true);
            return view();
        }
    }

    public function addComment()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }
        if (Request::isPost()) {
            $data = Request::post();
            if (isset($data['mediaId']) && isset($data['comment']) && $data['mediaId'] != '' && $data['comment'] != '') {
                $commentModel = new MediaCommentModel();
                $userModel = new UserModel();
                $user = $userModel->where('id', Session::get('r_user')->id)->find();
                if (!$user) {
                    return json(['code' => 400, 'message' => '用户不存在']);
                } else if ($user->authority < 0) {
                    return json(['code' => 400, 'message' => '用户已被封禁']);
                } else if ($user->rCoin < 0.01) {
                    return json(['code' => 400, 'message' => 'R币不足']);
                }
                $data['comment'] .= ' ';
                $pattern = '/@([a-zA-Z0-9_]+)/';
                preg_match_all($pattern, $data['comment'], $matches);
                $mentions = [];
                if (count($matches[1]) > 0) {
                    foreach ($matches[1] as $match) {
                        $user = $userModel->where('nickName', $match)->find();
                        if ($user) {
                            $data['comment'] = str_replace('@' . $match, '@#' . $user->id . '# ', $data['comment']);
                            if (!in_array($user->id, $mentions)) {
                                $mentions[] = $user->id;
                            }
                        }
                    }
                }
                $commentModel->save([
                    'userId' => Session::get('r_user')->id,
                    'mediaId' => $data['mediaId'],
                    'rating' => $data['rate'],
                    'comment' => $data['comment'],
                    'mentions' => json_encode($mentions),
                    'quotedComment' => $data['replyTo']??0,
                ]);

                // 获取$data['comment']的字数
                $commentLength = mb_strlen($data['comment'], 'utf-8');

                // 每100个字0.01R币，不够100向上取整
                $rCoin = ceil($commentLength / 100) * 0.01;

                $user = $userModel->where('id', Session::get('r_user')->id)->find();
                $user->rCoin = $user->rCoin - 0.01 + $rCoin;
                $user->save();

                $financeRecordModel = new FinanceRecordModel();
                $financeRecordModel->save([
                    'userId' => Session::get('r_user')->id,
                    'action' => 3,
                    'count' => 0.01,
                    'recordInfo' => [
                        'message' => '影视评论消耗0.01R币',
                    ]
                ]);
                $financeRecordModel = new FinanceRecordModel();
                $financeRecordModel->save([
                    'userId' => Session::get('r_user')->id,
                    'action' => 8,
                    'count' => $rCoin,
                    'recordInfo' => [
                        'message' => '影视评论奖励' . $rCoin . 'R币',
                    ]
                ]);

                return json(['code' => 200, 'message' => '评论成功']);
            } else {
                return json(['code' => 400, 'message' => '评论失败']);
            }
        }
    }

    public function notifications()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }


        $notificationModel = new NotificationModel();
        $userId = Session::get('r_user')->id;
        $notificationModel = $notificationModel
            ->where('toUserId', $userId)
            ->where('fromUserId', '0')
            ->order('id', 'desc')
            ->limit(1)
            ->select();

        if ($notificationModel && count($notificationModel) > 0) {
            view::assign('notification', $notificationModel[0]);
        } else {
            view::assign('notification', null);
        }

        return view();
    }
    public function notificationDetail()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }

        if (Request::isGet()) {
            $id = input('id', 0, 'intval');
            view::assign('id', $id);
            if ($id != 0) {
                $userModel = new UserModel();
                $user = $userModel->where('id', $id)->find();
                if ($user) {
                    $nickName = $user->nickName??$user->userName;
                } else {
                    $nickName = '神秘用户';
                }
            } else {
                $nickName = '系统通知';
            }
            View::assign('nickName', $nickName);
            return view();
        }

        return view();
    }


    public function getNotifications()
    {
        if (Session::get('r_user') == null) {
            return json(['code' => 400, 'message' => '请先登录']);
        }

        if (Request::isPost()) {
            $data = Request::post();
            $page = $data['page'] ?? 1;
            $pagesize = $data['pageSize'] ?? 10;
            $offset = ($page - 1) * $pagesize;

            $userId = Session::get('r_user')->id;
            $notificationModel = new NotificationModel();

            // 使用子查询获取每个对话的最新消息
            $subQuery = $notificationModel->field([
                'id',
                'fromUserId',
                'toUserId',
                'message',
                'createdAt',
                'readStatus',
                'ROW_NUMBER() OVER (PARTITION BY 
                    LEAST(fromUserId, toUserId), 
                    GREATEST(fromUserId, toUserId) 
                    ORDER BY createdAt DESC) as rn'
            ])
                ->where(function ($query) use ($userId) {
                    $query->where('fromUserId', $userId)
                        ->whereOr('toUserId', $userId);
                })
                ->where('type', 1)
                ->buildSql();

            // 主查询获取最新消息并关联用户信息
            $notifications = $notificationModel->table($subQuery . ' n')
                ->where('n.rn', 1)
                ->join('rc_user u1', 'u1.id = n.fromUserId')
                ->join('rc_user u2', 'u2.id = n.toUserId')
                ->field([
                    'n.*',
                    'u1.userName as fromUserName',
                    'u1.nickName as fromNickName',
                    'u2.userName as toUserName',
                    'u2.nickName as toNickName'
                ])
                ->order('n.createdAt', 'desc')
                ->limit($offset, $pagesize)
                ->select()
                ->toArray();

            return json(['code' => 200, 'message' => '获取成功', 'data' => $notifications]);
        }
    }

    public function getUsers()
    {
        if (Session::get('r_user') == null) {
            return json(['code' => 400, 'message' => '请先登录']);
        }

        if (Request::isPost()) {
            $data = Request::post();
            $search = $data['search'] ?? '';
            $userModel = new UserModel();
            $users = $userModel
                ->where('nickName', 'like', '%' . $search . '%')
                ->field('id, nickName')
                ->limit(10)
                ->select();

            return json(['code' => 200, 'message' => '获取成功', 'data' => $users]);
        }
    }

    public function getNotificationDetail()
    {
        if (Session::get('r_user') == null) {
            return json(['code' => 400, 'message' => '请先登录']);
        }

        if (Request::isPost()) {
            $data = Request::post();
            $id = $data['id'] ?? 0;
            $time = $data['time'] ?? date('Y-m-d H:i:s');
            $notificationModel = new NotificationModel();
            $userId = Session::get('r_user')->id;

            $notifications = $notificationModel
                ->where(function ($query) use ($id, $userId) {
                    $query->where('fromUserId', $id)
                        ->where('toUserId', $userId)
                        ->whereOr(function ($query) use ($id, $userId) {
                            $query->where('fromUserId', $userId)
                                ->where('toUserId', $id);
                        });
                })
                ->where('createdAt', '<', $time)
                ->order('createdAt', 'desc')
                ->limit(10)
                ->select();

            // 将选择的发送给我的消息标记为已读
            $hasUnreadMessages = false;
            foreach ($notifications as $notification) {
                if ($notification->toUserId == $userId && $notification->readStatus == 0) {
                    $notification->readStatus = 1;
                    $notification->save();
                    $hasUnreadMessages = true;

                    // 发送已读消息通知
                    $webSocketServer = \app\websocket\WebSocketServer::getInstance();
                    $webSocketServer->sendToUser($notification->fromUserId, 'read_message', [
                        'notificationId' => $notification->id,
                        'toUserId' => $notification->toUserId
                    ]);
                }
            }

            if ($hasUnreadMessages) {
                // 发送新消息时
                $webSocketServer = \app\websocket\WebSocketServer::getInstance();

                // 更新未读消息数
                $webSocketServer->sendToUser($userId, 'unread_count', [
                    'count' => $notificationModel->where('toUserId', $userId)->where('readStatus', 0)->count()
                ]);
            }

            return json(['code' => 200, 'message' => '获取成功', 'data' => $notifications]);
        }
    }

    public function sendMessage()
    {
        if (Session::get('r_user') == null) {
            return json(['code' => 400, 'message' => '请先登录']);
        }

        if (Request::isPost()) {
            $data = Request::post();
            $toUserId = $data['id'] ?? 0;
            $message = $data['content'] ?? '';
            $fromUserId = Session::get('r_user')->id;

            if ($message == '') {
                return json(['code' => 400, 'message' => '参数错误']);
            }

            try {
                $notificationModel = new NotificationModel();
                $notification = $notificationModel->create([
                    'fromUserId' => $fromUserId,
                    'toUserId' => $toUserId,
                    'type' => 1,
                    'message' => $message,
                    'readStatus' => 0,
                ]);

                // 获取发送者信息
                $userModel = new UserModel();
                $fromUser = $userModel->where('id', $fromUserId)->find();
                $fromName = $fromUser->nickName ?? $fromUser->userName;
                $toUser = $userModel->where('id', $toUserId)->find();
                $toName = $toUser->nickName ?? $toUser->userName;

                // 发送新消息时
                $webSocketServer = \app\websocket\WebSocketServer::getInstance();

                // 发送新消息通知
                $webSocketServer->sendToUser($toUserId, 'new_message', [
                    'message' => $message,
                    'notificationId' => $notification->id,
                    'createdAt' => $notification->createdAt,
                    'fromUserId' => $fromUserId,
                    'toUserId' => $toUserId,
                    'fromUserName' => $fromName,
                    'toUserName' => $toName
                ]);

                $webSocketServer->sendToUser($fromUserId, 'update_message_list', [
                    'message' => $message,
                    'notificationId' => $notification->id,
                    'createdAt' => $notification->createdAt,
                    'fromUserId' => $fromUserId,
                    'toUserId' => $toUserId,
                    'fromUserName' => $fromName,
                    'toUserName' => $toName
                ]);

                // 更新未读消息数
                $webSocketServer->sendToUser($toUserId, 'unread_count', [
                    'count' => $notificationModel->where('toUserId', $toUserId)->where('readStatus', 0)->count()
                ]);

                $webSocketServer->sendToUser($fromUserId, 'unread_count', [
                    'count' => $notificationModel->where('toUserId', $fromUserId)->where('readStatus', 0)->count()
                ]);

                return json(['code' => 200, 'message' => '发送成功', 'data' => [
                    'notificationId' => $notification->id
                ]]);

            } catch (\Exception $e) {
                // 记录错误日志
                $logFile = __DIR__ . '/../../runtime/log/message_error.log';
                $time = date('Y-m-d H:i:s');
                $error = "[$time] Error sending message from $fromUserId to $toUserId: " . $e->getMessage() . "\n";
                file_put_contents($logFile, $error, FILE_APPEND);

                return json(['code' => 500, 'message' => '发送失败：' . $e->getMessage()]);
            }
        }
    }

    public function getUnreadCount()
    {
        if (Session::get('r_user') == null) {
            return json(['code' => 400, 'message' => '请先登录']);
        }

        $notificationModel = new NotificationModel();
        $userId = Session::get('r_user')->id;
        
        // 获取当前用户的未读消息数
        $unreadCount = $notificationModel
            ->where('toUserId', $userId)
            ->where('readStatus', 0)
            ->count();

        return json(['code' => 200, 'message' => '获取成功', 'data' => $unreadCount]);
    }

    public function getLatestMessage()
    {
        if (Session::get('r_user') == null) {
            return json(['code' => 400, 'message' => '请先登录']);
        }

        $notificationModel = new NotificationModel();
        $userId = Session::get('r_user')->id;
        
        // 获取最新的一条未读消息
        $latestMessage = $notificationModel
            ->where('toUserId', $userId)
            ->where('readStatus', 0)
            ->order('id', 'desc')
            ->find();

        if ($latestMessage) {
            // 如果是用户发送的消息，获取发送者信息
            if ($latestMessage->fromUserId > 0) {
                $userModel = new UserModel();
                $fromUser = $userModel->where('id', $latestMessage->fromUserId)->find();
                if ($fromUser) {
                    $latestMessage->message = ($fromUser->nickName ?? $fromUser->userName) . ': ' . $latestMessage->message;
                }
            }
            return json(['code' => 200, 'message' => '获取成功', 'data' => $latestMessage]);
        }

        return json(['code' => 200, 'message' => '没有新消息', 'data' => null]);
    }

    public function getMessages()
    {
        if (Session::get('r_user') == null) {
            return json(['code' => 400, 'message' => '请先登录']);
        }

        $data = Request::post();
        $id = $data['id'] ?? 0;
        $page = $data['page'] ?? 1;
        $pageSize = $data['pageSize'] ?? 10;
        $time = $data['time'] ?? date('Y-m-d H:i:s');
        $userId = Session::get('r_user')->id;

        if ($id == 0) {
            return json(['code' => 200, 'message' => '获取成功', 'data' => []]);
        }

        $notificationModel = new NotificationModel();
        $messages = $notificationModel
            ->where(function ($query) use ($id, $userId) {
                $query->where('fromUserId', $id)
                    ->where('toUserId', $userId)
                    ->whereOr(function ($query) use ($id, $userId) {
                        $query->where('fromUserId', $userId)
                            ->where('toUserId', $id);
                    });
            })
            ->where('createdAt', '<', $time)
            ->order('createdAt', 'desc')
            ->limit(($page - 1) * $pageSize, $pageSize)
            ->select();

        return json(['code' => 200, 'message' => '获取成功', 'data' => $messages]);
    }

    public function getSeekList()
    {
        if (Session::get('r_user') == null) {
            return json(['code' => 400, 'message' => '请先登录']);
        }

        if (Request::isPost()) {
            $data = Request::post();
            $page = $data['page'] ?? 1;
            $pageSize = $data['pageSize'] ?? 10;
            
            $seekModel = new \app\media\model\MediaSeekModel();
            $seekUserModel = new \app\media\model\MediaSeekUserModel();
            
            $list = $seekModel
                // 获取求片记录的同求人数还有求片人的nickName，其中nickName是rc_user表中的nickName字段，保证rc_user中的id和rc_media_seek中的userId一致
                ->field('rc_media_seek.*, (SELECT COUNT(*) FROM rc_media_seek_user WHERE seekId = rc_media_seek.id) as seekCount, rc_user.nickName')
                ->join('rc_user', 'rc_user.id = rc_media_seek.userId')
                ->order('id', 'desc')
                ->page($page, $pageSize)
                ->select()
                ->each(function($item) use ($seekUserModel) {
                    // 检查当前用户是否已同求
                    $item['canSeek'] = !$seekUserModel->where([
                        'seekId' => $item['id'],
                        'userId' => Session::get('r_user')->id
                    ])->find();
                    return $item;
                });
            
            $total = $seekModel->count();
            
            return json(['code' => 200, 'message' => '获取成功', 'data' => [
                'list' => $list,
                'total' => $total
            ]]);
        }
    }

    public function addSeek()
    {
        if (Session::get('r_user') == null) {
            return json(['code' => 400, 'message' => '请先登录']);
        }

        if (Request::isPost()) {
            $data = Request::post();
            if (empty($data['title'])) {
                return json(['code' => 400, 'message' => '请输入影片名称']);
            }

            $seekModel = new \app\media\model\MediaSeekModel();
            $data['userId'] = Session::get('r_user')->id;
            
            if ($seekModel->createSeek($data)) {
                return json(['code' => 200, 'message' => '求片成功']);
            } else {
                return json(['code' => 500, 'message' => '求片失败']);
            }
        }
    }

    public function addSeekUser()
    {
        if (Session::get('r_user') == null) {
            return json(['code' => 400, 'message' => '请先登录']);
        }

        if (Request::isPost()) {
            $data = Request::post();
            if (empty($data['seekId'])) {
                return json(['code' => 400, 'message' => '参数错误']);
            }

            $seekUserModel = new \app\media\model\MediaSeekUserModel();
            
            // 检查是否已同求
            $exist = $seekUserModel->where([
                'seekId' => $data['seekId'],
                'userId' => Session::get('r_user')->id
            ])->find();
            
            if ($exist) {
                return json(['code' => 400, 'message' => '您已同求过该影片']);
            }
            
            if ($seekUserModel->addSeekUser($data['seekId'], Session::get('r_user')->id)) {
                return json(['code' => 200, 'message' => '同求成功']);
            } else {
                return json(['code' => 500, 'message' => '同求失败']);
            }
        }
    }

    public function seekDetail()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }
        
        $id = Request::param('id');
        $seekModel = new \app\media\model\MediaSeekModel();
        $seek = $seekModel->where('id', $id)->find();
        
        if (!$seek) {
            return redirect('/media/user/seek');
        }
        
        View::assign('seek', $seek);
        return view();
    }

    public function getRequestList()
    {
        if (Session::get('r_user') == null) {
            return json(['code' => 400, 'message' => '请先登录']);
        }

        if (Request::isPost()) {
            $data = Request::post();
            $page = $data['page'] ?? 1;
            $pageSize = $data['pageSize'] ?? 10;
            
            $requestModel = new RequestModel();
            
            // 获取当前用户的工单列表
            $list = $requestModel
                ->where('requestUserId', Session::get('r_user')->id)
                ->order('id', 'desc')
                ->page($page, $pageSize)
                ->select();
            
            // 获取总记录数
            $total = $requestModel
                ->where('requestUserId', Session::get('r_user')->id)
                ->count();
            
            return json([
                'code' => 200, 
                'message' => '获取成功', 
                'data' => [
                    'list' => $list,
                    'total' => $total
                ]
            ]);
        }
        
        return json(['code' => 400, 'message' => '请求方式错误']);
    }

    public function searchMoviePilot()
    {
        if (Session::get('r_user') == null) {
            return json(['code' => 400, 'message' => '请先登录']);
        }

        if (Request::isPost()) {
            $data = Request::post();
            $title = $data['title'] ?? '';

            if (Config::get('media.moviepilot.enabled') == false) {
                return json([
                    'code' => 403,
                    'message' => '功能未开启',
                    'data' => [
                        'canAutoDownload' => false
                    ]
                ]);
            }
            
            // 检查权限
            $seekModel = new \app\media\model\MediaSeekModel();
            $canAutoDownload = $seekModel->checkAutoDownloadPermission(Session::get('r_user')->id);
            
            if (!$canAutoDownload) {
                return json([
                    'code' => 403,
                    'message' => '权限不足',
                    'data' => [
                        'canAutoDownload' => false
                    ]
                ]);
            }

            if (empty($title)) {
                return json(['code' => 400, 'message' => '请输入影片名称']);
            }
            
            $moviePilot = new \app\media\service\MoviePilot();
            $results = $moviePilot->search($title);
            
            return json([
                'code' => 200,
                'message' => '搜索成功',
                'data' => [
                    'results' => $results,
                    'canAutoDownload' => true
                ]
            ]);
        }
    }

    public function addMoviePilotTask()
    {
        if (Session::get('r_user') == null) {
            return json(['code' => 400, 'message' => '请先登录']);
        }

        if (Config::get('media.moviepilot.enabled') == false) {
            return json(['code' => 401, 'message' => '功能未开启']);
        }

        if (Request::isPost()) {
            $data = Request::post();
            trace("接收到的请求数据: " . json_encode($data), 'info');
            
            $user = Session::get('r_user');
            $seekModel = new \app\media\model\MediaSeekModel();
            
            // 检查权限
            $canAutoDownload = $seekModel->checkAutoDownloadPermission($user->id);
            if (!$canAutoDownload) {
                return json(['code' => 400, 'message' => '您没有权限使用自动下载功能']);
            }
            
            // 创建求片记录并自动下载
            try {
                $moviePilot = new \app\media\service\MoviePilot();
                $downloadResult = $moviePilot->addDownloadTask([
                    'title' => $data['title'],
                    'torrentInfo' => $data['torrentInfo'],
                    'description' => $data['description'] ?? ''
                ]);
                
                if ($downloadResult['success']) {
                    // 创建求片记录
                    $seekData = [
                        'userId' => $user->id,
                        'title' => $data['title'],
                        'description' => $data['description'] ?? '',
                        'status' => 2, // 直接设置为下载中
                        'statusRemark' => '自动下载中',
                        'downloadId' => $downloadResult['download_id']
                    ];
                    
                    if ($seekModel->createSeek($seekData)) {
                        return json([
                            'code' => 200,
                            'message' => '已添加到下载队列',
                            'data' => ['download_id' => $downloadResult['download_id']]
                        ]);
                    }
                }
                
                return json(['code' => 400, 'message' => $downloadResult['message'] ?? '添加下载任务失败']);
                
            } catch (\Exception $e) {
                trace("添加下载任务失败: " . $e->getMessage(), 'error');
                return json(['code' => 500, 'message' => '系统错误，请稍后重试']);
            }
        }
    }

    public function getSeekDetail()
    {
        if (Session::get('r_user') == null) {
            return json(['code' => 400, 'message' => '请先登录']);
        }
        
        if (Request::isPost()) {
            $data = Request::post();
            $seekId = $data['seekId'] ?? 0;
            
            if (!$seekId) {
                return json(['code' => 400, 'message' => '参数错误']);
            }
            
            $seekModel = new \app\media\model\MediaSeekModel();
            $seek = $seekModel->with(['user', 'seekUsers.user'])
                ->where('id', $seekId)
                ->find();
            
            if (!$seek) {
                return json(['code' => 400, 'message' => '求片记录不存在']);
            }
            
            // 获取日志记录
            $seekLogModel = new \app\media\model\MediaSeekLogModel();
            $logs = $seekLogModel->where('seekId', $seekId)
                ->where('type', 'in', [1,2,3])
                ->order('createdAt', 'asc')
                ->select()
                ->toArray();
            
            // 如果有下载ID，获取下载进度
            if ($seek->downloadId && $seek->status == 2 && Config::get('media.moviepilot.enabled')) {
                $moviePilot = new \app\media\service\MoviePilot();
                $downloadTask = $moviePilot->getDownloadTask($seek->downloadId);
                if ($downloadTask) {
                    $seek->downloadProgress = $downloadTask['progress'];
                    $seek->downloadState = $downloadTask['state'];
                }
            }
            
            // 格式化数据
            $data = [
                'id' => $seek->id,
                'title' => $seek->title,
                'description' => $seek->description,
                'status' => $seek->status,
                'statusRemark' => $seek->statusRemark,
                'seekCount' => $seek->seekCount,
                'createdAt' => $seek->createdAt,
                'updatedAt' => $seek->updatedAt,
                'downloadId' => $seek->downloadId,
                'downloadProgress' => $seek->downloadProgress ?? 0,
                'downloadState' => $seek->downloadState ?? '',
                // 发起人信息
                'userName' => $seek->user->userName ?? '',
                'nickName' => $seek->user->nickName ?? '',
                // 同求用户列表
                'seekUsers' => array_map(function($seekUser) {
                    return [
                        'userId' => $seekUser['userId'],
                        'userName' => $seekUser['user']['userName'] ?? '',
                        'nickName' => $seekUser['user']['nickName'] ?? '',
                        'createdAt' => $seekUser['createdAt']
                    ];
                }, $seek->seekUsers->toArray()),
                // 状态变更日志
                'statusLogs' => array_map(function($log) {
                    return [
                        'type' => $log['type'],
                        'content' => $log['content'],
                        'createdAt' => $log['createdAt']
                    ];
                }, $logs)
            ];
            
            return json([
                'code' => 200,
                'message' => '获取成功',
                'data' => $data
            ]);
        }
        
        return json(['code' => 400, 'message' => '请求方式错误']);
    }

    /**
     * 判断两个IP是否在同一个城市
     * @param string $ip1 第一个IP
     * @param string $ip2 第二个IP
     * @return bool 是否在同一个城市
     */
    private function isInSameCity($ip1, $ip2)
    {
        try {
            // 使用 ip2region 获取 IP 地理位置信息
            $ip2regionFile = root_path() . 'public/static/media/ip2region.xdb';
            if (!file_exists($ip2regionFile)) {
                return false;
            }

            $searcher = new Ip2Region($ip2regionFile);
            
            // 获取两个IP的地理位置信息
            $location1 = $searcher->btreeSearch($ip1);
            $location2 = $searcher->btreeSearch($ip2);
            
            if (!$location1 || !$location2) {
                return false;
            }

            // 解析地理位置信息
            $city1 = explode('|', $location1['region'])[2] ?? '';
            $city2 = explode('|', $location2['region'])[2] ?? '';
            
            // 判断城市是否相同
            return $city1 && $city2 && $city1 === $city2;
        } catch (\Exception $e) {
            // 发生异常时记录日志
            trace("IP地理位置查询失败: " . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * 显示服务条款页面
     */
    public function terms()
    {
        if (Request::isGet()) {
            $sysConfigModel = new SysConfigModel();
            $terms = $sysConfigModel->where('key', 'userAgreement')->find();
            
            View::assign('content', $terms ? $terms['value'] : '');
            
            if (Request::param('ajax')) {
                // 如果是 AJAX 请求，只返回内容部分
                return $terms ? $terms['value'] : '';
            }
            return view();
        }
        return redirect('/media/user/login');
    }

    /**
     * 显示隐私政策页面
     */
    public function privacy()
    {
        if (Request::isGet()) {
            $sysConfigModel = new SysConfigModel();
            $privacy = $sysConfigModel->where('key', 'privacyPolicy')->find();
            
            View::assign('content', $privacy ? $privacy['value'] : '');
            
            if (Request::param('ajax')) {
                // 如果是 AJAX 请求，只返回内容部分
                return $privacy ? $privacy['value'] : '';
            }
            return view();
        }
        return redirect('/media/user/login');
    }
}
