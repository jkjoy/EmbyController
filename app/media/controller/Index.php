<?php

namespace app\media\controller;

use app\api\model\EmbyUserModel;
use app\BaseController;
use app\media\model\MediaCommentModel;
use app\media\model\MediaInfoModel;
use think\facade\Request;
use think\facade\Session;
use app\media\model\UserModel as UserModel;
use think\facade\View;
use think\facade\Config;
use think\facade\Cache;

class Index extends BaseController
{

    public function index()
    {
        // 查询用户数
        $userModel = new UserModel();
        $allRegisterUserCount = $userModel->count();
        $activateRegisterUserCount = $userModel->where('authority', '>=', 0)->count();
        $deactivateRegisterUserCount = $allRegisterUserCount - $activateRegisterUserCount;
        // 24小时登录用户数
        $todayLoginUserCount = $userModel->where('updatedAt', '>=', date('Y-m-d H:i:s', strtotime('-1 day')))->count();
        // 查询最新的两条评论，comment需要大于100字
        $mediaCommentModel = new MediaCommentModel();
        $latestMediaComment = $mediaCommentModel
            ->where('comment', '>', 50)
            ->where('mentions', '=', '[]')
            ->order('createdAt', 'desc')
            ->join('rc_media_info', 'rc_media_comment.mediaId = rc_media_info.id')
            ->field('rc_media_comment.*, rc_media_info.mediaName, rc_media_info.mediaYear, rc_media_info.mediaType, rc_media_info.mediaMainId')
            ->limit(2)
            ->select();
        foreach ($latestMediaComment as $key => $comment) {
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
                $latestMediaComment[$key]['mentions'] = $mentionsUser;
            }
        }
        View::assign('allRegisterUserCount', $allRegisterUserCount);
        View::assign('activateRegisterUserCount', $activateRegisterUserCount);
        View::assign('deactivateRegisterUserCount', $deactivateRegisterUserCount);
        View::assign('todayLoginUserCount', $todayLoginUserCount);
        View::assign('latestMediaComment', $latestMediaComment);
        return view();
    }

    public function getLineStatus()
    {
        $islogin = false;
        if (Session::get('r_user') != null) {
            $islogin = true;
        }
        // 处理POST请求
        if (Request::isPost()) {
            if (Cache::get('serverList')) {
                $serverList = Cache::get('serverList');
            } else {
                $serverList = [];
                $lineList = Config::get('media.lineList');
                $i = 0;
                foreach ($lineList as $line) {
                    $url = $line['url'] . '/emby/System/Ping?api_key=' . Config::get('media.apiKey');
                    try {
                        $ch = curl_init($url);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'accept: */*'
                        ]);
                        curl_exec($ch);
                        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200) {
                            $status = 1;
                        } else {
                            $status = 0;
                        }
                    } catch (\Exception $e) {
                        $status = 0;
                    }
                    $i++;
                    $serverList[] = [
                        'name' => $line['name'],
                        'url' => $line['url'],
                        'status' => $status
                    ];
                }
                // 将serverList保存到缓存中
                Cache::set('serverList', $serverList, 600);
            }

            if (!$islogin) {
                // 去除name和url
                $i = 0;
                foreach ($serverList as $key => $value) {
                    $i++;
                    $serverList[$key]['name'] = $i;
                    $serverList[$key]['url'] = '';
                }
            }

            return json(['code' => 200, 'serverList' => $serverList]);
        }
    }

    public function getLatestMedia() {
        if (request()->isPost()) {
            $embyUserModel = new EmbyUserModel();
            if (Session::get('r_user') != null) {
                $embyUser = $embyUserModel->where('userId', Session::get('r_user')['id'])->find();
            } else {
                $embyUser = null;
            }
            if ($embyUser) {
                $embyUserId = $embyUser['embyId'];
            } else {
                $embyUserId = Config::get('media.adminUserId');
            }
            if (Cache::get('latestMedia-'.$embyUserId)) {
                $latestMedia = Cache::get('latestMedia-'.$embyUserId);
            } else {
                $url = Config::get('media.urlBase') . 'Users/' . $embyUserId . '/Items/Latest?EnableImages=true&EnableUserData=false&api_key=' . Config::get('media.apiKey');
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'accept: application/json'
                ]);
                $latestMedia = curl_exec($ch);
                Cache::set('latestMedia-'.$embyUserId, $latestMedia, 600);
            }
            return json(['code' => 200, 'latestMedia' => json_decode($latestMedia, true)]);
        }
    }

    public function getMetaData() {
        if (request()->isPost()) {
            $data = request()->post();
            if (isset($data['mediaId']) && $data['mediaId'] != '') {
                $embyUserModel = new EmbyUserModel();
                $embyUser = $embyUserModel->where('userId', Session::get('r_user')['id'])->find();
                if ($embyUser) {
                    $embyUserId = $embyUser['embyId'];
                } else {
                    $embyUserId = Config::get('media.adminUserId');
                }
                while (1){
                    $url = Config::get('media.urlBase') . 'Users/' . $embyUserId . '/Items/' . $data['mediaId'] . '?api_key=' . Config::get('media.apiKey');
                    $ch = curl_init($url);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'accept: application/json'
                    ]);
                    $metaData = json_decode(curl_exec($ch), true);

                    if ($metaData['Type'] == "Episode" && isset($metaData['SeriesId'])) {
                        $data['mediaId'] = $metaData['SeriesId'];
                    } else {
                        break;
                    }
                }

                $mediaName = $metaData['Name'];
                $mediaYear = date('Y', strtotime($metaData['PremiereDate']));

                $mediaInfoModel = new MediaInfoModel();
                $mediaInfo = $mediaInfoModel->where('mediaName', $mediaName)->where('mediaYear', $mediaYear)->find();
                if ($mediaInfo) {
                    $mediaInfo = json_decode($mediaInfo['mediaInfo'], true);
                    // 判断mediaIdList中是否已经存在mediaId
                    if (!in_array($data['mediaId'], $mediaInfo['mediaIdList'])) {
                        $mediaInfo['mediaIdList'][] = $data['mediaId'];
                        $mediaInfoModel->where('mediaName', $mediaName)->where('mediaYear', $mediaYear)->update([
                            'mediaInfo' => json_encode($mediaInfo)
                        ]);
                    }
                } else {
                    $mediaInfo = [
                        'mediaIdList' => [$data['mediaId']],
                        'ExternalUrls' => $metaData['ExternalUrls'],
                        'People' => $metaData['People'],
                        'Genres' => $metaData['Genres'],
                        'Studios' => $metaData['Studios'],
                        'Overview' => $metaData['Overview'],
                        'PremiereDate' => $metaData['PremiereDate'],
                    ];
                    $mediaInfoModel->save([
                        'mediaName' => $mediaName,
                        'mediaYear' => $mediaYear,
                        'mediaType' => ($metaData['Type'] == 'Movie') ? 1 : ($metaData['Type'] == 'Series' ? 2 : 0),
                        'mediaMainId' => $data['mediaId'],
                        'mediaInfo' => json_encode($mediaInfo)
                    ]);
                }
                $mediaInfo = $mediaInfoModel->where('mediaName', $mediaName)->where('mediaYear', $mediaYear)->find();
                $mediaInfoArray = json_decode($mediaInfo['mediaInfo'], true);
                $mediaInfo['mediaInfo'] = $mediaInfoArray;

                $mediaCommentModel = new MediaCommentModel();
                $mediaComment = $mediaCommentModel->where('mediaId', $mediaInfo['id'])->find();
                if ($mediaComment) {
                    $mediaCommentCount = $mediaCommentModel->where('mediaId', $mediaInfo['id'])->count();
                    $averageRate = $mediaCommentModel->where('mediaId', $mediaInfo['id'])->avg('rating');
                } else {
                    $mediaCommentCount = 0;
                    $averageRate = 0;
                }
                $basicInfo = [
                    'mediaCommentCount' => $mediaCommentCount,
                    'averageRate' => $averageRate
                ];
                return json(['code' => 200, 'mediaInfo' => $mediaInfo, 'basicInfo' => $basicInfo]);
            } else {
                return json(['code' => 400, 'message' => 'mediaId不能为空']);
            }
        }
    }

    public function getPrimaryImg() {
        if (request()->isGet()) {
            $id = input('id');
            if ($id == 0) {
                $file = file_get_contents('static/media/img/movie-img.jpeg');
                return response($file, 200, ['Content-Type' => 'image/jpeg']);
            }
            $url = Config::get('media.urlBase') . 'Items/' . $id . '/Images/Primary?quality=80&api_key=' . Config::get('media.apiKey');
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'accept: */*'
            ]);
            $response = curl_exec($ch);
            if ($response == '' || $response == 'Object reference not set to an instance of an object.') {
                $file = file_get_contents('static/media/img/movie-img.jpeg');
                return response($file, 200, ['Content-Type' => 'image/jpeg']);
            }
            return response($response, 200, ['Content-Type' => 'image/jpeg']);
        }
    }

    public function admin()
    {
        return redirect((string) url('/media/admin/index'));
    }

}
