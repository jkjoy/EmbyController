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

        View::assign('paymentMethods', \app\service\PaymentGateway::methods());
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
            $id = Request::get('id');
            if (!is_scalar($id) || !ctype_digit((string) $id)) return redirect('/finance/payRecord');
            $payRecord = $payRecordModel->where('id', $id)->find();
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
        return $this->paymentStatus(true);
    }

    public function checkPay()
    {
        return $this->paymentStatus(false);
    }

    private function paymentStatus(bool $repay)
    {
        if (!Request::isPost()) return json(['code' => 405, 'message' => '请使用 POST 请求'], 405);
        $user = Session::get('r_user');
        if (!$user) return json(['code' => 401, 'message' => '请先登录'], 401);
        return json(\app\service\PaymentService::result(fn() => app(\app\service\PaymentService::class)->check(
            (int) $user->id, Request::post('id'), $repay
        )));
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

