<?php

namespace app\api\controller;

use app\api\model\UserModel;
use app\BaseController;
use think\facade\Request;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;
use think\Response;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Label\Label;
use Endroid\QrCode\Logo\Logo;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\ValidationException;
use GuzzleHttp\Client;
use think\facade\Cache;

class Common extends BaseController
{
    /**
     * 获取接口列表
     * @return \think\response\Json
     * @author Anjie
     * @date 2024-07-09
     */
    public function index()
    {
        $controllerPath = '../app/api/controller';
        $namespace = 'app\\api\\controller\\';
        $file = 'Common.php';
        $className = str_replace('.php', '', $file);
        $fullClassName = $namespace . $className;

        $apis = [];

        // 检查类是否存在，避免类名无效的问题
        if (class_exists($fullClassName)) {
            $reflectionClass = new \ReflectionClass($fullClassName);
            $methods = $reflectionClass->getMethods(\ReflectionMethod::IS_PUBLIC);

            foreach ($methods as $method) {
                if ($method->name == '__construct') {
                    continue;
                }

                $docComment = $method->getDocComment();
                $description = $this->parseDescriptionFromDocComment($docComment);

                $apis[] = [
                    'url' => '/api/' . strtolower($className) . '/' . $method->name,
                    'method' => 'GET', // 默认GET，可以根据注释内容调整
                    'description' => $description
                ];
            }
        }

        return json([
            'code' => 200,
            'msg' => 'success',
            'data' => $apis
        ]);
    }


    /**
     * 心跳检测
     * @return \think\response\Json
     * @author Anjie
     * @date 2024-07-09
     */
    public function ping()
    {
        $time = time();
        return json([
            'code' => 200,
            'msg' => 'pong',
            'data' => [
                'time' => $time
            ]
        ]);
    }

    /**
     * 获取IP地址（ipv4/ipv6）
     * @return \think\response\Json
     * @author Anjie
     * @date 2024-07-09
     */
    public function getip()
    {
        return json([
            'code' => 200,
            'msg' => 'success',
            'data' => [
                'ip' => getRealIp()
            ]
        ]);
    }

    /**
     * 根据id/邮箱获取用户的qq邮箱/gravatar头像，优先id，优先qq邮箱
     * @return Response
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author Anjie
     * @date 2024-07-10
     */
    public function getHeadImg()
    {
        $id = input('id');
        $email = input('email');
        $size = input('size', 96);
        $default = input('default', 'mp');
        $rating = input('rating', 'g');

        if ($id && $id == 0) {
            // 返回：public/static/index/img/logo-dark.png
            $img = file_get_contents('../public/static/index/img/logo-dark.png');
            return response($img, 200, ['Content-Type' => 'image/png']);
        }

        if ($id) {
            // 根据id获取用户邮箱
            $user = UserModel::find($id);
            if ($user) {
                $email = $user->email;
            }
        }

        if (is_null($email)) {
            $email = '';
        } else {
            $email = strtolower(trim($email));
        }


        $url = getGravatar($email, $size, $default, $rating, false);

        // 获取Gravatar图片
        $img = file_get_contents($url);

        if ($img === false) {
            return response('Failed to retrieve image', 500);
        }

        return response($img, 200, ['Content-Type' => 'image/png']);
    }

    /**
     * 根据内容获取二维码
     * @author Anjie
     * @date 2024-10-31
     */
    public function getqrcode()
    {
        $data = input('data');
        $writer = new PngWriter();
        $qrCode = new QrCode(
            data: $data,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Low,
            size: 500,
            margin: 20,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
            foregroundColor: new Color(0, 0, 0),
            backgroundColor: new Color(255, 255, 255)
        );
        $logo = new Logo(
            path: __DIR__.'/assets/symfony.png',
            resizeToWidth: 50,
            punchoutBackground: true
        );
        $label = new Label(
            text: 'Label',
            textColor: new Color(255, 0, 0)
        );
//        $result = $writer->write($qrCode, $logo, $label);
        $result = $writer->write($qrCode);
        header('Content-Type: '.$result->getMimeType());
        echo $result->getString();
        exit();

    }

    /**
     * 图片代理接口
     * @return Response
     */
    public function proxyImage()
    {
        $url = request()->get('url', '');
        if (empty($url)) {
            return response('Invalid URL', 400);
        }

        // 解码URL
        $url = urldecode($url);

        // 检查URL是否合法
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return response('Invalid URL', 400);
        }

        // 检查是否是允许的域名
        $allowedDomains = [
            'image.tmdb.org',
            'themoviedb.org',
            'www.themoviedb.org',
            't.alipayobjects.com',
            'img.alipayobjects.com',
            'qnmob3.doubanio.com'
        ];

        $urlDomain = parse_url($url, PHP_URL_HOST);
        
        // 特殊处理TMDB的图片URL
        if (strpos($url, 'themoviedb.org') !== false && !str_starts_with($url, 'https://image.tmdb.org')) {
            // 如果是TMDB的图片但不是完整的CDN地址，添加CDN前缀
            if (str_starts_with($url, '/')) {
                $url = 'https://image.tmdb.org/t/p/original' . $url;
            } else {
                $url = 'https://image.tmdb.org/t/p/original/' . $url;
            }
        }

        // 检查域名是否允许
        $isAllowed = false;
        foreach ($allowedDomains as $domain) {
            if (strpos($urlDomain, $domain) !== false) {
                $isAllowed = true;
                break;
            }
        }

        if (!$isAllowed) {
            trace("Domain not allowed: " . $urlDomain . " for URL: " . $url, 'error');
            return response('Domain not allowed', 403);
        }

        // 生成缓存键
        $cacheKey = 'image_proxy_' . md5($url);

        try {
            // 尝试从缓存获取图片
            $imageData = Cache::get($cacheKey);
            
            if (!$imageData) {
                // 如果缓存中没有，则从远程获取
                $client = new Client([
                    'timeout' => 10,
                    'verify' => false
                ]);
                
                $response = $client->get($url);
                $imageData = [
                    'content' => $response->getBody()->getContents(),
                    'content_type' => $response->getHeaderLine('Content-Type'),
                ];
                
                // 如果没有获取到content-type，根据文件扩展名判断
                if (empty($imageData['content_type'])) {
                    $extension = strtolower(pathinfo($url, PATHINFO_EXTENSION));
                    $mimeTypes = [
                        'jpg' => 'image/jpeg',
                        'jpeg' => 'image/jpeg',
                        'png' => 'image/png',
                        'gif' => 'image/gif',
                        'webp' => 'image/webp'
                    ];
                    $imageData['content_type'] = $mimeTypes[$extension] ?? 'image/jpeg';
                }
                
                // 缓存图片数据，有效期1天
                Cache::set($cacheKey, $imageData, 24 * 3600);
            }

            // 返回图片，使用数组格式设置header
            return response($imageData['content'], 200, [
                'Content-Type' => $imageData['content_type'],
                'Cache-Control' => 'public, max-age=86400',
                'Access-Control-Allow-Origin' => '*'
            ]);

        } catch (\Exception $e) {
            // 记录错误日志
            trace('Image proxy error: ' . $e->getMessage() . ' for URL: ' . $url, 'error');
            return response('Failed to fetch image', 500);
        }
    }

    public function getLocation()
    {
        $ip = getRealIp();
        $url = '/ws/location/v1/ip?ip=' . $ip . '&key=' . config('map.key');

        $md5 = md5($url . config('map.sk'));
        $url = 'https://apis.map.qq.com' . $url . '&sig=' . $md5;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $output = curl_exec($ch);
        curl_close($ch);

        return $output;

    }

    // 以下为private方法，不对外提供访问

    /**
     * 解析方法注释中的描述信息
     * @param string $docComment
     * @return string
     * @author Anjie
     * @date 2024-07-09
     */
    private function parseDescriptionFromDocComment($docComment)
    {
        $description = '暂无接口描述';
        if ($docComment) {
            // 使用正则表达式提取注释中的描述部分
            if (preg_match('/\*\s+(.+?)(?=\n\s*\*|\*\/)/s', $docComment, $matches)) {
                $description = trim($matches[1]);
                // 去除每行开头的 '*' 和多余的空格
                $description = preg_replace('/^\*\s*/m', '', $description);
                $description = preg_replace('/\n\s*/', '', $description);
            }
        }
        return $description;
    }

}