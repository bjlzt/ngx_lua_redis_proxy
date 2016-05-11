local _M = {}

_M.nodes = {}

_M.nodes['default'] = { 
    read={
        {addr="10.4.96.12",port=6379,timeout=3000},
        {addr="10.4.96.13",port=6379,timeout=3000},
        --{addr="10.4.18.129",port=6379,timeout=3000},
        --{addr="unix:/path/to/unix.sock",port=nil,timeout=1000},
    },  
    write={
        {addr="10.4.18.129",port=6379,timeout=3000},
        --{addr="unix:/path/to/unix.sock",port=nil,timeout=1000},
    }   
}

local mt = { __index = _M }

return _M
