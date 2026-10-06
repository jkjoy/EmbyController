<?php
namespace app\media\service;

use think\facade\Cache;
use think\facade\Config;
use GuzzleHttp\Client;
use Exception;

class MoviePilot
{
    protected $client;
    protected $config;
    protected $token;
    protected $tokenCacheKey;
    protected $cryptKey;
    
    public function __construct()
    {
        $this->config = Config::get('media.moviepilot');
        if (empty($this->config)) {
            throw new Exception('MoviePilot配置错误');
        } else if ($this->config['enabled'] !== true) {
            throw new Exception('MoviePilot未启用');
        }

        $baseUrl = Config::get('media.moviepilot.url', '');
        
        // 确保URL末尾没有斜杠
        $baseUrl = rtrim($baseUrl, '/');
        
        $this->client = new Client([
            'base_uri' => $baseUrl,
            'timeout' => 30,
            'verify' => false,
            'allow_redirects' => [
                'max'             => 5,
                'strict'          => true,
                'referer'         => true,
                'protocols'       => ['http', 'https'],
                'track_redirects' => true
            ]
        ]);
        
        trace("MoviePilot base URL: " . $baseUrl, 'info');
        $this->tokenCacheKey = 'moviepilot_token_' . hash('sha256', serialize([
            $baseUrl,
            $this->config['username'],
            $this->config['password'],
        ]));
        $this->token = Cache::get($this->tokenCacheKey);
        $this->cryptKey = Config::get('media.crontabKey', '');
        
        if (!$this->token) {
            $this->login();
        }
    }
    
    protected function login()
    {
        try {
            $response = $this->client->post('/api/v1/login/access-token', [
                'form_params' => [
                    'username' => $this->config['username'],
                    'password' => $this->config['password'],
                ],
            ]);
            
            $result = json_decode($response->getBody(), true);
            if (isset($result['access_token'])) {
                $this->token = $result['token_type'] . ' ' . $result['access_token'];
                Cache::set($this->tokenCacheKey, $this->token, 3600); // 1小时过期
                return true;
            }
        } catch (Exception $e) {
            trace("MoviePilot登录失败: " . $e->getMessage(), 'error');
        }
        return false;
    }
    
    protected function encrypt($data)
    {
        $method = 'AES-256-CBC';
        $key = substr(hash('sha256', $this->cryptKey, true), 0, 32);
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt(json_encode($data), $method, $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $encrypted);
    }
    
    protected function decrypt($data)
    {
        $method = 'AES-256-CBC';
        $key = substr(hash('sha256', $this->cryptKey, true), 0, 32);
        $data = base64_decode($data);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        return json_decode(openssl_decrypt($encrypted, $method, $key, OPENSSL_RAW_DATA, $iv), true);
    }
    
    public function search($title)
    {
        try {
            $response = $this->client->get('/api/v1/search/title', [
                'headers' => ['Authorization' => $this->token],
                'query' => ['keyword' => $title],
            ]);
            
            $data = json_decode($response->getBody(), true);
            if ($data['success']) {
                $results = [];
                foreach ($data['data'] as $item) {
                    $metaInfo = $item['meta_info'] ?? [];
                    $torrentInfo = $item['torrent_info'] ?? [];
                    
                    $encryptedTorrentInfo = $this->encrypt($torrentInfo);
                    
                    $results[] = [
                        'title' => $metaInfo['title'] ?? '',
                        'year' => $metaInfo['year'] ?? '',
                        'type' => $metaInfo['type'] ?? '',
                        'resource_pix' => $metaInfo['resource_pix'] ?? '',
                        'video_encode' => $metaInfo['video_encode'] ?? '',
                        'audio_encode' => $metaInfo['audio_encode'] ?? '',
                        'resource_team' => $metaInfo['resource_team'] ?? '',
                        'seeders' => $torrentInfo['seeders'] ?? '',
                        'size' => $torrentInfo['size'] ?? '',
                        'labels' => $torrentInfo['labels'] ?? '',
                        'description' => $torrentInfo['description'] ?? '',
                        'torrent_info' => $encryptedTorrentInfo,
                    ];
                }
                
                // 按做种数排序并限制结果数量
                usort($results, function($a, $b) {
                    return $b['seeders'] <=> $a['seeders'];
                });
                
                return array_slice($results, 0, 10);
            }
        } catch (Exception $e) {
            trace("MoviePilot搜索失败: " . $e->getMessage(), 'error');
        }
        return [];
    }
    
    public function addDownloadTask($param)
    {
        try {
            trace("MoviePilot添加下载任务参数: " . json_encode($param), 'info');
            
            $data = [
                'torrent_in' => $this->decrypt($param['torrentInfo'])
            ];
            
            if (isset($data['torrent_in']['site']) && !is_int($data['torrent_in']['site'])) {
                $data['torrent_in']['site'] = intval($data['torrent_in']['site']);
            }
            
            $response = $this->client->post('/api/v1/download/add', [
                'headers' => [
                    'Authorization' => $this->token,
                    'Content-Type' => 'application/json',
                ],
                'json' => $data,
            ]);
            
            $result = json_decode($response->getBody(), true);
            trace("MoviePilot API返回: " . json_encode($result), 'info');
            
            if ($result['success']) {
                return [
                    'success' => true,
                    'download_id' => $result['data']['download_id'],
                ];
            }
            
            if (isset($result['message']) && mb_strlen($result['message']) > 0) {
                return [
                    'success' => false,
                    'message' => $result['message']
                ];
            }
            
            return [
                'success' => false,
                'message' => '添加下载任务失败，请检查站点配置是否正确'
            ];
        } catch (Exception $e) {
            trace("MoviePilot添加下载任务失败: " . $e->getMessage(), 'error');
            return [
                'success' => false,
                'message' => '添加下载任务失败，请联系管理员检查日志'
            ];
        }
    }
    
    public function getDownloadTasks($download_id = null)
    {
        try {
            trace("MoviePilot 开始获取下载任务列表", 'info');
            
            $response = $this->client->get('/api/v1/download', [
                'headers' => ['Authorization' => $this->token],
                'query' => [
                    'name' => 'qBittorrent'
                ]
            ]);
            
            trace("MoviePilot API响应: " . $response->getBody(), 'info');
            $result = json_decode($response->getBody(), true);
            
            $data = [];
            foreach ($result as $item) {
                if ($download_id && $item['hash'] !== $download_id) {
                    continue;
                }
                $data[] = [
                    'download_id' => $item['hash'],
                    'state' => $item['state'],
                    'progress' => $item['progress'],
                ];
                if ($download_id) {
                    break;
                }
            }
            
            if (empty($data)) {
                trace("MoviePilot 未找到任何下载任务", 'info');
            }
            
            return $data;
        } catch (Exception $e) {
            trace("MoviePilot获取下载任务失败: " . $e->getMessage(), 'error');
            if (strpos($e->getMessage(), 'Connection refused') !== false) {
                trace("MoviePilot服务未启动或配置错误，请检查服务状态和配置", 'error');
            } elseif (strpos($e->getMessage(), 'SSL') !== false) {
                trace("MoviePilot SSL证书验证失败，请检查证书配置", 'error');
            } elseif (strpos($e->getMessage(), '401') !== false) {
                trace("MoviePilot 认证失败，请检查token是否有效", 'error');
                // 尝试重新登录
                if ($this->login()) {
                    return $this->getDownloadTasks($download_id);
                }
            }
        }
        return [];
    }
    
    public function getDownloadTask($downloadId)
    {
        try {
            if (empty($downloadId)) {
                trace("MoviePilot获取下载任务失败: downloadId为空", 'error');
                return null;
            }
            
            $tasks = $this->getDownloadTasks($downloadId);
            if (empty($tasks)) {
                trace("MoviePilot获取下载任务失败: 获取任务列表失败", 'error');
                return null;
            }
            
            foreach ($tasks as $task) {
                if ($task['download_id'] === $downloadId) {
                    return $task;
                }
            }
            
            trace("MoviePilot获取下载任务失败: 未找到指定任务 ID: " . $downloadId, 'error');
        } catch (Exception $e) {
            trace("MoviePilot获取下载任务失败: " . $e->getMessage(), 'error');
        }
        return null;
    }
    
    public function subscribeSearch($title, $page = 1)
    {
        try {
            $response = $this->client->get('/api/v1/media/search', [
                'headers' => ['Authorization' => $this->token],
                'query' => [
                    'title' => $title,
                    'type' => 'media',
                    'page' => $page
                ],
            ]);
            
            $data = json_decode($response->getBody(), true);
            if (!empty($data)) {
                $results = array_map(function($item) {
                    // 如果 $item['genre_ids']存在  16 或者 10762 ，就是动画，就不返回
                    if (isset($item['genre_ids']) && (in_array(16, $item['genre_ids']) || in_array(10762, $item['genre_ids']))) {
                        return null;
                    }
                    // 检查媒体库状态
                    $exists = $this->checkMediaExists($item);
                    // 检查订阅状态
                    $subscribed = $this->checkSubscribed($item);

                    return [
                        'title' => $item['title'] ?? '',
                        'type' => $item['type'] ?? '',
                        'year' => $item['year'] ?? '',
                        'tmdb_id' => $item['tmdb_id'] ?? null,
                        'douban_id' => $item['douban_id'] ?? null,
                        'bangumi_id' => $item['bangumi_id'] ?? null,
                        'original_language' => $item['original_language'] ?? '',
                        'overview' => $item['overview'] ?? '',
                        'poster_path' => $item['poster_path'] ?? '',
                        'vote_average' => $item['vote_average'] ?? 0,
                        'source' => $item['source'] ?? '',
                        'in_library' => $exists,  // 是否在媒体库中
                        'subscribed' => $subscribed  // 是否已订阅
                    ];
                }, $data);

                return array_values(array_filter($results));
            }
        } catch (Exception $e) {
            trace("MoviePilot媒体搜索失败: " . $e->getMessage(), 'error');
            throw new Exception('搜索失败: ' . $e->getMessage());
        }
        return [];
    }

    // 检查是否在媒体库中
    public function checkMediaExists($mediaInfo)
    {
        try {
            $response = $this->client->get('/api/v1/mediaserver/exists', [
                'headers' => ['Authorization' => $this->token],
                'query' => [
                    'tmdbid' => $mediaInfo['tmdb_id'],
                    'title' => $mediaInfo['title'],
                    'year' => $mediaInfo['year'],
                    'mtype' => $mediaInfo['type']
                ]
            ]);

            $result = json_decode($response->getBody(), true);
            return $result['success'] && !empty($result['data']['item']['id']);
        } catch (Exception $e) {
            trace("检查媒体是否存在失败: " . $e->getMessage(), 'error');
            return false;
        }
    }

    // 检查是否已订阅
    public function checkSubscribed($mediaInfo)
    {
        try {
            // 构建查询路径
            $queryPath = '';
            if (!empty($mediaInfo['douban_id'])) {
                $queryPath = 'douban:' . $mediaInfo['douban_id'];
            } else if (!empty($mediaInfo['tmdb_id'])) {
                $queryPath = 'tmdb:' . $mediaInfo['tmdb_id'];
            } else if (!empty($mediaInfo['bangumi_id'])) {
                $queryPath = 'bangumi:' . $mediaInfo['bangumi_id'];
            } else {
                return false;
            }

            $response = $this->client->get('/api/v1/subscribe/media/' . $queryPath, [
                'headers' => ['Authorization' => $this->token],
                'query' => [
                    'title' => $mediaInfo['title']
                ]
            ]);

            $result = json_decode($response->getBody(), true);
            return !empty($result['id']);
        } catch (Exception $e) {
            trace("检查订阅状态失败: " . $e->getMessage(), 'error');
            return false;
        }
    }
    
    public function getSeasons($tmdbId)
    {
        try {
            $response = $this->client->get('/api/v1/tmdb/seasons/' . $tmdbId, [
                'headers' => ['Authorization' => $this->token]
            ]);
            
            $data = json_decode($response->getBody(), true);
            if (!empty($data)) {
                // 处理季度信息，只返回有效的季度
                return array_filter($data, function($season) {
                    return !empty($season['air_date']) || !empty($season['episode_count']);
                });
            }
        } catch (Exception $e) {
            trace("MoviePilot获取季度信息失败: " . $e->getMessage(), 'error');
            throw new Exception('获取季度信息失败: ' . $e->getMessage());
        }
        return [];
    }
    
    public function subscribeMedia($mediaInfo)
    {
        try {
            if ($mediaInfo['type'] === '电视剧') {
                // 获取季度信息
                $seasons = $this->getSeasons($mediaInfo['tmdb_id']);
                if (empty($seasons)) {
                    throw new Exception('未找到季度信息');
                }

                // 检查媒体服务器是否存在
                $notExistsResult = $this->checkMediaServerNotExists($mediaInfo);

                $results = [];
                foreach ($seasons as $season) {
                    // 跳过没有播出日期且没有集数的季度
                    if (empty($season['air_date']) && empty($season['episode_count'])) {
                        continue;
                    }

                    $result = $this->subscribe([
                        'name' => $mediaInfo['title'],
                        'type' => $mediaInfo['type'],
                        'year' => $mediaInfo['year'],
                        'tmdbid' => $mediaInfo['tmdb_id'],
                        'season' => $season['season_number'],
                        'doubanid' => $mediaInfo['douban_id'] ?? null,
                        'bangumiid' => $mediaInfo['bangumi_id'] ?? null,
                        'best_version' => 0
                    ]);

                    $results[] = [
                        'season' => $season['season_number'],
                        'success' => $result['success'],
                        'message' => $result['message']
                    ];
                }

                // 检查是否所有季度都订阅成功
                $allSuccess = array_reduce($results, function($carry, $item) {
                    return $carry && $item['success'];
                }, true);

                return [
                    'success' => true,  // 只要有成功订阅的就返回true
                    'message' => $allSuccess ? '所有季度订阅成功' : '部分季度订阅成功',
                    'data' => [  // 添加 data 字段
                        'title' => $mediaInfo['title'],
                        'type' => $mediaInfo['type'],
                        'year' => $mediaInfo['year'],
                        'overview' => $mediaInfo['overview'] ?? '',
                        'details' => $results
                    ]
                ];
            } else {
                // 电影直接订阅
                $result = $this->subscribe([
                    'name' => $mediaInfo['title'],
                    'type' => $mediaInfo['type'],
                    'year' => $mediaInfo['year'],
                    'tmdbid' => $mediaInfo['tmdb_id'],
                    'season' => 0,
                    'doubanid' => $mediaInfo['douban_id'] ?? null,
                    'bangumiid' => $mediaInfo['bangumi_id'] ?? null,
                    'best_version' => 1  // 电影使用最佳版本
                ]);

                return [
                    'success' => true,
                    'message' => '订阅成功',
                    'data' => [
                        'title' => $mediaInfo['title'],
                        'type' => $mediaInfo['type'],
                        'year' => $mediaInfo['year'],
                        'overview' => $mediaInfo['overview'] ?? ''
                    ]
                ];
            }
        } catch (Exception $e) {
            trace("MoviePilot订阅媒体失败: " . $e->getMessage(), 'error');
            throw new Exception('订阅失败: ' . $e->getMessage());
        }
    }

    private function checkMediaServerNotExists($mediaInfo)
    {
        try {
            // 构建请求参数
            $params = [
                'source' => 'themoviedb',
                'type' => $mediaInfo['type'],
                'title' => $mediaInfo['title'],
                'year' => $mediaInfo['year'],
                'tmdb_id' => $mediaInfo['tmdb_id'],
                'original_language' => $mediaInfo['original_language'],
                'original_title' => $mediaInfo['original_title'] ?? $mediaInfo['title'],
                'release_date' => $mediaInfo['release_date'] ?? '',
                'backdrop_path' => $mediaInfo['backdrop_path'] ?? '',
                'poster_path' => $mediaInfo['poster_path'] ?? '',
                'vote_average' => $mediaInfo['vote_average'] ?? 0,
                'overview' => $mediaInfo['overview'] ?? '',
                'origin_country' => $mediaInfo['origin_country'] ?? [],
                'popularity' => $mediaInfo['popularity'] ?? 0,
                'detail_link' => "https://www.themoviedb.org/tv/{$mediaInfo['tmdb_id']}",
                'title_year' => "{$mediaInfo['title']} ({$mediaInfo['year']})"
            ];

            $response = $this->client->post('/api/v1/mediaserver/notexists', [
                'headers' => [
                    'Authorization' => $this->token,
                    'Content-Type' => 'application/json'
                ],
                'json' => $params
            ]);

            $result = json_decode($response->getBody(), true);
            return [
                'success' => true,
                'data' => $result
            ];
        } catch (Exception $e) {
            trace("检查媒体服务器失败: " . $e->getMessage(), 'error');
            return [
                'success' => false,
                'message' => '检查媒体服务器失败: ' . $e->getMessage()
            ];
        }
    }

    public function subscribe($params)
    {
        try {
            $response = $this->client->post('/api/v1/subscribe/', [
                'headers' => [
                    'Authorization' => $this->token,
                    'Content-Type' => 'application/json'
                ],
                'json' => $params
            ]);

            $result = json_decode($response->getBody(), true);
            
            // MoviePilot返回失败可能是已存在的情况，我们也认为是成功
            return [
                'success' => true,
                'message' => $result['message'] ?? '订阅成功',
                'data' => $result['data'] ?? null
            ];
        } catch (Exception $e) {
            trace("MoviePilot订阅失败: " . $e->getMessage(), 'error');
            return [
                'success' => false,
                'message' => '订阅失败: ' . $e->getMessage()
            ];
        }
    }
}
