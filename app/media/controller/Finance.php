<?php

namespace app\media\controller;

use app\BaseController;
use app\media\model\FinanceRecordModel;
use app\media\model\PayRecordModel;
use app\media\model\SysConfigModel;
use think\facade\Request;
use think\facade\Session;
use app\media\model\UserModel as UserModel;
use app\media\validate\Login as LoginValidate;
use app\media\validate\Register as RegisterValidate;
use think\facade\View;
use think\facade\Config;
use think\facade\Cache;


class Finance extends BaseController
{
    public function index()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/user/login');
        }
        return view();
    }

    public function user()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/user/login');
        }

        $rate = 1;
        $sysConfigModel = new SysConfigModel();
        $rateConfig = $sysConfigModel->where('key', 'chargeRate')->find();
        if ($rateConfig) {
            $rate = $rateConfig['value'];
        }

        $userModel = new UserModel();
        $userFromDatabase = $userModel->where('id', Session::get('r_user')->id)->find();
        $userFromDatabase['password'] = null;

        View::assign('rate', $rate);

        View::assign('epay', Config::get('payment.epay'));
        View::assign('userFromDatabase', $userFromDatabase);
        return view();
    }

    public function record()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/user/login');
        }
        return view();
    }

    public function payRecord()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/user/login');
        }
        return view();
    }

    public function payRecordDetail()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/user/login');
        }
        if (Request::isGet()) {
            $payRecordModel = new PayRecordModel();
            $data = Request::param();
            $payRecord = $payRecordModel->where('id', $data['id'])->find();
            if ($payRecord == null) {
                return redirect('/finance/payRecord');
            }
            if ($payRecord['userId'] != Session::get('r_user')->id) {
                return redirect('/finance/payRecord');
            }
            View::assign('payRecord', $payRecord);
            return view();
        }
    }

    public function rePay()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/user/login');
        }
        if (Request::isPost()) {
            $payRecordModel = new PayRecordModel();
            $data = Request::param();
            $payRecord = $payRecordModel->where('id', $data['id'])->find();
            if ($payRecord == null) {
                return json(['code' => 400, 'message' => '订单不存在']);
            }
            if ($payRecord['userId'] != Session::get('r_user')->id) {
                return json(['code' => 400, 'message' => '订单不存在']);
            }
            if ($payRecord['type'] == 2) {
                return json(['code' => 400, 'message' => '订单已支付']);
            }

            // 如果是订单5分钟内创建
            if (time() - strtotime($payRecord['createdAt']) < 300) {
                return json(['code' => 400, 'message' => '订单创建时间小于5分钟，请等待系统队列任务完成后再试']);
            }

            if (time() - strtotime($payRecord['createdAt']) > 10800) {
                return json(['code' => 400, 'message' => '订单创建时间大于3小时，请重新发起支付']);
            }

            $url = Config::get('payment.epay.urlBase') . 'api.php?act=order&pid=' . Config::get('payment.epay.id') . '&key=' . Config::get('payment.epay.key') . '&out_trade_no=' . $payRecord['tradeNo'];
            $respond = getHttpResponse($url);
            $respond = json_decode($respond, true);
            if ($respond['code'] == 1 && $respond['status'] == 1) {
                $payRecord->type = 2;
                $payRecord->save();

                $userModel = new UserModel();
                $user = $userModel->where('id', $payRecord['userId'])->find();
                $user->rCoin = $user->rCoin + $payRecord['money']*2;
                $user->save();

                $financeRecordModel = new FinanceRecordModel();
                $financeRecordModel->save([
                    'userId' => $payRecord['userId'],
                    'action' => 1,
                    'count' => $payRecord['money'],
                    'recordInfo' => [
                        'message' => '订单(#' . $payRecord['tradeNo'] . ')用户手动补单支付成功，兑换成' . $payRecord['money'] . currencyName() . ' + ' . $payRecord['money'] . '赠送' . currencyName(),
                    ]
                ]);

                return json(['code' => 200, 'message' => '订单已支付']);
            } else {
                $payRecordInfoArray = json_decode(json_encode($payRecord['payRecordInfo']), true);
                if (isset($payRecordInfoArray['payUrl'])) {
                    return json(['code' => 200, 'message' => '订单未支付，请扫码支付', 'payUrl' => $payRecordInfoArray['payUrl']]);
                } else {
                    return json(['code' => 400, 'message' => '订单无法重新支付，请重新下单']);
                }
            }
        }
    }

    public function checkPay()
    {
        if (Session::get('r_user') == null) {
            return json(['code' => 400, 'message' => '请先登录']);
        }

        if (Request::isPost()) {
            $data = Request::post();
            $payRecordModel = new PayRecordModel();
            $payRecord = $payRecordModel->where('id', $data['id'])->find();
            if ($payRecord == null) {
                return json(['code' => 400, 'message' => '订单不存在']);
            }
            if ($payRecord['userId'] != Session::get('r_user')->id) {
                return json(['code' => 400, 'message' => '订单不存在']);
            }
            if ($payRecord['type'] == 2) {
                return json(['code' => 200, 'message' => '订单已支付']);
            }

            $url = Config::get('payment.epay.urlBase') . 'api.php?act=order&pid=' . Config::get('payment.epay.id') . '&key=' . Config::get('payment.epay.key') . '&out_trade_no=' . $payRecord['tradeNo'];
            $respond = getHttpResponse($url);
            $respond = json_decode($respond, true);
            if ($respond['code'] == 1 && $respond['status'] == 1) {
                $payRecord->type = 2;
                $payRecord->save();

                $userModel = new UserModel();
                $user = $userModel->where('id', $payRecord['userId'])->find();
                $user->rCoin = $user->rCoin + $payRecord['money']*2;
                $user->save();

                $financeRecordModel = new FinanceRecordModel();
                $financeRecordModel->save([
                    'userId' => $payRecord['userId'],
                    'action' => 1,
                    'count' => $payRecord['money'],
                    'recordInfo' => [
                        'message' => '订单(#' . $payRecord['tradeNo'] . ')用户手动补单支付成功，兑换成' . $payRecord['money'] . currencyName() . ' + ' . $payRecord['money'] . '赠送' . currencyName(),
                    ]
                ]);

                return json(['code' => 200, 'message' => '订单已支付']);
            } else {
                return json(['code' => 400, 'message' => '订单未支付']);
            }
        }
    }

    public function getRecordList()
    {
        if (Session::get('r_user') == null) {
            return json(['code' => 400, 'message' => '请先登录']);
        }

        if (Request::isPost()) {
            $data = Request::post();
            $page = $data['page'] ?? 1;
            $pageSize = $data['pageSize'] ?? 10;
            
            $financeRecordModel = new FinanceRecordModel();
            
            // 获取当前用户的记录
            $list = $financeRecordModel
                ->where('userId', Session::get('r_user')->id)
                ->order('id', 'desc')
                ->page($page, $pageSize)
                ->select()
                ->each(function($item) {
                    // 确保recordInfo是对象
                    if (is_string($item->recordInfo)) {
                        $item->recordInfo = json_decode($item->recordInfo);
                    }
                    return $item;
                });
            
            // 获取总记录数
            $total = $financeRecordModel
                ->where('userId', Session::get('r_user')->id)
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

    public function getPayRecordList()
    {
        if (Session::get('r_user') == null) {
            return json(['code' => 400, 'message' => '请先登录']);
        }

        if (Request::isPost()) {
            $data = Request::post();
            $page = $data['page'] ?? 1;
            $pageSize = $data['pageSize'] ?? 10;
            
            $payRecordModel = new PayRecordModel();
            
            // 获取当前用户的账单记录
            $list = $payRecordModel
                ->where('userId', Session::get('r_user')->id)
                ->order('id', 'desc')
                ->page($page, $pageSize)
                ->select()
                ->each(function($item) {
                    // 确保payRecordInfo是对象
                    if (is_string($item->payRecordInfo)) {
                        $item->payRecordInfo = json_decode($item->payRecordInfo);
                    }
                    return $item;
                });
            
            // 获取总记录数
            $total = $payRecordModel
                ->where('userId', Session::get('r_user')->id)
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
}

