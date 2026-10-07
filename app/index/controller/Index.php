<?php

namespace app\index\controller;

use app\BaseController;
use think\facade\View;

class Index extends BaseController
{
    public function index()
    {
        return redirect('/');
    }

    public function hello($name = 'ThinkPHP8')
    {
        return 'hello,' . $name;
    }


}
