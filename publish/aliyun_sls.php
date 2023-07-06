<?php
/*
 * @author: 布尔
 * @name: 配置参数
 * @desc: 介绍
 * @LastEditTime: 2023-07-06 09:37:17
 * @FilePath: \aliyun-sls\publish\aliyun_sls.php
 */
declare(strict_types=1);
use function Hyperf\Support\env;

return [
    'endpoint' => env('ALIYUN_SLS_ENDPOINT', 'cn-beijing.log.aliyuncs.com'),
    'access_key' => env('ALIYUN_SLS_AK', ''),
    'secret_key' => env('ALIYUN_SLS_SK', ''),
    'project' => env('ALIYUN_SLS_PROJECT', ''),
    'logstore' => env('ALIYUN_SLS_LOGSTORE', ''),
];
