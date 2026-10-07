<?php

namespace app\api\route;
use think\facade\Route;


Route::get('ping', 'Index/ping')->completeMatch();
Route::get('common/proxyImage', 'common/proxyImage');
