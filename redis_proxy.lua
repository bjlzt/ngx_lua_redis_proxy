local _M = {}

_M._VERSION = '0.1'
-- 是否是写操作
_M.is_write = false
-- 是否记录错误日志
_M.log = true
-- 使用的db
_M.db = 0
-- 支持的最大db编号
_M.max_db_num = 16
-- 默认超时时间
_M.default_timout = 1000
-- 当前操作使用的redis节点
_M.current_node = {}

-- 连接池设置
_M.enable_pool = true
_M.max_idle_timeout = 10000
_M.pool_size = 100

-- 最小命令长度
local MIN_CMD_LEN = 3

-- 错误代码
local E_SUCC = 0
local E_FORBIDDEN_CMD = 1  
local E_INVALID_CMD = 2
local E_CONNECT_FAIL = 3
local E_INVALID_DB = 4
local E_SELECT_DB_FAIL = 5
local E_EXEC_FAIL = 6

_M.nodes = {
    read={
        {addr="127.0.0.1",port=6379,timeout=1000},
        {addr="127.0.0.1",port=6378,timeout=1000},
        --{addr="unix:/path/to/unix.sock",port=nil,timeout=1000},
    },
    write={
        {addr="127.0.0.1",port=6378,timeout=1000},
        --{addr="unix:/path/to/unix.sock",port=nil,timeout=1000},
    }
}

local mt = { __index = _M }

-- 所有写命令
local w_commands = {
    brpoplpush=1,rpush=1,rpoplpush=1,rpushx=1,sadd=1,
    hdel=1,hincrby=1,hincrbyfloat=1,hsetnx=1,hset=1,
    incr=1,incrby=1,incrbyfloat=1,decr=1,decrby=1,del=1,expire=1,expireat=1,append=1,set=1,
    setex=1,setnx=1,setrange=1,
    linsert=1,lpush=1,lpushx=1,lrem=1,lset=1,ltrim=1,
    migrate=1,move=1,
    mset=1,msetnx=1,psetex=1,
    persist=1,pexpire=1,pexpireat=1,
    rename=1,renamenx=1,
    setbit=1,bitop=1,
    smove=1,spop=1,srem=1,
    pipeline=1,
}
-- 禁止执行的命令
local forbidden_commands = {
    client=1,config=1,bgsave=1,bgrewriteaof=1,eval=1,flushall=1,flushdb=1,save=1,
    script=1,sync=1,

}

function _M.new(self)
    return setmetatable({}, mt) 
end

-- 检查是否是一个允许执行的命令
function _M.check_is_forbid(self,cmd)
    return forbidden_commands[cmd] == 1
end

-- 检查是否是一个写操作
function _M.check_is_write(self,cmd)
    self.is_write = w_commands[cmd] == 1
    return self.is_write
end

-- 根据命令选择一个redis服务器
function _M.get_redis_addr(self,cmd)
    -- 根据cmd参数来判断是读还是写
    local is_write = self:check_is_write(cmd)

    local nodes = {}
    if is_write then
        nodes = self.nodes["write"]
    else
        nodes = self.nodes["read"]
    end

    local index = math.random(1,#nodes)
    return nodes[index]
end
-- luajit不能变长参数，只能解释执行。为了不影响性能，把参数以table形式传过来
-- function _M.redis_proxy(self , cmd , ...) 
function _M.redis_proxy(self , cmd , params)
    if string.len(cmd) < MIN_CMD_LEN then
        return E_INVALID_CMD,nil,"invalid cmd name:" .. cmd
    end
    if self:check_is_forbid(cmd) then
        return E_FORBIDDEN_CMD,nil,"cmd is not allowed to exec:" .. cmd
    end

	local redis = require "resty.redis"
	local red = redis:new()

    -- 检查是否是有效命令
    local func = ""
    local not_pipeline = (cmd ~= "pipeline" and ngx.var.request_method == 'GET' )
    if not_pipeline  then
        func = red[cmd]	
        if nil == func then
            if self.log then
	            ngx.log( ngx.ERR , "invalid cmd name:" .. cmd)
            end
            return E_INVALID_CMD,nil,"invalid cmd name:" .. cmd
        end
    end
    -- 获取地址、配制	
    local server = self:get_redis_addr(cmd)	
    self.current_node = server

    -- 把db做为pool name的一部分
    local option = {pool=""}
    option.pool = server.addr .. server.port .. self.db

    local timeout = server.timeout or 1000
	red:set_timeout(timeout) -- 1 sec
	local ok, err = false,nil
    -- unix-socket
    if server.port == nil then
        ok,err = red:connect(server.addr, option)
    else
        ok,err = red:connect(server.addr, server.port, option)
    end
	if not ok then
        if self.log then
	        ngx.log( ngx.ERR,"failed to connect: ".. err)
        end
	    return E_CONNECT_FAIL,ok,err
	end

    if self.db > self.max_db_num then
        return E_INVALID_DB,nil,"ERR invalid DB index:" .. self.db ..",supported max DB index : " .. self.max_db_num
    end
    
    ok, err = red:select(self.db)
    if not ok then
        if self.log then
	        ngx.log(ngx.ERR ,"failed to select db: ".. err)
        end
        return E_SELECT_DB_FAIL,ok,err
    end
    local res = ""
    if not_pipeline then
        res,err = func(red, unpack(params))
	    if not res then
            if self.log then
	            ngx.log(ngx.ERR ,"failed to exec func: " .. err)
            end
            return E_EXEC_FAIL,res,err
        end 
    else
        -- pipeline处理
        red:init_pipeline()
        for k,v in pairs(params) do 
            local command = table.remove(v,1)
            func = red[command]
            if nil == func then
                red:cancel_pipeline()
                if self.log then
                    ngx.log( ngx.ERR , "invalid cmd name in pipeline:" .. command)
                end 
                return E_INVALID_CMD,nil,"invalid cmd name in pipeline:" .. command 
            end 
            func(red, unpack(v))
        end
        res, err = red:commit_pipeline()
        if not res then
            if self.log then
	            ngx.log(ngx.ERR ,"failed to exec pipeline: " .. err)
            end
            return E_EXEC_FAIL,res,err
        end
    end
    -- 启用连接池
    local ret = ""
    if self.enable_pool then
	    local ok, ret = red:set_keepalive(self.max_idle_timeout, self.pool_size)
    else
        local ok, ret = red:close()
    end
	if not ok and self.log then
	    ngx.log(ngx.ERR ,"failed to set keepalive or close: ".. ret)
	end
    return E_SUCC,res,err
end

function _M.select(self,dbNum)
    self.db = dbNum
end

return _M
