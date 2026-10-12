<?php

namespace app\media\controller;

use app\media\model\MediaHistoryModel;
use app\BaseController;
use app\media\model\EmbyDeviceModel;
use app\media\model\EmbyUserModel as EmbyUserModel;
use app\service\ExchangeCodes;
use app\media\model\FinanceRecordModel;
use app\media\model\SysConfigModel as SysConfigModel;
use mailer\Mailer;
use Symfony\Component\VarDumper\Cloner\Data;
use think\facade\Request;
use think\facade\Session;
use app\media\model\UserModel as UserModel;
use app\media\validate\Login as LoginValidate;
use app\media\validate\Register as RegisterValidate;
use think\facade\View;
use think\facade\Config;
use think\facade\Cache;
use think\facade\Db;


class Server extends BaseController
{
    private $lifetimecost = 999;
    private $lifetimeauthority = 101;

    public function index()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/user/login');
        }
        return view();
    }

    public function changeTo()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/user/login');
        }
        if (Session::get('r_user')->authority == 0) {
            $data = Request::get();
            if (isset($data['userId'])) {
                $userModel = new UserModel();
                $user = $userModel->where('id', $data['userId'])->find();
                if ($user) {
                    Session::set('r_user', $user);
                    return redirect('/user/index');
                }
            } else if (isset($data['UserId'])) {
                $userModel = new UserModel();
                $user = $userModel->where('id', $data['UserId'])->find();
                if ($user) {
                    Session::set('r_user', $user);
                    return redirect('/user/index');
                }
            }
        }
    }

    public function account()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/user/login');
        }
        View::assign('lifetimecost', $this->lifetimecost);
        View::assign('lifetimeauthority', $this->lifetimeauthority);
        $userModel = new UserModel();
        $userFromDatabase = $userModel->where('id', Session::get('r_user')->id)->find();
        $userFromDatabase['password'] = null;
        $embyUserModel = new EmbyUserModel();
        $embyUserFromDatabase = $embyUserModel->where('userId', Session::get('r_user')->id)->find();
        $userInfoArray = $embyUserFromDatabase ? json_decode(json_encode($embyUserFromDatabase->userInfo), true) : [];
        if (isset($userInfoArray['autoRenew'])) {
            $autoRenew = $userInfoArray['autoRenew'];
        } else {
            $autoRenew = 0;
        }
        if ($embyUserFromDatabase && $embyUserFromDatabase['embyId'] != null) {
            $embyId = $embyUserFromDatabase['embyId'];
            $activateTo = $embyUserFromDatabase['activateTo'];
            $url = Config::get('media.urlBase') . 'Users/' . $embyId . '?api_key=' . Config::get('media.apiKey');
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'accept: application/json'
            ]);
            $embyUserFromEmby = json_decode(curl_exec($ch));
        } else {
            $embyUserFromEmby = null;
            $activateTo = null;
        }
        View::assign('userFromDatabase', $userFromDatabase);
        View::assign('embyUserFromDatabase', $embyUserFromDatabase);
        View::assign('embyUserFromEmby', $embyUserFromEmby);
        View::assign('autoRenew', $autoRenew);
        View::assign('activateTo', $activateTo);
        return view();
    }

    public function changePassword()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/user/login');
        }
        if (Request::isPost()) {
            $data = Request::post();
            if (isset($data['password']) && $data['password'] != '') {
                $embyUserModel = new EmbyUserModel();
                $user = $embyUserModel->where('userId', Session::get('r_user')->id)->find();
                if (isset($user->embyId)) {
                    $url = Config::get('media.urlBase') . 'Users/' . $user->embyId . '/Password?api_key=' . Config::get('media.apiKey');
                    $data = [
                        'Id' => $user->embyId,
                        'NewPw' => $data['password'],
//                        'ResetPassword' => true
                    ];
                    $ch = curl_init($url);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'accept: application/json',
                        'Content-Type: application/json'
                    ]);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                    $response = curl_exec($ch);
                    if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200 || curl_getinfo($ch, CURLINFO_HTTP_CODE) == 204) {
                        return json(['code' => 200, 'message' => '修改成功']);
                    } else {
                        return json(['code' => 400, 'message' => $response]);
                    }
                } else {
                    return json(['code' => 400, 'message' => '请先创建Emby账号']);
                }
            } else {
                return json(['code' => 400, 'message' => '密码不能为空']);
            }
        } else if (Request::isGet()) {
            return view();
        }
    }

    public function create()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/user/login');
        }
        $embyUserModel = new EmbyUserModel();
        $user = $embyUserModel->where('userId', Session::get('r_user')->id)->find();
        if (isset($user->embyId)) {
            return redirect((string) url('/server/account'));
        }
        if (Request::isPost()) {
            $data = Request::post();
            $embyUserName = $data['embyUserName'];
            $url = Config::get('media.urlBase') . 'Users/New?api_key=' . Config::get('media.apiKey');
            $data = [
                'Name' => $embyUserName,
                'CopyFromUserId' => Config::get('media.UserTemplateId'),
                'UserCopyOptions' => [
                    'UserPolicy',
                    'UserConfiguration'
                ]
            ];
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'accept: application/json',
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            $response = curl_exec($ch);
            // 如果是400错误，说明用户名已存在
            if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 400) {
                return json(['code' => 400, 'message' => '用户名已存在']);
            } else {
                $embyUserId = json_decode($response, true)['Id'];
                $embyUserModel = new EmbyUserModel();
                $embyUserModel->save([
                    'userId' => Session::get('r_user')->id,
                    'embyId' => $embyUserId,
                ]);
                $embyUser = $embyUserId;

                $url = Config::get('media.urlBase') . 'Users/' . $embyUserId . '/Policy?api_key=' . Config::get('media.apiKey');
                $data = ['IsDisabled' => true];

                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'accept: */*',
                    'Content-Type: application/json'
                ]);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

                curl_exec($ch);

                Session::set('m_embyId', $embyUserId);

                return json(['code' => 200, 'message' => '创建成功']);
            }
        } else if (Request::isGet()) {
            return view();
        }
    }

    public function servers()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/user/login');
        }

        if (Cache::get('serverList')) {
            View::assign('serverList', Cache::get('serverList'));
            return view();
        }

        $serverList = [];
        $lineList = Config::get('media.lineList');
        foreach ($lineList as $line) {
            $url = $line['url'] . '/emby/System/Ping?api_key=' . Config::get('media.apiKey');
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'accept: */*'
            ]);
            $response = curl_exec($ch);
            if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200) {
                $status = 1;
            } else {
                $status = 0;
            }
            $serverList[] = [
                'name' => $line['name'],
                'url' => $line['url'],
                'status' => $status
            ];
        }

        // 将serverList保存到缓存中
        Cache::set('serverList', $serverList, 1200);

        View::assign('serverList', $serverList);

        return view();
    }

    public function session()
    {
        if (!Session::has('r_user')) {
            return json(['code' => 400, 'message' => '请先登录']);
        }
        if (Request::isPost()) {
            $embyUserModel = new EmbyUserModel();
            $user = $embyUserModel->where('userId', Session::get('r_user')->id)->find();
            if (isset($user->embyId)) {

                if (Cache::get('sessionList-' . Session::get('r_user')->id)) {
                    $sessionList = Cache::get('sessionList-' . Session::get('r_user')->id);
                    return json(['code' => 200, 'message' => '获取成功', 'data' => $sessionList]);
                }
                $url = Config::get('media.urlBase') . 'Sessions?api_key=' . Config::get('media.apiKey');
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'accept: application/json'
                ]);
                $response = curl_exec($ch);
                $allSessionList = json_decode($response, true);
                $sessionList = [];
                foreach ($allSessionList as $session) {
                    if (isset($session['UserId']) && $session['UserId'] == $user->embyId) {
                        $sessionList[] = $session;
                    }
                }

                Cache::set('sessionList-' . Session::get('r_user')->id, $sessionList, 10);
            } else {
                $sessionList = null;
            }

            return json(['code' => 200, 'message' => '获取成功', 'data' => $sessionList]);
        }
//        $embyUserModel = new EmbyUserModel();
//        $user = $embyUserModel->where('userId', Session::get('r_user')->id)->find();
//        if (isset($user->embyId)) {
//            $url = Config::get('media.urlBase') . 'Sessions?api_key=' . Config::get('media.apiKey');
//            $ch = curl_init($url);
//            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
//            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
//            curl_setopt($ch, CURLOPT_HTTPHEADER, [
//                'accept: application/json'
//            ]);
//            $response = curl_exec($ch);
//            $allSessionList = json_decode($response, true);
//            $sessionList = [];
//            foreach ($allSessionList as $session) {
//                if (isset($session['UserId']) && $session['UserId'] == $user->embyId) {
//                    $sessionList[] = $session;
//                }
//            }
//
//            View::assign('sessionList', $sessionList);
//        } else {
//            $sessionList = null;
//        }
//
//        View::assign('sessionList', $sessionList);
//        return view();
    }

    public function devices()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/user/login');
        }

        $sysConfigModel = new SysConfigModel();
        $sysConfig = $sysConfigModel->where('key', 'maxActiveDeviceCount')->find();
        if ($sysConfig) {
            $maxActiveDeviceCount = $sysConfig->value;
        } else {
            $maxActiveDeviceCount = 0;
        }

        // 获取白名单和黑名单配置
        $clientListConfig = $sysConfigModel->where('key', 'clientList')->find();
        $clientList = $clientListConfig ? json_decode($clientListConfig['value'], true) : [];

        $clientBlackListConfig = $sysConfigModel->where('key', 'clientBlackList')->find();
        $clientBlackList = $clientBlackListConfig ? json_decode($clientBlackListConfig['value'], true) : [];

        $embyUserModel = new EmbyUserModel();
        $user = $embyUserModel->where('userId', Session::get('r_user')->id)->find();

        if ($user) {
            $embyDeviceModel = new EmbyDeviceModel();
            $deviceList = $embyDeviceModel
                ->where('embyId', $user->embyId)
                ->where('deactivate', 'in', [0, null])
                ->order('lastUsedTime', 'desc')
                ->select();
        } else {
            $deviceList = null;
        }

        View::assign('maxActiveDeviceCount', $maxActiveDeviceCount);
        View::assign('deviceList', $deviceList);
        View::assign('clientList', $clientList);
        View::assign('clientBlackList', $clientBlackList);
        return view();
    }

    public function deletedevice()
    {
        if (!Session::has('r_user')) {
            return json(['code' => 400, 'message' => '请先登录']);
        }
        if (Request::isPost()) {
            $data = Request::post();
            $deviceId = $data['deviceId'];

            $embyUserModel = new EmbyUserModel();
            $embyUser = $embyUserModel->where('userId', Session::get('r_user')->id)->find();

            // Debugging output
            if (!$embyUser) {
                return json(['code' => 400, 'message' => '用户不存在', 'userId' => Session::get('r_user')->id]);
            }


            $embyDeviceModel = new EmbyDeviceModel();
            $device = $embyDeviceModel
                ->where('deviceId', $deviceId)
                ->where('deactivate', 'in', [0, null])
                ->where('embyId', $embyUser->embyId)
                ->find();

            if (!$device) {
                return json(['code' => 400, 'message' => '设备不存在或者你没有设备所有权', 'deviceId' => $deviceId]);
            }
            $url = Config::get('media.urlBase') . 'Devices/Delete?api_key=' . Config::get('media.apiKey');
            $data = [
                'Id' => $deviceId
            ];
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'accept: application/json',
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            $response = curl_exec($ch);
            if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200 || curl_getinfo($ch, CURLINFO_HTTP_CODE) == 204) {
                $embyDeviceModel
                    ->where('deviceId', $deviceId)
                    ->update([
                        'deactivate' => 1
                    ]);
                return json(['code' => 200, 'message' => '删除成功']);
            } else {
                return json(['code' => 400, 'message' => $response]);
            }
        }
    }

    public function getItemsByIds()
    {
        if (!Session::has('r_user')) {
            return json(['code' => 400, 'message' => '请先登录']);
        }
        if (Request::isPost()) {
            $data = Request::post();
            $ids = $data['ids'];
            $url = Config::get('media.urlBase') . 'Items?Ids=' . join(',', $ids) . '&EnableImages=true&&api_key=' . Config::get('media.apiKey');
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'accept: application/json'
            ]);
            $response = curl_exec($ch);
            return json(['code' => 200, 'message' => '获取成功', 'data' => json_decode($response, true)]);
        }
    }

    public function viewList()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/user/login');
        }
        $embyUserModel = new EmbyUserModel();
        $user = $embyUserModel->where('userId', Session::get('r_user')->id)->find();
        if (isset($user->embyId)) {
            $url = Config::get('media.urlBase') . 'Users/' . $user->embyId . '/Views?IncludeExternalContent=true&api_key=' . Config::get('media.apiKey');
//            $url = Config::get('media.urlBase') . 'Shows/NextUp?UserId=' . $user->embyId . '&api_key=' . Config::get('media.apiKey');
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'accept: application/json'
            ]);
            $response = curl_exec($ch);
            $viewList = json_decode($response, true);
            View::assign('viewList', $viewList);
        } else {
            $viewList = null;
        }
        View::assign('viewList', $viewList);
        return view();
    }

    public function setAutoRenew()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/user/login');
        }
        if (Request::isPost()) {
            $data = Request::post();
            $embyUserModel = new EmbyUserModel();
            $embyUser = $embyUserModel->where('userId', Session::get('r_user')->id)->find();
            $userInfoArray = json_decode(json_encode($embyUser->userInfo), true);
            $userInfoArray['autoRenew'] = $data['autoRenew'];
            $embyUser->userInfo = $userInfoArray;
            $embyUser->save();

            $financeRecordModel = new FinanceRecordModel();
            $financeRecordModel->save([
                'userId' => Session::get('r_user')->id,
                'action' => 5,
                'count' => $data['autoRenew'],
                'recordInfo' => [
                    'message' => '设置自动续期Emby账号状态为' . ($data['autoRenew']==1?'开启':'关闭')
                ]
            ]);

            sendTGMessage(Session::get('r_user')->id, '您的Emby账号自动续期状态已设置为 <strong>' . ($data['autoRenew']==1?'开启':'关闭') . '</strong>');

            return json(['code' => 200, 'message' => '设置成功']);
        }
    }

    public function activateEmbyUserByBalance()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/user/login');
        }
        if (Request::isPost()) {
            $userId = Session::get('r_user')->id;
            $embyUserModel = new EmbyUserModel();
            $embyUser = $embyUserModel->where('userId', $userId)->find();
            $embyUserId = $embyUser->embyId;

            // 原子扣费：仅当账号未封禁且余额充足时才扣减，按受影响行数判断，防止并发重复扣费/透支
            $deducted = (new UserModel())
                ->where('id', $userId)
                ->where('authority', '>=', 0)
                ->where('rCoin', '>=', 1)
                ->dec('rCoin', 1)
                ->update();
            if (!$deducted) {
                return json([
                    'code' => 400,
                    'message' => '余额不足'
                ]);
            }

            $url = Config::get('media.urlBase') . 'Users/' . $embyUserId . '/Policy?api_key=' . Config::get('media.apiKey');
            $profile = $this->getTmpUserProfile();
            $profile['IsDisabled'] = false;
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'accept: */*',
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($profile));
            $response = curl_exec($ch);
            if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200 || curl_getinfo($ch, CURLINFO_HTTP_CODE) == 204) {
                $activateTo = date('Y-m-d H:i:s', time() + 86400);
                $embyUser->activateTo = $activateTo;
                $embyUser->save();
                $financeRecordModel = new FinanceRecordModel();
                $financeRecordModel->save([
                    'userId' => $userId,
                    'action' => 3,
                    'count' => 1,
                    'recordInfo' => [
                        'message' => '使用余额激活Emby账号'
                    ]
                ]);

                sendTGMessage($userId, '您的Emby账号已激活');

                // 更新Session（重新读取最新余额）
                $user = (new UserModel())->where('id', $userId)->find();
                $r_user = Session::get('r_user');
                $r_user->rCoin = $user->rCoin;
                Session::set('r_user', $r_user);
                return json([
                    'code' => 200,
                    'message' => '激活成功'
                ]);
            } else {
                // Emby 激活失败，退回已扣除的余额
                (new UserModel())->where('id', $userId)->inc('rCoin', 1)->update();
                return json([
                    'code' => 400,
                    'message' => $response
                ]);
            }
        }
    }


    public function activateEmbyUserByCode()
    {
        return $this->redeemExchangeCode([1]);
    }

    public function continueSubscribeEmbyUserByBalance()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/user/login');
        }
        if (Request::isPost()) {
            $userId = Session::get('r_user')->id;

            Db::startTrans();
            try {
                // 悲观锁读取，串行化并发续期，保证扣费与到期时间累加一致
                $user = (new UserModel())->where('id', $userId)->lock(true)->find();
                $embyUser = (new EmbyUserModel())->where('userId', $userId)->lock(true)->find();

                $activateTo = $embyUser['activateTo'];
                if ($activateTo == null) {
                    Db::rollback();
                    return json([
                        'code' => 400,
                        'message' => 'LifeTime用户无需续期'
                    ]);
                }
                if ($user->rCoin < 10) {
                    Db::rollback();
                    return json([
                        'code' => 400,
                        'message' => '余额不足'
                    ]);
                }

                if (strtotime($activateTo) > time()) {
                    $activateTo = date('Y-m-d H:i:s', strtotime($activateTo) + 2592000);
                } else {
                    $activateTo = date('Y-m-d H:i:s', time() + 2592000);
                }
                $embyUser->activateTo = $activateTo;
                $embyUser->save();
                $user->rCoin = $user->rCoin - 10;
                $user->save();
                $financeRecordModel = new FinanceRecordModel();
                $financeRecordModel->save([
                    'userId' => $userId,
                    'action' => 3,
                    'count' => 10,
                    'recordInfo' => [
                        'message' => '使用余额续期Emby账号'
                    ]
                ]);
                Db::commit();
            } catch (\Exception $e) {
                Db::rollback();
                return json([
                    'code' => 400,
                    'message' => '续期失败，请稍后重试'
                ]);
            }

            sendTGMessage($userId, '您的Emby账号已续期至 <strong>' . $activateTo . '</strong>');
            // 更新Session
            $r_user = Session::get('r_user');
            $r_user->rCoin = $user->rCoin;
            Session::set('r_user', $r_user);
            return json([
                'code' => 200,
                'message' => '续期成功'
            ]);
        }
    }

    public function continueSubscribeEmbyUserByCode()
    {
        return $this->redeemExchangeCode([2, 3]);
    }

    public function continueSubscribeEmbyUserToLifetimeByRCoin()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/user/login');
        }
        if (Request::isPost()) {
            $userId = Session::get('r_user')->id;

            Db::startTrans();
            try {
                $user = (new UserModel())->where('id', $userId)->lock(true)->find();
                if ($user->authority != 0 && $user->authority < $this->lifetimeauthority) {
                    Db::rollback();
                    return json([
                        'code' => 400,
                        'message' => '您没有权限'
                    ]);
                }
                $embyUser = (new EmbyUserModel())->where('userId', $userId)->lock(true)->find();
                if ($embyUser->activateTo == null) {
                    Db::rollback();
                    return json([
                        'code' => 400,
                        'message' => 'LifeTime用户无需续期'
                    ]);
                }
                if ($embyUser->activateTo < date('Y-m-d H:i:s', time())) {
                    Db::rollback();
                    return json([
                        'code' => 400,
                        'message' => '用户已过期，请先激活至未过期'
                    ]);
                }
                if ($user->rCoin < $this->lifetimecost) {
                    Db::rollback();
                    return json([
                        'code' => 400,
                        'message' => '余额不足'
                    ]);
                }

                $embyUser->activateTo = null;
                $embyUser->save();
                $user->rCoin = $user->rCoin - $this->lifetimecost;
                $user->save();
                $financeRecordModel = new FinanceRecordModel();
                $financeRecordModel->save([
                    'userId' => $userId,
                    'action' => 3,
                    'count' => $this->lifetimecost,
                    'recordInfo' => [
                        'message' => '使用余额续期Emby账号至终身'
                    ]
                ]);
                Db::commit();
            } catch (\Exception $e) {
                Db::rollback();
                return json([
                    'code' => 400,
                    'message' => '续期失败，请稍后重试'
                ]);
            }

            sendTGMessage($userId, '您的Emby账号已续期至终身');
            $poems = [
                "明月松间照，清泉石上流。",
                "千里江陵一日还，弱水三千只取一瓢饮。",
                "落霞与孤鹜齐飞，秋水共长天一色。",
                "欲穷千里目，更上一层楼。",
                "寒山转苍翠，秋水日潺湲。",
                "疏影横斜水清浅，暗香浮动月黄昏。",
                "白云千载空悠悠，青枫浦上不胜愁。",
                "孤舟蓑笠翁，独钓寒江雪。",
                "天姥连天向天横，势拔五岳掩赤城。",
                "洞庭青草，近中秋，更无一点风色。"
            ];
            $randomPoem = $poems[array_rand($poems)];
            sendTGMessageToGroup($randomPoem . PHP_EOL . PHP_EOL . '🎉 恭喜 <strong>' . (Session::get('r_user')->nickName??Session::get('r_user')->userName) . '</strong> 获得' . Config::get('app.app_name') . ' Lifetime ！');
            // 更新Session
            $r_user = Session::get('r_user');
            $r_user->rCoin = $user->rCoin;
            Session::set('r_user', $r_user);
            return json([
                'code' => 200,
                'message' => '续期成功'
            ]);
        }
    }

    public function redeemCode()
    {
        return $this->redeemExchangeCode();
    }

    private function redeemExchangeCode(?array $allowedTypes = null)
    {
        if (!Request::isPost()) return json(['code' => 405, 'message' => '请使用POST请求']);
        $user = Session::get('r_user');
        $userId = is_array($user) || $user instanceof \ArrayAccess ? (int) ($user['id'] ?? 0) : (int) ($user->id ?? 0);
        if ($userId <= 0) return json(['code' => 401, 'message' => '请先登录']);
        try {
            $result = ExchangeCodes::redeem($userId, Request::post('code'), $allowedTypes);
        } catch (\DomainException $e) {
            return json(['code' => $e->getCode() ?: 400, 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            trace('兑换码处理失败', 'error');
            return json(['code' => 400, 'message' => '兑换失败，请稍后重试']);
        }

        // 兑换已提交，通知或Session故障不能把成功回应改成失败，避免用户误以为码未消费。
        try {
            if (is_array($user)) $user['rCoin'] = $result['rCoin'];
            else $user->rCoin = $result['rCoin'];
            Session::set('r_user', $user);
            if ($result['exchangeType'] === 4) {
                sendTGMessage($userId, '您已经成功兑换了 <strong>' . $result['exchangeCount'] . '</strong> ' . currencyNameHtml()
                    . '，当前余额为 <strong>' . $result['rCoin'] . '</strong>');
            } else {
                sendTGMessage($userId, '您的Emby账号已续期至 <strong>' . $result['activateTo'] . '</strong>');
            }
        } catch (\Throwable $e) {
            trace('兑换已成功，但通知或Session更新失败', 'warning');
        }
        $message = $result['exchangeType'] === 4
            ? '成功兑换' . $result['exchangeCount'] . currencyName()
            : '成功兑换会员时长' . $result['exchangeCount'] . ($result['exchangeType'] === 3 ? '个月' : '天');
        return json(array_merge(['code' => 200, 'message' => $message], $result));
    }

    public function exchangeCode()
    {
        return $this->redeemExchangeCode([4]);
    }

    public function crontab()
    {
        // 获取get参数
        $data = Request::get();
        // 判断是否有参数
        if (verifyCrontabKey($data['crontabkey'] ?? null)) {
            $actionCount = 0;
            $finishCount = 0;
            $errorCount = 0;
            $errorList = [];

            // 任务1: 刷新线路状态
            try {
                $actionCount++;
                $serverList = [];
                $lineList = Config::get('media.lineList');
                foreach ($lineList as $line) {
                    $url = $line['url'] . '/emby/System/Ping?api_key=' . Config::get('media.apiKey');
                    $ch = curl_init($url);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'accept: */*'
                    ]);
                    $response = curl_exec($ch);
                    if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200 && $response == 'Emby Server') {
                        $status = 1;
                    } else {
                        $status = 0;
                    }
                    $serverList[] = [
                        'name' => $line['name'],
                        'url' => $line['url'],
                        'status' => $status
                    ];
                }
                // 将serverList保存到缓存中
                Cache::set('serverList', $serverList, 600);
                $finishCount++;
            } catch (\Exception $e) {
                $errorCount++;
                $errorList[] = [
                    'action' => '刷新线路状态',
                    'message' => $e->getMessage(),
                    'line' => $e->getLine(),
                ];
            }

            // 任务2 生成播放日报
//            try{
//                // 如果有缓存
//                if (!Cache::get('playDailyReport-'.date('Y-m-d'))) {
//                    // 如果是晚上8点到8点十分
//                    if (date('H') == 20 && date('i') == 0) {
//                        $this->generatePlayDailyReport();
//                    }
//                }
//
//
//            } catch (\Exception $e) {
//                $errorCount++;
//                $errorList[] = [
//                    'action' => '生成播放日报',
//                    'message' => $e->getMessage(),
//                    'line' => $e->getLine(),
//                ];
//            }


            if ($actionCount == $finishCount) {
                return json([
                    'code' => 200,
                    'message' => '执行成功',
                    'finishCount' => $finishCount
                ]);
            } else if ($actionCount > $finishCount && $finishCount != 0) {
                return json([
                    'code' => 200,
                    'message' => '部分执行成功',
                    'errorCount' => $errorCount,
                    'errorList' => $errorList
                ]);
            } else {
                return json([
                    'code' => 400,
                    'message' => '执行失败',
                    'errorCount' => $errorCount,
                    'errorList' => $errorList
                ]);
            }
        } else {
            return json([
                'code' => 400,
                'message' => '无效的key'
            ]);
        }
    }
    public function resolvePayment()
    {
        return json(['code' => 410, 'message' => '旧支付回调已停用'], 410);
    }

    public function paymentNotify()
    {
        if (!Request::isPost()) return json(['code' => 'FAIL', 'message' => '请使用 POST 回调'], 405);
        $method = Request::get('method');
        if (!is_string($method) || !in_array($method, ['alipay', 'wxpay'], true)) return json(['code' => 'FAIL', 'message' => '无效的支付渠道'], 400);
        try {
            app(\app\service\PaymentService::class)->callback($method, request());
            return app(\app\service\PaymentGateway::class)->success($method);
        } catch (\Throwable $e) {
            \think\facade\Log::error('支付回调验证或记账失败');
            return json(['code' => 'FAIL', 'message' => '支付通知验证或记账失败'], 400);
        }
    }

    public function pay()
    {
        if (!Request::isPost()) return json(['code' => 405, 'message' => '请使用 POST 请求'], 405);
        $user = Session::get('r_user');
        if (!$user) return json(['code' => 401, 'message' => '请先登录'], 401);
        $data = Request::post();
        return json(\app\service\PaymentService::result(fn() => app(\app\service\PaymentService::class)->create(
            (int) $user->id, $data['money'] ?? null, $data['method'] ?? null, getRealIp()
        )));
    }

    public function getTmpUserProfile()
    {
        $embyId = Config::get('media.UserTemplateId');
        $url = Config::get('media.urlBase') . 'Users/' . $embyId . '?api_key=' . Config::get('media.apiKey');
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'accept: application/json'
        ]);
        $response = curl_exec($ch);

        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200) {
            $userFromEmby = json_decode($response, true);
            if (isset($userFromEmby['Policy'])) {
                return $userFromEmby['Policy'];
            }
        }
        return null;
    }

    // 添加到白名单
    public function addToWhitelist()
    {
        if (!Session::has('r_user')) {
            return json(['code' => 400, 'message' => '请先登录']);
        }

        // 客户端白名单为全局访问控制配置，仅管理员可修改
        if (Session::get('r_user')->authority != 0) {
            return json(['code' => 403, 'message' => '无权限']);
        }

        if (Request::isPost()) {
            $data = Request::post();
            $deviceId = $data['deviceId'];

            $embyDeviceModel = new EmbyDeviceModel();
            $device = $embyDeviceModel->where('deviceId', $deviceId)->find();

            if (!$device) {
                return json(['code' => 400, 'message' => '设备不存在']);
            }

            $sysConfigModel = new SysConfigModel();
            $clientListConfig = $sysConfigModel->where('key', 'clientList')->find();
            $clientList = $clientListConfig ? json_decode($clientListConfig['value'], true) : [];

            // 检查是否已在白名单中
            if (in_array($deviceId, $clientList)) {
                return json(['code' => 400, 'message' => '该设备已在白名单中']);
            }

            // 添加到白名单
            $clientList[] = $deviceId;

            if ($clientListConfig) {
                $clientListConfig->value = json_encode($clientList);
                $clientListConfig->save();
            } else {
                $sysConfigModel->save([
                    'key' => 'clientList',
                    'value' => json_encode($clientList)
                ]);
            }

            return json(['code' => 200, 'message' => '添加成功']);
        }
    }

    // 添加到黑名单
    public function addToBlacklist()
    {
        if (!Session::has('r_user')) {
            return json(['code' => 400, 'message' => '请先登录']);
        }

        // 客户端黑名单为全局访问控制配置，仅管理员可修改
        if (Session::get('r_user')->authority != 0) {
            return json(['code' => 403, 'message' => '无权限']);
        }

        if (Request::isPost()) {
            $data = Request::post();
            $deviceId = $data['deviceId'];

            $embyDeviceModel = new EmbyDeviceModel();
            $device = $embyDeviceModel->where('deviceId', $deviceId)->find();

            if (!$device) {
                return json(['code' => 400, 'message' => '设备不存在']);
            }

            $sysConfigModel = new SysConfigModel();
            $clientBlackListConfig = $sysConfigModel->where('key', 'clientBlackList')->find();
            $clientBlackList = $clientBlackListConfig ? json_decode($clientBlackListConfig['value'], true) : [];

            // 检查是否已在黑名单中
            if (in_array($deviceId, $clientBlackList)) {
                return json(['code' => 400, 'message' => '该设备已在黑名单中']);
            }

            // 添加到黑名单
            $clientBlackList[] = $deviceId;

            if ($clientBlackListConfig) {
                $clientBlackListConfig->value = json_encode($clientBlackList);
                $clientBlackListConfig->save();
            } else {
                $sysConfigModel->save([
                    'key' => 'clientBlackList',
                    'value' => json_encode($clientBlackList)
                ]);
            }

            return json(['code' => 200, 'message' => '添加成功']);
        }
    }

    // 从名单中移除
    public function removeFromList()
    {
        if (!Session::has('r_user')) {
            return json(['code' => 400, 'message' => '请先登录']);
        }

        // 客户端名单为全局访问控制配置，仅管理员可修改
        if (Session::get('r_user')->authority != 0) {
            return json(['code' => 403, 'message' => '无权限']);
        }

        if (Request::isPost()) {
            $data = Request::post();
            $deviceId = $data['deviceId'];
            $listType = $data['listType'];

            $sysConfigModel = new SysConfigModel();

            if ($listType === 'whitelist') {
                $configKey = 'clientList';
            } else if ($listType === 'blacklist') {
                $configKey = 'clientBlackList';
            } else {
                return json(['code' => 400, 'message' => '无效的列表类型']);
            }

            $listConfig = $sysConfigModel->where('key', $configKey)->find();
            if (!$listConfig) {
                return json(['code' => 400, 'message' => '列表不存在']);
            }

            $list = json_decode($listConfig['value'], true);

            // 从列表中移除设备
            $list = array_filter($list, function($item) use ($deviceId) {
                return $item !== $deviceId;
            });

            $listConfig->value = json_encode(array_values($list));
            $listConfig->save();

            return json(['code' => 200, 'message' => '移除成功']);
        }
    }

    private function generatePlayDailyReport() {
        try {
            // 获取24小时内的播放记录
            $startTime = date('Y-m-d H:i:s', strtotime('-24 hours'));

            $mediaHistoryModel = new MediaHistoryModel();
            $records = $mediaHistoryModel
                ->where('updatedAt', '>=', $startTime)
                ->select();

            if ($records->isEmpty()) {
                return '过去24小时没有播放记录';
            }

            // 用于存储每个影片/剧集的播放次数
            $movieStats = [];
            $seriesStats = [];

            foreach ($records as $record) {
                $historyInfo = json_decode(json_encode($record['historyInfo']), true);

                // 确定媒体标识和名称
                $isSeries = false;
                if (isset($historyInfo['item'])) {
                    if (isset($historyInfo['item']['SeriesName']) && isset($historyInfo['item']['SeriesId'])) {
                        // 这是一个剧集
                        $isSeries = true;
                        $mediaId = 'series_' . $historyInfo['item']['SeriesId'];
                        $mediaName = $historyInfo['item']['SeriesName'];
                        $mediaYear = isset($historyInfo['item']['ProductionYear']) ? $historyInfo['item']['ProductionYear'] : '';
                    } else {
                        // 这是一个电影
                        $mediaId = $record['mediaId'];
                        $mediaName = $record['mediaName'];
                        $mediaYear = $record['mediaYear'];
                    }
                } else {
                    // 兼容旧数据
                    $mediaId = $record['mediaId'];
                    $mediaName = $record['mediaName'];
                    $mediaYear = $record['mediaYear'];
                }

                if ($isSeries) {
                    if (!isset($seriesStats[$mediaId])) {
                        $seriesStats[$mediaId] = [
                            'id' => $mediaId,
                            'name' => $mediaName,
                            'year' => $mediaYear,
                            'count' => 0
                        ];
                    }
                    $seriesStats[$mediaId]['count']++;
                } else {
                    if (!isset($movieStats[$mediaId])) {
                        $movieStats[$mediaId] = [
                            'id' => $mediaId,
                            'name' => $mediaName,
                            'year' => $mediaYear,
                            'count' => 0
                        ];
                    }
                    $movieStats[$mediaId]['count']++;
                }

            }

            // 按播放次数排序
            uasort($seriesStats, function($a, $b) {
                return $b['count'] - $a['count'];
            });

            uasort($movieStats, function($a, $b) {
                return $b['count'] - $a['count'];
            });

            // 只取前10个
            $seriesStats = array_slice($seriesStats, 0, 10);
            $movieStats = array_slice($movieStats, 0, 10);

            // 构建回复消息
            $message = "📊 " . date('Y年m月d日',) . "日最热门影视排行榜：\n\n";

            $message .= "📺 电影\n";
            $rank = 1;
            foreach ($movieStats as $media) {
                $title = $media['name'];
                $year = $media['year'] ? "（{$media['year']}）" : '';
                $count = $media['count'];

                $message .= "{$rank}. {$title}{$year}\n";
                $message .= "   👥 {$count}次播放\n";
                $rank++;
            }

            $message .= "\n📺 剧集\n";
            $rank = 1;
            foreach ($seriesStats as $media) {
                $title = $media['name'];
                $year = $media['year'] ? "（{$media['year']}）" : '';
                $count = $media['count'];

                $message .= "{$rank}. {$title}{$year}\n";
                $message .= "   👥 {$count}次播放\n";
                $rank++;
            }

            // 发送消息到群组
            sendTGMessageToGroup($message);
            Cache::set('playDailyReport-'.date('Y-m-d'), $message, 86400);

            return $message;

        } catch (\Exception $e) {
            return '获取播放记录失败' . PHP_EOL.$e->getMessage();
        }
    }
}
