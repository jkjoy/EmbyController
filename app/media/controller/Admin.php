<?php

namespace app\media\controller;

use app\BaseController;
use app\media\model\EmbyUserModel;
use app\media\model\FinanceRecordModel;
use app\media\model\RequestModel as RequestModel;
use app\media\model\SysConfigModel;
use think\facade\Request;
use think\facade\Session;
use app\media\model\UserModel as UserModel;
use think\facade\View;
use think\facade\Config;
use think\facade\Cache;
use think\facade\Db;
use app\media\model\ExchangeCodeModel;

class Admin extends BaseController
{

    public function index()
    {
        if (session('r_user') == null || session('r_user')['authority'] != 0) {
            return redirect((string) url('/media/user/index'));
        }
        return view();
    }

    public function admin()
    {
        if (session('r_user') == null || session('r_user')['authority'] != 0) {
            return redirect((string) url('/media/user/index'));
        }
        return redirect((string) url('/media/admin/index'));
    }

    public function request()
    {
        if (session('r_user') == null || session('r_user')['authority'] != 0) {
            return redirect((string) url('/media/user/index'));
        }
        $page = input('page', 1, 'intval');
        $pagesize = input('pagesize', 10, 'intval');
        $requestModel = new RequestModel();
        $requestModel = $requestModel
            ->order('rc_request.updatedAt', 'desc')
            ->order('type', 'asc')
            ->field('rc_request.*, u1.nickName as requestNickName, u1.userName as requestUserName, u2.nickName as replyNickName, u2.userName as replyUserName')
            ->join('rc_user u1', 'rc_request.requestUserId = u1.id', 'LEFT')
            ->join('rc_user u2', 'rc_request.replyUserId = u2.id', 'LEFT');
        $pageCount = ceil($requestModel->count() / $pagesize);
        $requestsList = $requestModel
            ->page($page, $pagesize)
            ->select();
        View::assign('page', $page);
        View::assign('pageCount', $pageCount);
        View::assign('requestsList', $requestsList);
        return view();
    }

    public function requestDetail()
    {
        if (session('r_user') == null || session('r_user')['authority'] != 0) {
            return redirect((string) url('/media/user/index'));
        }
        $data = Request::get();
        $requestModel = new RequestModel();
        $request = $requestModel->where('id', $data['id'])->find();

        $request['message'] = json_decode($request['message'], true);

        $userModel = new UserModel();
        $requestUser = $userModel->where('id', $request['requestUserId'])->find();
        $requestUser->password = '';
        $replyUser = $userModel->where('id', $request['replyUserId'])->find();
        if ($replyUser) {
            $replyUser->password = '';
        }
        View::assign('requestUser', $requestUser);
        View::assign('replyUser', $replyUser);
        View::assign('request', $request);
        return view();
    }

    public function requestAddReply()
    {
        if (session('r_user') == null || session('r_user')['authority'] != 0) {
            return redirect((string) url('/media/user/index'));
        }
        if (Request::isPost()) {
            $data = Request::post();
            if ($data['content'] == '') {
                return json(['code' => 400, 'message' => '回复内容不能为空']);
            }
            $requestModel = new RequestModel();
            $request = $requestModel->where('id', $data['requestId'])->find();

            if (!$request) {
                return json(['code' => 400, 'message' => '工单不存在']);
            }

            if ($request->replyUserId != null && $request->replyUserId != Session::get('r_user')->id) {
                return json(['code' => 400, 'message' => '您无权回复该工单']);
            }

            $message = json_decode($request['message'], true);

            if ($request->replyUserId == null) {
                $request->replyUserId = Session::get('r_user')->id;
                $message[] = [
                    'role' => 'system',
                    'time' => date('Y-m-d H:i:s'),
                    'content' => '管理员(#' . Session::get('r_user')->id . ')已加入对话'
                ];
            }

            $message[] = [
                'role' => 'admin',
                'userId' => Session::get('r_user')->id,
                'time' => date('Y-m-d H:i:s'),
                'content' => $data['content'],
            ];
            $request->message = json_encode($message);
            $request->type = 2;
            $request->save();
            $title = json_decode(json_encode($request['requestInfo']), true)['title'];
            sendTGMessage($request->requestUserId, '您标题为 <strong>' . $title . '</strong> 的工单已经回复，回复内容如下：' . $data['content']);
            sendStationMessage($request->requestUserId, '您标题为 ' . $title . ' 的工单已经回复，回复内容如下：' . $data['content']);
            // 发送邮件
//            $userModel = new UserModel();
//            $user = $userModel->where('id', $request->requestUserId)->find();
//            if ($user && $user->email) {
//
//                $Message = $data['content'];
//                $Email = $user->email;
//                $SiteUrl = Config::get('app.app_host').'/media';;
//
//                $sysConfigModel = new SysConfigModel();
//                $requestAlreadyReply = $sysConfigModel->where('key', 'requestAlreadyReply')->find();
//                if ($requestAlreadyReply) {
//                    $requestAlreadyReply = $requestAlreadyReply['value'];
//                } else {
//                    $requestAlreadyReply = '您的工单已经回复，回复内容如下：<br>{Message}<br>请登录系统查看：<a href="{SiteUrl}">{SiteUrl}</a>';
//                }
//
//                $requestAlreadyReply = str_replace('{Message}', $Message, $requestAlreadyReply);
//                $requestAlreadyReply = str_replace('{Email}', $Email, $requestAlreadyReply);
//                $requestAlreadyReply = str_replace('{SiteUrl}', $SiteUrl, $requestAlreadyReply);
//
//                sendEmail($user->email, '您的工单已经回复', $requestAlreadyReply);
//            }

            $userModel = new UserModel();
            $user = $userModel->where('id', $request->requestUserId)->find();

            if ($user && $user->email) {

                $sendFlag = true;

                if ($user->userInfo) {
                    $userInfo = json_decode(json_encode($user->userInfo), true);
                    if (isset($userInfo['banEmail']) && $userInfo['banEmail'] == 1) {
                        $sendFlag = false;
                    }
                }

                if ($sendFlag) {
                    $Message = $data['content'];
                    $Email = $user->email;
                    $SiteUrl = Config::get('app.app_host').'/media';

                    $sysConfigModel = new SysConfigModel();
                    $requestAlreadyReply = $sysConfigModel->where('key', 'sysnotificiations')->find();
                    if ($requestAlreadyReply) {
                        $requestAlreadyReply = $requestAlreadyReply['value'];
                    } else {
                        $requestAlreadyReply = '您有一条新消息：{Message}';
                    }

                    $requestAlreadyReply = str_replace('{Message}', '您的工单已经回复，回复内容如下: '.$Message, $requestAlreadyReply);
                    $requestAlreadyReply = str_replace('{Email}', $Email, $requestAlreadyReply);
                    $requestAlreadyReply = str_replace('{SiteUrl}', $SiteUrl, $requestAlreadyReply);

                    \think\facade\Queue::push('app\api\job\SendMailMessage', [
                        'to' => $user->email,
                        'subject' => '工单回复通知',
                        'content' => $requestAlreadyReply,
                        'isHtml' => true
                    ], 'main');
                }
            }

            return json(['code' => 200, 'message' => '回复已提交', 'messageRecord' => json_encode($message)]);
        }
    }

    public function getThisReply()
    {
        if (session('r_user') == null || session('r_user')['authority'] != 0) {
            return redirect((string) url('/media/user/index'));
        }
        if (Request::isPost()) {
            $data = Request::post();
            $requestModel = new RequestModel();
            $request = $requestModel->where('id', $data['requestId'])->find();

            if (!$request) {
                return json(['code' => 400, 'message' => '工单不存在']);
            }

            if ($request->replyUserId != null && $request->replyUserId != Session::get('r_user')->id) {
                return json(['code' => 400, 'message' => '您无权操作该工单']);
            }

            $message = json_decode($request['message'], true);

            if ($request->replyUserId == null) {
                $request->replyUserId = Session::get('r_user')->id;
                $message[] = [
                    'role' => 'system',
                    'time' => date('Y-m-d H:i:s'),
                    'content' => '管理员(#' . Session::get('r_user')->id . ')已加入对话'
                ];

                $request->message = json_encode($message);
                $request->save();
                return json(['code' => 200, 'message' => '已加入对话', 'messageRecord' => json_encode($message)]);
            } else {
                return json(['code' => 400, 'message' => '您无权操作该工单']);
            }

        }
    }

    public function requestClose()
    {
        if (session('r_user') == null || session('r_user')['authority'] != 0) {
            return redirect((string) url('/media/user/index'));
        }
        if (Request::isPost()) {
            $data = Request::post();
            $requestModel = new RequestModel();
            $request = $requestModel->where('id', $data['requestId'])->find();

            if (!$request) {
                return json(['code' => 400, 'message' => '工单不存在']);
            }

            if ($request->replyUserId != Session::get('r_user')->id) {
                return json(['code' => 400, 'message' => '您无权对该工单进行关闭操作']);
            }

            if ($request->type > 0) {
                $message = json_decode($request['message'], true);
                $message[] = [
                    'role' => 'system',
                    'time' => date('Y-m-d H:i:s'),
                    'content' => '管理员(#' . Session::get('r_user')->id . ')关闭该工单',
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

    public function requestLeave()
    {
        if (session('r_user') == null || session('r_user')['authority'] != 0) {
            return redirect((string) url('/media/user/index'));
        }
        if (Request::isPost()) {
            $data = Request::post();
            $requestModel = new RequestModel();
            $request = $requestModel->where('id', $data['requestId'])->find();

            if (!$request) {
                return json(['code' => 400, 'message' => '工单不存在']);
            }

            if ($request->replyUserId != Session::get('r_user')->id) {
                return json(['code' => 400, 'message' => '您无权对该工单进行操作']);
            }

            if ($request->type > 0) {
                $message = json_decode($request['message'], true);
                $message[] = [
                    'role' => 'system',
                    'time' => date('Y-m-d H:i:s'),
                    'content' => '管理员(#' . Session::get('r_user')->id . ')已离开对话',
                ];
                $request->message = json_encode($message);
                $request->replyUserId = null;
                $request->save();
                return json(['code' => 200, 'message' => '已离开对话', 'messageRecord' => json_encode($message)]);
            } else {
                return json(['code' => 400, 'message' => '工单已关闭，无法操作']);
            }
        }
    }

    public function requestReward()
    {
        if (session('r_user') == null || session('r_user')['authority'] != 0) {
            return redirect((string) url('/media/user/index'));
        }
        if (Request::isPost()) {
            $data = Request::post();
            $requestModel = new RequestModel();
            $request = $requestModel->where('id', $data['requestId'])->find();
            $reward = $data['reward'];
            if (!$request) {
                return json(['code' => 400, 'message' => '工单不存在']);
            }
            // 校验奖励金额为正数，避免负数扣币或非法输入
            if (!is_numeric($reward) || $reward <= 0) {
                return json(['code' => 400, 'message' => '请输入正确的奖励金额']);
            }
            $reward = round((float)$reward, 2);

            if ($request->type > 0) {
                $message = json_decode($request['message'], true) ?: [];
                $message[] = [
                    'role' => 'system',
                    'time' => date('Y-m-d H:i:s'),
                    'content' => '管理员(#' . Session::get('r_user')->id . ')奖励给您了' . $reward . 'R币',
                ];

                Db::startTrans();
                try {
                    $request->message = json_encode($message);
                    $request->save();

                    $financeRecordModel = new FinanceRecordModel();
                    $financeRecordModel->save([
                        'userId' => $request->requestUserId,
                        'action' => 8,
                        'count' => $reward,
                        'recordInfo' => [
                            'message' => '管理员(#' . Session::get('r_user')->id . ')已在您的工单(#' . $data['requestId'] . ')奖励给您了' . $reward . 'R币',
                        ]
                    ]);

                    // 原子加币，避免并发读改写丢失更新
                    (new UserModel())->where('id', $request->requestUserId)->inc('rCoin', $reward)->update();

                    Db::commit();
                } catch (\Exception $e) {
                    Db::rollback();
                    return json(['code' => 400, 'message' => '奖励失败，请稍后重试']);
                }

                sendStationMessage($request->requestUserId, '管理员(#' . Session::get('r_user')->id . ')已在您的工单(#' . $data['requestId'] . ')奖励给您了' . $reward . 'R币');
                return json(['code' => 200, 'message' => '奖励成功', 'messageRecord' => json_encode($message)]);
            } else {
                return json(['code' => 400, 'message' => '工单已关闭，无法操作']);
            }
        }
    }

    public function seek()
    {
        if (session('r_user') == null || session('r_user')['authority'] != 0) {
            return redirect((string) url('/media/user/index'));
        }
        return view();
    }

    // 获取求片列表
    public function getSeekList()
    {
        if (session('r_user') == null || session('r_user')['authority'] != 0) {
            return redirect((string) url('/media/user/index'));
        }
        $data = Request::post();
        $page = $data['page'] ?? 1;
        $pageSize = $data['pageSize'] ?? 10;
        $status = $data['status'] ?? null;
        $search = $data['search'] ?? '';

        $seekModel = new \app\media\model\MediaSeekModel();
        $query = $seekModel->alias('s')
            ->join('user u', 'u.id = s.userId')
            ->field('s.*, u.userName, u.nickName');

        if ($status !== null && $status !== '') {
            $query = $query->where('s.status', $status);
        }

        if ($search) {
            $query = $query->where('s.title|u.userName|u.nickName', 'like', "%{$search}%");
        }

        $list = $query->order('s.id', 'desc')
            ->page($page, $pageSize)
            ->select();

        $total = $query->count();

        return json(['code' => 200, 'message' => '获取成功', 'data' => [
            'list' => $list,
            'total' => $total
        ]]);
    }

    // 更新求片状态
    public function updateSeekStatus()
    {
        if (session('r_user') == null || session('r_user')['authority'] != 0) {
            return redirect((string) url('/media/user/index'));
        }

        $data = Request::post();
        if (empty($data['id']) || !isset($data['status'])) {
            return json(['code' => 400, 'message' => '参数错误']);
        }

        $seekModel = new \app\media\model\MediaSeekModel();
        $seek = $seekModel->where('id', $data['id'])->find();
        if (!$seek) {
            return json(['code' => 404, 'message' => '求片记录不存在']);
        }

        $result = $seekModel->updateStatus($data['id'], $data['status'], $data['remark'] ?? '');
        if ($result) {
            // 发送通知给用户
//            sendStationMessage($seek->userId, "您的求片《{$seek->title}》状态已更新为：" . $seek->getStatusTextAttr(null, ['status' => $data['status']]));
            return json(['code' => 200, 'message' => '更新成功']);
        } else {
            return json(['code' => 500, 'message' => '更新失败']);
        }
    }

    // 用户列表页面
    public function userList()
    {
        $page = input('page', 1);
        $pageSize = input('pageSize', 10);
        $keyword = input('keyword', '');

        $userModel = new UserModel();
        $query = $userModel
            ->join('rc_telegram_user', 'rc_user.id = rc_telegram_user.userId', 'LEFT')
            ->field('rc_user.*, rc_telegram_user.telegramId');

        // 构建查询条件
        if (!empty($keyword)) {
            $query = $query->where(function ($query) use ($keyword) {
                $query->whereOr([
                    ['rc_user.id', 'like', "%{$keyword}%"],
                    ['userName', 'like', "%{$keyword}%"],
                    ['nickName', 'like', "%{$keyword}%"],
                    ['email', 'like', "%{$keyword}%"],
                    ['telegramId', 'like', "%{$keyword}%"],
                ]);
            });
        }

        // 先获取总数
        $total = $query->count();

        // 再获取当前页数据
        $list = $query->page($page, $pageSize)->select();

        return view('admin/user/list', [
            'list' => $list,
            'total' => $total,
            'currentPage' => (int)$page,
            'lastPage' => ceil($total / $pageSize),
            'keyword' => $keyword // 传递关键词到视图
        ]);
    }

    // 添加用户页面
    public function addUser()
    {
        if (request()->isPost()) {
            $data = input('post.');

            // 验证数据
            try {
                // 确保 authority 为整数
                $data['authority'] = intval($data['authority']);

                validate(\app\media\validate\AdminUpdate::class)
                    ->scene('add')
                    ->check($data);
            } catch (\Exception $e) {
                return json(['code' => 400, 'msg' => $e->getMessage()]);
            }

            // 密码加密
            $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);

            $userModel = new UserModel();
            if ($userModel->save($data)) {
                return json(['code' => 200, 'msg' => '添加成功']);
            }
            return json(['code' => 400, 'msg' => '添加失败']);
        }

        return View::fetch('admin/user/add');
    }

    // 编辑用户
    public function editUser()
    {
        $userModel = new UserModel();

        if (request()->isPost()) {
            $data = input('post.');

            try {
                // 确保 authority 为整数
                if (isset($data['authority'])) {
                    $data['authority'] = intval($data['authority']);
                }

                validate(\app\media\validate\AdminUpdate::class)
                    ->scene('edit')
                    ->check($data);
            } catch (\Exception $e) {
                return json(['code' => 400, 'msg' => $e->getMessage()]);
            }

            // 检查是否尝试修改其他管理员
            $targetUser = $userModel->where('id', $data['id'])->find();
            if ($targetUser['authority'] == 0 && session('r_user.id') != $data['id']) {
                return json(['code' => 401, 'msg' => '无权修改其他管理员信息']);
            }

            // 如果修改密码
            if (!empty($data['password'])) {
                $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
            } else {
                unset($data['password']);
            }

            if ($userModel->update($data)) {
                return json(['code' => 200, 'msg' => '更新成功']);
            }
            return json(['code' => 400, 'msg' => '更新失败']);
        }

        // 获取用户信息部分保持不变
        $id = input('get.id');
        if (empty($id)) {
            return json(['code' => 0, 'msg' => '参数错误']);
        }

        $user = $userModel->where('id', $id)->find();
        if (!$user) {
            return json(['code' => 0, 'msg' => '用户不存在']);
        }

        View::assign('searchedUser', $user);
        return View::fetch('admin/user/edit');
    }

    // 修改用户状态
    public function changeStatus()
    {
        if (session('r_user') == null || session('r_user')['authority'] != 0) {
            return json(['code' => 403, 'msg' => '无权操作']);
        }

        $id = input('id');
        $status = input('status');

        if (empty($id)) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }

        $userModel = new UserModel();

        // 检查是否存在且不是管理员
        $user = $userModel->where('id', $id)->find();
        if (!$user) {
            return json(['code' => 404, 'msg' => '用户不存在']);
        }
        if ($user['authority'] == 0) {
            return json(['code' => 403, 'msg' => '无法修改管理员状态']);
        }

        // 将状态更改为修改 authority
        // 注意：status是字符串 'true' 或 'false'，需要转换
        $status = $status === 'true' || $status === true;
        $authority = $status ? -1 : 1;  // 如果status为true则禁用(-1)，否则启用(1)

        try {
            $result = $userModel->where('id', $id)->update(['authority' => $authority]);
            if ($result !== false) {
                if ($authority === -1) {
                    // 检查有没有emby账号，有的话禁用
                    $embyUserModel = new EmbyUserModel();
                    $embyUser = $embyUserModel->where('userId', $id)->find();
                    if ($embyUser && $embyUser['embyId']) {
                        $embyId = $embyUser['embyId'];
                        $this->disableEmbyAccount($embyId);
                    }
                }
                return json(['code' => 200, 'msg' => '状态更新成功']);
            }
            // 记录错误信息
            trace("更新用户状态失败：用户ID={$id}, 新状态={$authority}", 'error');
            return json(['code' => 500, 'msg' => '状态更新失败']);
        } catch (\Exception $e) {
            // 记录异常信息
            trace("更新用户状态异常：" . $e->getMessage(), 'error');
            return json(['code' => 500, 'msg' => '状态更新失败：' . $e->getMessage()]);
        }
    }

    // 用户详情
    public function userDetail()
    {
        $id = input('id');
        $userModel = new UserModel();
        $user = $userModel->find($id);
        View::assign('user', $user);
        return View::fetch('admin/user/detail');
    }

    // 兑换码列表页面
    public function exchangeCodeList()
    {
        $page = input('page', 1);
        $pageSize = input('pageSize', 10);
        $keyword = input('keyword', '');

        $exchangeCodeModel = new ExchangeCodeModel();
        $query = $exchangeCodeModel;

        // 构建查询条件
        if (!empty($keyword)) {
            $query = $query->where(function ($query) use ($keyword) {
                $query->whereOr([
                    ['id', 'like', "%{$keyword}%"],
                    ['code', 'like', "%{$keyword}%"],
                    ['usedByUserId', 'like', "%{$keyword}%"]
                ]);
            });
        }

        // 先获取总数
        $total = $query->count();

        // 再获取当前页数据，并按创建时间倒序排序
        $list = $query->page($page, $pageSize)
            ->order('createdAt', 'desc')
            ->select()
            ->each(function($item) {
                // 解析 JSON 字段
                $item['codeInfo'] = json_decode(json_encode($item['codeInfo']), true);
                return $item;
            });

        return view('admin/exchangeCode/list', [
            'list' => $list,
            'total' => $total,
            'currentPage' => (int)$page,
            'lastPage' => ceil($total / $pageSize),
            'keyword' => $keyword
        ]);
    }

    // 生成随机兑换码
    private function generateCode($length = 16) {
        $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!#-';
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $code;
    }

    // 添加兑换码
    public function addExchangeCode()
    {
        if (request()->isPost()) {
            $data = input('post.');
            $mode = $data['mode'] ?? 'single';

            try {
                $baseData = [
                    'exchangeType' => $data['exchangeType'],
                    'exchangeCount' => $data['exchangeCount'],
                    'type' => 0, // 未使用
                    'codeInfo' => [
                        'remark' => $data['remark'] ?? ''
                    ]
                ];

                if ($mode === 'single') {
                    // 单个添加
                    $exchangeCodeModel = new ExchangeCodeModel();
                    $baseData['code'] = $this->generateCode();
                    if ($exchangeCodeModel->save($baseData)) {
                        return json(['code' => 200, 'msg' => '添加成功', 'data' => ['codes' => [$baseData['code']]]]);
                    }
                } else {
                    // 批量添加
                    $generateCount = min(100, max(1, intval($data['generateCount'])));
                    $codes = [];
                    $successCount = 0;

                    // 使用事务确保批量添加的原子性
                    $exchangeCodeModel = new ExchangeCodeModel();
                    $exchangeCodeModel->startTrans();

                    try {
                        for ($i = 0; $i < $generateCount; $i++) {
                            $newData = $baseData;
                            $newData['code'] = $this->generateCode();
                            // 每次创建新的模型实例
                            $model = new ExchangeCodeModel();
                            if ($model->save($newData)) {
                                $codes[] = $newData['code'];
                                $successCount++;
                            }
                        }

                        if ($successCount === $generateCount) {
                            $exchangeCodeModel->commit();
                            return json([
                                'code' => 200,
                                'msg' => "成功生成 {$successCount} 个兑换码",
                                'data' => ['codes' => $codes]
                            ]);
                        } else {
                            $exchangeCodeModel->rollback();
                            return json(['code' => 400, 'msg' => "部分兑换码生成失败"]);
                        }
                    } catch (\Exception $e) {
                        $exchangeCodeModel->rollback();
                        throw $e;
                    }
                }

                return json(['code' => 400, 'msg' => '添加失败']);
            } catch (\Exception $e) {
                return json(['code' => 400, 'msg' => $e->getMessage()]);
            }
        }

        return view('admin/exchangeCode/add');
    }

    // 禁用/启用兑换码
    public function changeExchangeCodeStatus()
    {
        if (session('r_user') == null || session('r_user')['authority'] != 0) {
            return json(['code' => 403, 'msg' => '无权操作']);
        }

        $id = input('id');
        $status = input('status');

        if (empty($id)) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }

        $exchangeCodeModel = new ExchangeCodeModel();

        // 检查是否存在
        $code = $exchangeCodeModel->where('id', $id)->find();
        if (!$code) {
            return json(['code' => 404, 'msg' => '兑换码不存在']);
        }

        // 如果已经被使用，不允许修改状态
        if ($code['type'] == 1) {
            return json(['code' => 400, 'msg' => '已使用的兑换码无法修改状态']);
        }

        // 修改状态
        $status = $status === 'true' || $status === true;
        $type = $status ? -1 : 0;  // true则禁用(-1)，false则启用(0)

        try {
            $result = $exchangeCodeModel->where('id', $id)->update(['type' => $type]);
            if ($result !== false) {
                return json(['code' => 200, 'msg' => '状态更新成功']);
            }
            return json(['code' => 500, 'msg' => '状态更新失败']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '状态更新失败：' . $e->getMessage()]);
        }
    }

    // 抽奖列表页面
    public function lotteryList()
    {
        if (session('r_user') == null || session('r_user')['authority'] != 0) {
            return redirect((string) url('/media/user/index'));
        }

        $page = input('page', 1);
        $pageSize = input('pageSize', 10);
        $keyword = input('keyword', '');

        $lotteryModel = new \app\api\model\LotteryModel();
        $query = $lotteryModel;

        // 构建查询条件
        if (!empty($keyword)) {
            $query = $query->where(function ($query) use ($keyword) {
                $query->whereOr([
                    ['id', 'like', "%{$keyword}%"],
                    ['title', 'like', "%{$keyword}%"],
                    ['description', 'like', "%{$keyword}%"]
                ]);
            });
        }

        // 获取总数
        $total = $query->count();
        $lastPage = ceil($total / $pageSize);

        // 获取当前页数据
        $list = $query->page($page, $pageSize)
            ->order('createTime', 'desc')
            ->select();

        $enableBot = true;
        if (!Config::get('telegram.botConfig.bots.randallanjie_bot.token') ||
            Config::get('telegram.botConfig.bots.randallanjie_bot.token') == '' ||
            Config::get('telegram.botConfig.bots.randallanjie_bot.token') == null ||
            Config::get('telegram.botConfig.bots.randallanjie_bot.token') == 'notgbot') {
            $enableBot = false;
        }

        return view('admin/lottery/list', [
            'list' => $list,
            'total' => $total,
            'currentPage' => (int)$page,
            'lastPage' => $lastPage,
            'keyword' => $keyword,
            'enableBot' => $enableBot
        ]);
    }

    // 添加抽奖页面
    public function addLottery()
    {
        if (session('r_user') == null || session('r_user')['authority'] != 0) {
            return redirect((string) url('/media/user/index'));
        }

        if (request()->isPost()) {
            $data = input('post.');

            // 验证数据
            $validate = new \app\media\validate\LotteryValidate();
            if (!$validate->scene('add')->check($data)) {
                return json(['code' => 400, 'msg' => $validate->getError()]);
            }

            try {
                // 处理prizes数据
                $prizes = json_decode($data['prizes'], true);

                // 处理开奖时间
                if (strtotime($data['drawTime']) === false || strtotime($data['drawTime']) <= time()) {
                    return json(['code' => 400, 'msg' => '开奖时间必须大于当前时间']);
                }

                // 创建抽奖
                $lotteryModel = new \app\api\model\LotteryModel();
                $lotteryData = [
                    'title' => $data['title'],
                    'description' => $data['description'],
                    'drawTime' => date('Y-m-d H:i:s', strtotime($data['drawTime'])),
                    'prizes' => $prizes,
                    'keywords' => $data['keywords'] ?? '',
                    'status' => $data['chatId']?1:0,
                    'chatId' => $data['chatId'] ?? null
                ];

//                echo json_encode($lotteryData);
//                die();

                if ($lotteryModel->save($lotteryData)) {
                    return json(['code' => 200, 'msg' => '添加成功']);
                }
                return json(['code' => 400, 'msg' => '添加失败']);

            } catch (\Exception $e) {
                return json(['code' => 400, 'msg' => $e->getMessage()]);
            }
        }

        return view('admin/lottery/add');
    }

    // 编辑抽奖页面
    public function editLottery()
    {
        if (session('r_user') == null || session('r_user')['authority'] != 0) {
            return redirect((string) url('/media/user/index'));
        }

        $id = input('id');
        $lotteryModel = new \app\api\model\LotteryModel();

        if (request()->isPost()) {
            $data = input('post.');

            // 验证数据
            $validate = new \app\media\validate\LotteryValidate();
            if (!$validate->scene('edit')->check($data)) {
                return json(['code' => 400, 'msg' => $validate->getError()]);
            }

            try {
                // 检查抽奖是否存在
                $lottery = $lotteryModel->find($id);
                if (!$lottery) {
                    return json(['code' => 404, 'msg' => '抽奖不存在']);
                }

                // 检查状态
                if ($lottery['status'] == 2) {
                    return json(['code' => 400, 'msg' => '已结束的抽奖不能编辑']);
                }

                // 处理prizes数据
                if (isset($data['prizes'])) {
                    $prizes = json_decode($data['prizes'], true);
                    if (!is_array($prizes) || empty($prizes)) {
                        return json(['code' => 400, 'msg' => '奖品数据格式错误']);
                    }
                } else {
                    return json(['code' => 400, 'msg' => '奖品数据不能为空']);
                }

                // 处理开奖时间
                $drawTime = strtotime($data['drawTime']);
                if ($drawTime === false || $drawTime <= time()) {
                    return json(['code' => 400, 'msg' => '开奖时间必须大于当前时间']);
                }

                // 更新数据
                $updateData = [
                    'title' => $data['title'],
                    'description' => $data['description'],
                    'drawTime' => date('Y-m-d H:i:s', $drawTime),
                    'keywords' => $data['keywords'] ?? '',
                    'prizes' => $prizes,
                    'chatId' => $data['chatId'] ?? null
                ];

                if ($lottery->save($updateData)) {
                    return json(['code' => 200, 'msg' => '更新成功']);
                }
                return json(['code' => 400, 'msg' => '更新失败']);

            } catch (\Exception $e) {
                return json(['code' => 400, 'msg' => $e->getMessage()]);
            }
        }

        $lottery = $lotteryModel->find($id);
        if (!$lottery) {
            return redirect((string) url('/media/admin/lotteryList'));
        }

        return view('admin/lottery/edit', ['lottery' => $lottery]);
    }

    // 修改抽奖状态
    public function changeLotteryStatus()
    {
        if (session('r_user') == null || session('r_user')['authority'] != 0) {
            return json(['code' => 403, 'msg' => '无权操作']);
        }

        $id = input('id');
        $status = input('status');

        if (empty($id)) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }

        $lotteryModel = new \app\api\model\LotteryModel();

        // 检查是否存在
        $lottery = $lotteryModel->find($id);
        if (!$lottery) {
            return json(['code' => 404, 'msg' => '抽奖不存在']);
        }

        // 如果抽奖已结束，不允许修改状态
        if ($lottery['status'] == 2) {
            return json(['code' => 400, 'msg' => '已结束的抽奖不能修改状态']);
        }

        // 修改状态
        $status = $status === 'true' || $status === true;
        $newStatus = $status ? -1 : 1;  // true则禁用(-1)，false则启用(1)

        try {
            if ($lottery->save(['status' => $newStatus])) {
                return json(['code' => 200, 'msg' => '状态更新成功']);
            }
            return json(['code' => 500, 'msg' => '状态更新失败']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '状态更新失败：' . $e->getMessage()]);
        }
    }

    // 查看抽奖参与者
    public function lotteryParticipants()
    {
        if (session('r_user') == null || session('r_user')['authority'] != 0) {
            return redirect((string) url('/media/user/index'));
        }

        $id = input('id');
        if (empty($id)) {
            return redirect((string) url('/media/admin/lotteryList'));
        }

        $participantModel = new \app\api\model\LotteryParticipantModel();
        $participants = $participantModel->where('lotteryId', $id)
            ->order('createTime', 'desc')
            ->select();

        return view('admin/lottery/participants', ['participants' => $participants]);
    }

    // 系统设置页面
    public function setting()
    {
        if (session('r_user') == null || session('r_user')['authority'] != 0) {
            return redirect((string) url('/media/user/index'));
        }

        if (request()->isPost()) {
            $data = input('post.');

            try {
                $sysConfigModel = new SysConfigModel();
                // 处理可能的 JSON 数据
                if (isset($data['clientList'])) {
                    $data['clientList'] = $data['clientList']; // 已经是 JSON 字符串了
                }
                if (isset($data['clientBlackList'])) {
                    $data['clientBlackList'] = $data['clientBlackList']; // 已经是 JSON 字符串了
                }

                // 遍历提交的设置并更新
                foreach ($data as $key => $value) {
                    // 查找是否存在该配置
                    $config = $sysConfigModel->where('key', $key)->find();

                    if ($config) {
                        // 更新已存在的配置
                        $config->value = $value;
                        $config->save();
                    } else {
                        // 添加新配置
                        $sysConfigModel->save([
                            'key' => $key,
                            'value' => $value,
                            'appName' => 'media',
                            'type' => 1,
                            'status' => 1
                        ]);
                    }
                }

                return json(['code' => 200, 'message' => '设置已更新']);
            } catch (\Exception $e) {
                return json(['code' => 400, 'message' => '更新失败：' . $e->getMessage()]);
            }
        } else if (request()->isGet()) {
            // 获取所有系统设置
            $sysConfigModel = new SysConfigModel();
            $configs = $sysConfigModel->select();

            // 将配置转换为关联数组
            $settings = [];
            foreach ($configs as $config) {
                $settings[$config['key']] = $config['value'];
            }

            View::assign('settings', $settings);
            return view('admin/setting');
        }
    }

    private function disableEmbyAccount($embyId) {
        $apiKey = MEDIA_CONFIG['apiKey'];
        $urlBase = MEDIA_CONFIG['urlBase'];

        $url = $urlBase . 'Users/' . $embyId . '/Policy?api_key=' . $apiKey;
        $data = ['IsDisabled' => true];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'accept: */*',
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (!($httpCode == 200 || $httpCode == 204)) {
            throw new \Exception("Failed to disable Emby account: $response");
        }
    }

    // 日志列表页面
    public function logs()
    {
        if (session('r_user') == null || session('r_user')['authority'] != 0) {
            return redirect((string) url('/media/user/index'));
        }

        // 获取runtime/log目录下的所有.log文件
        $logPath = root_path() . 'runtime/log/';
        $logFiles = glob($logPath . '*.log');
        
        // 格式化日志文件信息
        $logs = [];
        foreach ($logFiles as $file) {
            $logs[] = [
                'name' => basename($file),
                'path' => $file,
                'size' => format_bytes(filesize($file)),
                'modified' => date('Y-m-d H:i:s', filemtime($file))
            ];
        }

        // 按修改时间倒序排序
        usort($logs, function($a, $b) {
            return strtotime($b['modified']) - strtotime($a['modified']);
        });

        return view('admin/logs/index', ['logs' => $logs]);
    }

    // 查看日志内容
    public function viewLog()
    {
        if (session('r_user') == null || session('r_user')['authority'] != 0) {
            return redirect((string) url('/media/user/index'));
        }

        $filename = input('filename');
        $lines = input('lines', 100); // 默认显示最后100行
        $mode = input('mode', 'tail'); // tail或者head模式

        // 安全检查：确保文件在logs目录下
        $logPath = root_path() . 'runtime/log/';
        $filePath = $logPath . basename($filename);
        
        if (!file_exists($filePath) || !is_file($filePath)) {
            return json(['code' => 404, 'msg' => '日志文件不存在']);
        }

        if (pathinfo($filePath, PATHINFO_EXTENSION) !== 'log') {
            return json(['code' => 403, 'msg' => '只能查看日志文件']);
        }

        // 读取日志内容
        if ($mode === 'tail') {
            $content = $this->tailFile($filePath, $lines);
        } else {
            $content = $this->headFile($filePath, $lines);
        }

        if (request()->isAjax()) {
            return json(['code' => 200, 'data' => $content]);
        }

        return view('admin/logs/view', [
            'filename' => $filename,
            'content' => $content
        ]);
    }

    // 获取文件最后n行
    private function tailFile($file, $lines = 100)
    {
        $handle = fopen($file, "r");
        $linecounter = $lines;
        $pos = -2;
        $beginning = false;
        $text = [];

        while ($linecounter > 0) {
            $t = " ";
            while ($t != "\n") {
                if(fseek($handle, $pos, SEEK_END) == -1) {
                    $beginning = true;
                    break;
                }
                $t = fgetc($handle);
                $pos--;
            }
            
            if ($beginning) {
                rewind($handle);
            }
            
            $text[$lines-$linecounter] = fgets($handle);
            if ($beginning) break;
            $linecounter--;
        }
        fclose($handle);
        
        // 反转数组使最新的日志在前面
        return array_reverse($text);
    }

    // 获取文件前n行
    private function headFile($file, $lines = 100)
    {
        $handle = fopen($file, "r");
        $text = [];
        for ($i = 0; $i < $lines && !feof($handle); $i++) {
            $text[] = fgets($handle);
        }
        fclose($handle);
        return $text;
    }

    // 格式化文件大小
    private function format_bytes($size)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }
        return round($size, 2) . ' ' . $units[$i];
    }

    // 实时获取新日志
    public function tailf()
    {
        if (session('r_user') == null || session('r_user')['authority'] != 0) {
            return json(['code' => 403, 'msg' => '无权限']);
        }

        $filename = input('filename');
        $lastPosition = input('position', 0);

        $logPath = root_path() . 'runtime/log/';
        $filePath = $logPath . basename($filename);
        
        if (!file_exists($filePath) || !is_file($filePath)) {
            return json(['code' => 404, 'msg' => '日志文件不存在']);
        }

        $currentSize = filesize($filePath);
        $newContent = '';

        if ($currentSize > $lastPosition) {
            $handle = fopen($filePath, 'r');
            fseek($handle, $lastPosition);
            $newContent = fread($handle, $currentSize - $lastPosition);
            fclose($handle);
        }

        return json([
            'code' => 200,
            'data' => [
                'content' => $newContent,
                'position' => $currentSize
            ]
        ]);
    }
}
