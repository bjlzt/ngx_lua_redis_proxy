<?php
/***************************************************************************
 *
 * Copyright (c) 2016 ByteDance.com, Inc. All Rights Reserved
 *
 **************************************************************************/
/**
 *@file LuaRedis.php
 *
 *@brief 请求redis-proxy类: 此类发起api请求，获取redis命令原生结果（和redis扩展结果不一样）,返回值需要自己处理：具体命令返回值参考redis手册
 *
 *@author liuzhantao@bytedance.com
 *@date   2016-05-08
 *@update 2016-05-08
 *
 */
/*
demo : 
    $test = RedisProxy::getInstance();
    $ret = $test->set('k1','v1');
    $ret = $test->get('k1');
    $test->mset('k1','v1','k2','v2');
    $test->mget('k1','k2','k3','k4');
    //pipeline
    $test->pipeline('mset','k1','v1','k2','v2');
    $test->pipeline('mget','k1','k2','k3');
    $ret = $test->commit();
*/
class RedisProxy
{
    private $url_ = 'http://10.4.18.124/rds';
    private $db_ = 1;
    private $auth_ = null;
    private $debug_ = 0;
    private $srv_ = 'duoshuo';
    private $error_ = null;
    public $commands_ = [];
    static private $instance_ = null;
    public static function getOne()
    {
        return self::getInstance();
    }
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
        $result = $this->getReq($url);
        return $this->parseGetReq($func,$result);
    }
    /**
     *@desc 解析命令返回值
     */
    private function parseGetReq($command,$result)
    {
        if (is_array($result) && empty($result))
        {
            return $result;
        }
        $cmd = strtolower($command);
        $ret = $result;
        $method = 'parse_'.$cmd;
        if (method_exists($this,$method))
        {
            $ret = $this->$method($result);
        }
        return $ret;
    }
    /**
     *@desc 解析zrevrangebyscore命令返回值
     */
    private function parse_zrevrangebyscore($params)
    {
        $ret = []; 
        foreach($params as $k=>$v)
        {
            if ($k % 2 == 0)
            {
                $ret[$v] = $params[$k+1];
            }
        }
        return $ret;
    }
    private function parse_exists($p)
    {
        return $p === 1 ? true : false; 
    }
    /**
     *@desc pipeline调用,要和commit配合使用
     */
    public function pipeline()
    {
        $this->commands_[] = func_get_args(); 
    }
    /**
     *@desc pipeline调用,要和pipeline配合使用
     */
    public function commit()
    {
        if (empty($this->commands_))
        {
            $this->error_ .= '没有传递参数。';
            return false;
        }
        $data['pipeline'] = json_encode($this->commands_);
        $this->commands_ = [];
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
    /**
     *@desc 调用post方法
     */
    public function post($url,$data,$headers = [])
    {
        $ret = $this->http($url,'POST',$data,$headers);
        return $ret;
    }
    /**
     *@desc 解析redis-proxy返回的reponse
     */
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
    /**
     *@desc 生成query string
     */
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
    /**
     *@desc http request
     */
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
