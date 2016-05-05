local json = require("cjson")

local _M = {}
_M._VERSION = '0.1'

function _M.split(self, str , delim)
    if type(delim) ~= "string" or string.len(delim) <= 0 then
        return {str}
    end 
    local start = 1 
    local t = {}
    while true do
        local pos = string.find (str, delim, start, true) -- plain find
        if not pos then
          break
        end 
    
        table.insert (t, string.sub (str, start, pos - 1)) 
        start = pos + string.len (delim)
    end 
    table.insert (t, string.sub (str, start))
   
    return t
end


function _M.json_decode(self, str )
    local json_value = nil
    pcall(function (str) json_value = json.decode(str) end, str)
    return json_value
end

function _M.json_encode(self, object)
    return json.encode(object)
end
return _M
