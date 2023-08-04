<?php
/*
 * @author: 布尔
 * @name: 通用函数
 * @desc: 介绍
 * @LastEditTime: 2023-07-06 09:36:31
 */

declare(strict_types=1);

use Eykj\AliyunSls\ClientInterface;
use function Hyperf\Support\env;

if (!function_exists('alog')) {
    /**
     * @brief 阿里云日志
     * @param array $data 日志内容
     * @param int $type 类型 0 INFO:打印输出 1 WARRING:警告 2 ERROR:错误日志 3 CALLBACK:回调日志  4 SQL:数据库日志 5 SOCKET:socket日志 
     */
    function alog($data, int $type = 0): void
    {
        switch ($type) {
            case '0':
                $k = 'INFO::';
                break;
            case '1':
                $k = 'WARRING::';
                break;
            case '2':
                $k = 'ERROR::';
                break;
            case '3':
                $k = 'CALLBACK::';
                break;
            case '4':
                $k = 'SQL::';
                break;
            case '5':
                $k = 'SOCKET::';
                break;
            case '6':
                $k = 'MQTT::';
                break;

            default:
                $k = 'SQL::';
                break;
        }
        /* 添加阿里云sls日志 */
        if (is_array($data) || is_object($data)) {
            $data = json_encode($data, 320);
        }
        if (env('ALIYUN_SLS_ENDPOINT')) {
            try{
                container()->get(ClientInterface::class)->putLogs([$k => $data]);
            }catch (\Throwable $th) {

            }
        }
    }
}
