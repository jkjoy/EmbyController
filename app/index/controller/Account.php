<?php

namespace app\index\controller;

use app\BaseController;
use app\index\model\FinanceRecordModel;
use app\index\model\UserModel;
use think\facade\Cache;
use think\facade\Request;
use think\facade\View;
use think\facade\Config;
use think\facade\Db;

class Account extends BaseController
{
    public function sign()
    {
        if (request()->isGet()) {
            $signkey = input('signkey', "", 'trim');
            $errMsg = '';
            if ($signkey && $signkey != '' && Cache::has('get_sign_' . $signkey)) {
                $signkey = Cache::get('get_sign_' . $signkey);
            } else {
                $signkey = '';
                $errMsg = '签到链接已失效'. $signkey;
            }
            View::assign('signkey', $signkey);
            View::assign('errMsg', $errMsg);
            View::assign('sitekey', Config::get('apiinfo.cloudflareTurnstile.noninteractive.sitekey'));
            return view();
        } else if (request()->isPost()) {
            $data = request()->post();
            $signkey = $data['signkey']??'';

            if ($signkey == '') {
                return json(['code' => 401, 'message' => '参数错误']);
            }

            if (!judgeCloudFlare('noninteractive', $data['token']??'')) {
                return json(['code' => 400, 'message' => '您今日已签到或者您的网络环境异常，请核对后再试']);
            }

            $userId = Cache::get('post_signkey_' . $signkey);
            if ($userId == '') {
                return json(['code' => 400, 'message' => '用户信息不存在，请重新核对']);
            }
            $userModel = new UserModel();
            $user = $userModel->where('id', $userId)->find();
            $userInfoArray = json_decode(json_encode($user['userInfo']), true);

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
                $score = mt_rand(10, 30) / 100;

                Db::startTrans();
                try {
                    // 悲观锁串行化，并在锁内统一校验“今日是否已签到”，防止并发或不同判定分支重复领取
                    $lockedUser = (new UserModel())->where('id', $userId)->lock(true)->find();
                    $lockedInfo = json_decode(json_encode($lockedUser['userInfo']), true);
                    if (isset($lockedInfo['lastSignTime']) && $lockedInfo['lastSignTime'] == date('Y-m-d')) {
                        Db::rollback();
                        return json(['code' => 400, 'message' => '您今日已签到']);
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

                sendTGMessage($userId, "签到成功！今日签到获取" . $score . "R币");
                return json(['code' => 200, 'message' => '签到成功！今日签到获取' . $score . 'R币']);
            } else {
                return json(['code' => 401, 'message' => '签到失败']);
            }
        }
    }
}
