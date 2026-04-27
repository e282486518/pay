<?php

namespace fengkui;

class SafeRequest
{
    // 是否laravel
    public static function is_laravel(): bool
    {
        if (class_exists(\Illuminate\Support\Facades\App::class) && class_exists(\Illuminate\Support\Facades\Request::class)) {
            return true;
        }
        return false;
    }

    // 是否laravel的console模式
    public static function is_laravel_console(): bool
    {
        if (\Illuminate\Support\Facades\App::runningInConsole()) {
            return true;
        }
        return false;
    }

    // 结束应用
    public static function end()
    {
        if (self::is_laravel()) {
            response()->json([
                'code' => 500,
                'msg' => '系统内部错误'
            ], 500)->send();
        } else {
            die();
        }
    }

    /**
     * 兼容版 - 安全获取SERVER信息
     * 支持：Laravel Octane/Swoole + Laravel PHP-FPM + 纯PHP-FPM/CLI
     * @param string|null $key 键名，null返回所有SERVER信息
     * @param mixed|null $default 键不存在时的默认值
     * @return mixed 单个值/数组
     */
    public static function safe_server(?string $key = null, mixed $default = null): mixed
    {
        // 场景1：Laravel框架环境（含Octane/PHP-FPM）
        if (self::is_laravel()) {
            // 命令行/无HTTP请求，返回空/默认值
            if (self::is_laravel_console()) {
                return $key === null ? [] : $default;
            }

            $request = request();
            $server = $request->server();
            // ==============================================
            // 强制补全：和 PHP-FPM 完全一致的参数（对齐你的返回）
            // ==============================================
            $complete = [
                'REQUEST_SCHEME'   => $server['REQUEST_SCHEME'] ?? $request->getScheme(),
                'HTTPS'            => $server['HTTPS'] ?? $request->isSecure(), // 兼容
                'PHP_SELF'         => $server['PHP_SELF'] ?? '/index.php',
                'QUERY_STRING'     => $server['QUERY_STRING'] ?? $request->getQueryString() ?? '',
            ];
            $fullServer = array_merge($server, $complete);

            if ($key === null) {
                return $fullServer;
            }
            return $fullServer[$key] ?? $default;
        }

        // 场景2：纯PHP环境（无Laravel）
        if (!isset($_SERVER)) {
            return $key === null ? [] : $default;
        }
        if ($key === null) {
            return $_SERVER;
        }
        return $_SERVER[$key] ?? $default;
    }

    /**
     * 兼容版 - 安全获取GET参数（替代原生$_GET）
     * 支持：Laravel Octane/Swoole + Laravel PHP-FPM + 纯PHP-FPM/CLI
     * @param string|null $key 键名，null返回所有GET参数
     * @param mixed|null $default 键不存在时的默认值
     * @return mixed 单个值/数组
     */
    public static function safe_get(?string $key = null, mixed $default = null): mixed
    {
        // 场景1：Laravel框架环境
        if (self::is_laravel()) {
            if (self::is_laravel_console()) {
                return $key === null ? [] : $default;
            }
            return $key === null ? request()->query() : request()->query($key, $default);
        }

        // 场景2：纯PHP环境
        if (!isset($_GET)) {
            return $key === null ? [] : $default;
        }
        if ($key === null) {
            return $_GET;
        }
        return $_GET[$key] ?? $default;
    }

    /**
     * 兼容版 - 安全获取POST参数（替代原生$_POST）
     * 核心特性：兼容form/x-www-form-urlencoded/form-data + JSON提交（原生$_POST不支持JSON）
     * 支持：Laravel Octane/Swoole + Laravel PHP-FPM + 纯PHP-FPM/CLI
     * @param string|null $key 键名，null返回所有POST参数
     * @param mixed|null $default 键不存在时的默认值
     * @return mixed 单个值/数组
     */
    public static function safe_post(?string $key = null, mixed $default = null): mixed
    {
        // 场景1：Laravel框架环境（框架自动解析JSON，直接用post()）
        if (self::is_laravel()) {
            if (self::is_laravel_console()) {
                return $key === null ? [] : $default;
            }
            return $key === null ? request()->post() : request()->post($key, $default);
        }

        // 场景2：纯PHP环境
        if (!isset($_POST)) {
            return $key === null ? [] : $default;
        }

        // 返回结果：单个值/所有值
        if ($key === null) {
            return $_POST;
        }
        return $_POST[$key] ?? $default;
    }


    /**
     * 兼容版 - 安全获取POST JSON参数
     * 核心特性：兼容form/x-www-form-urlencoded/form-data + JSON提交（原生$_POST不支持JSON）
     * 支持：Laravel Octane/Swoole + Laravel PHP-FPM + 纯PHP-FPM/CLI
     * @param string|null $key 键名，null返回所有POST参数
     * @param mixed|null $default 键不存在时的默认值
     * @return mixed 单个值/数组
     */
    public static function safe_post_json(?string $key = null, mixed $default = null): mixed
    {
        // 场景1：Laravel框架环境（框架自动解析JSON，直接用post()）
        if (self::is_laravel()) {
            if (self::is_laravel_console()) {
                return $key === null ? [] : $default;
            }
            return $key === null ? request()->json()->all() : request()->json($key, $default)->all();
        }

        // 场景2：纯PHP环境（手动解析form+JSON，和Laravel行为对齐）
        $postData = [];
        $contentType = self::safe_server('CONTENT_TYPE', '');
        if (!$contentType) {
            $contentType = self::safe_server('HTTP_ACCEPT', '');
        }
        if (str_contains(strtolower($contentType), 'application/json')) {
            // 限制读取1M内的内容，防止大文件攻击，适配生产环境
            $jsonData = file_get_contents('php://input', false, null, 0, 1024 * 1024);
            $postData = json_decode($jsonData, true) ?? [];
        }

        // 返回结果：单个值/所有值
        if ($key === null) {
            return $postData;
        }
        return $postData[$key] ?? $default;
    }
    
    /**
     * 安全获取请求头（兼容 Octane / PHP-FPM / 原生PHP）
     * @param string|null $key 头名称，null 返回所有头
     * @param mixed $default 默认值
     * @return mixed|array
     */
    public static function safe_header(string $key = null, mixed $default = null): mixed
    {
        // 1. Laravel 环境（Octane / FPM 通用，最优方案）
        if (self::is_laravel()) {
            // 命令行无请求，返回空/默认值
            if (self::is_laravel_console()) {
                return $key === null ? [] : $default;
            }

            $headers = request()->headers;
            // key=null 返回所有请求头（数组格式）
            if ($key === null) {
                return $headers->all();
            }
            // 获取指定请求头
            return $headers->get($key, $default);
        }

        // 2. 原生 PHP 环境（兼容传统非Laravel项目）
        $allHeaders = [];
        foreach ($_SERVER as $name => $value) {
            // 只提取 HTTP_ 开头的请求头
            if (str_starts_with($name, 'HTTP_')) {
                // 转换格式：HTTP_WECHATPAY_SIGNATURE → Wechatpay-Signature
                $headerName = str_replace('_', '-', substr($name, 5));
                $headerName = ucwords($headerName, '-');
                $allHeaders[$headerName] = $value;
            }
        }

        // key=null 返回所有头
        if ($key === null) {
            return $allHeaders;
        }

        // 支持两种格式传参：Wechatpay-Signature / WECHATPAY_SIGNATURE
        $keyUc = str_replace('_', '-', strtoupper($key));
        return $allHeaders[$key] ?? $allHeaders[$keyUc] ?? $default;
    }
    
    /**
	 * 仅兼容读取 php://input 原始请求体（Octane/FPM通用）
	 */
	public static function php_input(): string
	{
	    // Laravel 环境(Octane/FPM)：官方标准方法，永不失效
	    if (self::is_laravel()) {
	        return request()->getContent();
	    }
	    // 原生PHP环境
	    return file_get_contents('php://input');
	}

    public static function redirect_url(string $url)
	{
        // 改为
        if (self::is_laravel()) {
            return redirect($url);
        } else {
            header('Location: '. $url);
            die();
        }
	}

    public static function getip()
	{
        // 改为
        if (self::is_laravel()) {
            return request()->ip();
        } else {
	        if (getenv("HTTP_CLIENT_IP"))
	            $ip = getenv("HTTP_CLIENT_IP");
	        else if(getenv("HTTP_X_FORWARDED_FOR"))
	            $ip = getenv("HTTP_X_FORWARDED_FOR");
	        else if(getenv("REMOTE_ADDR"))
	            $ip = getenv("REMOTE_ADDR");
	        else $ip = "Unknow";

	        if(preg_match('/^((?:(?:25[0-5]|2[0-4]\d|((1\d{2})|([1-9]?\d)))\.){3}(?:25[0-5]|2[0-4]\d|((1\d{2})|([1 -9]?\d))))$/', $ip))
	            return $ip;
	        else
	            return '';
        }
	}
}
