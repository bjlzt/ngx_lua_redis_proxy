<?php
/***************************************************************************
 *
 * Copyright (c) 2016 ByteDance.com, Inc. All Rights Reserved
 *
 **************************************************************************/

/**
 *@file LuaRedis.php
 *
 *@brief
 *
 *@author liuzhantao@bytedance.com
 *@date   2016-05-08
 *@update 2016-05-08
 *
 */
/*
demo : 
    $test = lua_redis::getInstance();

    $ret = $test->set('k1','v1');
    $ret = $test->get('k1');

    $test->mset('k1','v1','k2','v2');
    $test->mget('k1','k2','k3','k4');

    //pipeline
    $test->pipeline('mset','k1','v1','k2','v2');
    $test->pipeline('mget','k1','k2','k3');
    $ret = $test->commit();
*/
class lua_redis
{
    private $url_ = 'http://10.4.18.124/rds';
    private $db_ = 1;
    private $auth_ = null;
    private $debug_ = 0;
    private $srv_ = 'duoshuo';
    private $error_ = null;
    public $commands_ = [];

    static private $instance_ = null;

    public static function getInstance()
    {
        if (self::$instance_ == null)
            self::$instance_ = new self();
        return self::$instance_;
    }
    public function getError()
    {
        return $this->error_;
    }

    /**
     *@desc 普通方法调用
     */
    public function __call($func,$params)
    {
        $command = $func; 
        if (!empty($params))
            $command .= '/'. implode('/',$params);

        $url = $this->url_ .'/' . $command . '?' . $this->genArgs(); 
        return $this->getReq($url);
    }
    /**
     *@desc pipeline调用
     */
    public function pipeline()
    {
        $this->commands_[] = func_get_args(); 
    }
    public function commit()
    {
        if (empty($this->commands_))
        {
            $this->error_ .= '没有传递参数。';
            return false;
        }

        $data['pipeline'] = json_encode($this->commands_);
        $url = $this->url_ .'?' . $this->genArgs();
        return $this->post($url,$data);
    }
    /**
     *@desc 调用get方法：函数名不能使用get，因为redis中存在这个命令
     */
    public function getReq($url,$headers = [])
    {
        $ret = $this->http($url,'GET',NULL,$headers);
        return $ret;
    }
    public function post($url,$data,$headers = [])
    {
        $ret = $this->http($url,'POST',$data,$headers);
        return $ret;
    }
    private function parseResponse($str)
    {
        $result = false;
        do 
        {
            $ret = json_decode($str,true);
            $errno = json_last_error();
            if ($errno != JSON_ERROR_NONE)
            {
                $this->error_ .= "json_decode失败： $ret,str=$str";
                break;
            }
            if ($ret['code'] !== 0)
            {
                $this->error_ .= '获取失败：'. $ret['msg'];
                break;
            }
            $result = $ret['response'];
        }
        while(false);
        return $result;
    }
    private function genArgs()
    {
        $uri = 'srv=' . (empty($this->srv_) ? 'default' : $this->srv_); 
        if ($this->db_)
            $uri .= '&db='.$this->db_;
        if (!empty($this->auth_))
        {
            $uri .= '&auth=' . $this->auth_;
        }
        if ($this->debug_)
            $uri .= '&debug=' . $this->debug_;
        return $uri;
    }
    public function http($url, $method = 'GET', $postfields = NULL, $headers = array()) 
    {
        $this->http_info = array();
        $ci = curl_init();
        curl_setopt($ci, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_0);
        curl_setopt($ci, CURLOPT_USERAGENT, 'ds-php');
        curl_setopt($ci, CURLOPT_CONNECTTIMEOUT, 1);
        curl_setopt($ci, CURLOPT_TIMEOUT, 3);
        curl_setopt($ci, CURLOPT_RETURNTRANSFER, TRUE);

        if ('POST' == $method)
        {
            curl_setopt($ci, CURLOPT_POST, TRUE);
            if (!empty($postfields)) 
            {
                curl_setopt($ci, CURLOPT_POSTFIELDS, http_build_query($postfields));
            }
        }
        curl_setopt($ci, CURLOPT_URL, $url );
        if (!empty($headers))
        {
            curl_setopt($ci, CURLOPT_HTTPHEADER, $headers);
        }
        $response = curl_exec($ci);
        
        if ($response === false)
        {
            $message = curl_error($ci);
            $code = curl_errno($ci);
            curl_close($ci);
            $this->error_ .= "code=$code,message:$message";
            return false;
        }
        
        //$http_code = curl_getinfo($ci, CURLINFO_HTTP_CODE);
        //$http_info = curl_getinfo($ci);
        curl_close ($ci);
        return $this->parseResponse($response);
    }
}

$test = lua_redis::getInstance();
$ret = $test->mset('k1','v1','k2','v2','k3');
var_dump($ret);

$ret = $test->mset('k1','v1','k2','v2');
var_dump($ret);

$ret = $test->mget('k1','k2','k3');
print_r($ret);

$test->pipeline('mset','k1','v1','k2','v2');
$test->pipeline('mget','k1','k2','k3');
$ret = $test->commit();
print_r($ret);
