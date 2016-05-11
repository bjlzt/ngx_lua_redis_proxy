# ngx_lua_redis_proxy

redis proxy : 提供读写分离功能

使用pipeline时，需要使用POST方式： 

curl 'http://lua.com:8888/rds' -d 'pipeline=[["set","k","v"],["set","kk","vv"],["get","k"]]'

返回值：
{
  "code":0,
  "response":[
    "OK",
    "OK",
    "v"]
}

一般命令使用GET方式调用：


http://lua.com:8888/rds/mset/k1/v1/k2/v2/k3/v3/k4/bbbbb/k5/bbb

{
  code: 0,
  response: "OK"
}


http://lua.com:8888/rds/mget/k1/k2/k3/k8

{
  code: 0,
  response: [
    "v1",
    "v2",
    "v3",
    null
  ]
}


url中所有参数都是可选的。


如果redis设置了auth，参数中加auth=password,如http://lua.com:8888/rds/mget/k1/k2/k3/k8?auth=password

如果需要选择不同的库，参数中加db=dbNum ,如http://lua.com:8888/rds/mget/k1/k2/k3/k8?db=1


http://lua.com:8888/rds/mget/k1/k2/k3/k8?db=1&auth=password


url中使用debug=1，打印调试信息

url中使用srv=product_name，不同的业务使用不同的redis集群，默认为default


完整的url: 

http://lua.com:8888/rds/mget/k1/k2/k3/k8?db=1&auth=password&debug=1&srv=my_product
