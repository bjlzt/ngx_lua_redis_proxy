local funcs = require "functions"
local redis_proxy = require "redis_proxy"
local var = ngx.var

local rds = redis_proxy:new()
-- 是否返回redis server信息
local is_ret_server = false

-- 返回信息结构
local ret = {
    msg="OK",
    code= 0,
    response="",
}

-- 获取参数中的db信息
if var.is_args == "?" then
    local uri_args = ngx.req.get_uri_args()
    if uri_args.db ~= nil then
        local db = tonumber(uri_args.db)
        rds:select(db)
    end
end

local method = var.request_method
local code,res,err = 0,"",""

if "GET" == method then
    local uri = var.request_uri 
    if var.is_args == "?" then
        uri = funcs:split(uri,"?")[1]
    end
	local params = funcs:split(uri,"/")
	-- 一个有效的请求，拆分之分，table长度至少为3,第三个元素为redis命令
    if #params < 3 then
        code,res,err = 7,"","invalid request"
    else
        --去掉前两个元素
        table.remove(params,1)
        table.remove(params,1)
	    local cmd = table.remove(params,1)
	    code,res,err = rds:redis_proxy(cmd,unpack(params));
    end


end
-- 使用post时，认为是使用了pipeline
if "POST" == method then
    ngx.req.read_body()
    local post_args = ngx.req.get_post_args()
    if post_args.pipeline ~= nil then
        local commands = funcs:json_decode(post_args.pipeline)
        if commands ~= nil then
            code,res,err = rds:redis_proxy("pipeline",commands);
        else
            code,res,err = 9,"","wrong commands given"
        end
    else
        code,res,err = 8,"","no command given"
    end
end

ret.code = code
ret.msg = err
ret.response = res

-- server info
if is_ret_server then
    ret.server = rds.current_node
    ret.cmd = cmd
    ret.op = "read"
    if rds.is_write then
        ret.op = "write"
    end
end

ngx.say(funcs:json_encode(ret))

