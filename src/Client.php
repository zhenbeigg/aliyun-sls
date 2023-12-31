<?php
namespace Eykj\AliyunSls;


use Hyperf\Contract\ConfigInterface;
use Hyperf\Guzzle\ClientFactory as GuzzleClientFactory;
use Psr\Container\ContainerInterface;
use Eykj\AliyunSls\Request\PutLogsRequest;
use Eykj\AliyunSls\Response\PutLogsResponse;

class Client implements ClientInterface
{
    /**
     * API版本
     */
    const API_VERSION='0.6.0';
    /**
     * @var string aliyun accessKeyId
     */
    protected $accessKeyId;

    /**
     * @var string aliyun accessKeySecret
     */
    protected $accessKeySecret;

    /**
     * @var string LOG endpoint
     */
    protected $endpoint;

    /**
     * @var Closure
     */
    private $client;

    /**
     * @var ConfigInterface
     */
    private $config;

    public function __construct(ContainerInterface $container)
    {
        $this->client = $container->get(GuzzleClientFactory::class)->create();
        $this->config = $container->get(ConfigInterface::class);
    }

    /**
     * GMT format time string.
     *
     * @return string
     */
    protected function getGMT() {
        return gmdate( 'D, d M Y H:i:s' ) . ' GMT';
    }

    /**
     * parseToJson
     * Decodes a JSON string to a JSON Object.
     * Unsuccessful decode will cause an RuntimeException.
     * User：YM
     * Date：2019/12/30
     * Time：下午3:28
     * @param $resBody
     * @param $requestId
     * @return mixed|null
     */
    protected function parseToJson($resBody, $requestId)
    {
        if (!$resBody) {
            return NULL;
        }
        $result = json_decode($resBody, true );
        if ($result === NULL){
            throw new \RuntimeException ( "Bad format,not json;requestId:{$requestId}");
        }

        return $result;
    }

    /**
     * 请求处理响应
     * @param $method
     * @param $url
     * @param $body
     * @param $headers
     * @return array
     */
    public function sendRequest($method, $url, $body, $headers)
    {
        try {
            $response = $this->client->request($method,$url,['body'=>$body,'headers' => $headers]);
            $responseCode = $response->getStatusCode();
            $header = $response->getHeaders();
            $resBody = (string)$response->getBody();
        } catch (Exception $e) {
            throw new \RuntimeException($e->getMessage(),$e->getCode());
        }
        $requestId = isset($header['x-log-requestid'])?$header ['x-log-requestid'] : '';
        if ($responseCode == 200) {
            return [$resBody,$header];
        }
        $exJson = $this->parseToJson($resBody, $requestId);
        if (isset($exJson['error_code']) && isset($exJson['error_message'])) {
            throw new \RuntimeException("{$exJson['error_message']};requestId:{$requestId}", $exJson['error_code']);
        }
        if ($exJson) {
            $exJson = 'The return json is '.json_encode($exJson);
        } else {
            $exJson = '';
        }
        throw new \RuntimeException("Request is failed. Http code is {$responseCode}.{$exJson};requestId:{$requestId}");
    }

    /**
     * 组合请求公共数据
     * @param $method
     * @param $project
     * @param $body
     * @param $resource
     * @param $params
     * @param $headers
     * @return array
     */
    public function send($method, $project, $body, $resource, $params, $headers)
    {
        $accessKey = $this->config->get('aliyun_sls.access_key', '');
        $secretKey = $this->config->get('aliyun_sls.secret_key', '');
        $endpoint = $this->config->get('aliyun_sls.endpoint', '');
        if ($body) {
            $headers['Content-Length'] = strlen ($body);
            $headers["x-log-bodyrawsize"] = $headers["x-log-bodyrawsize"]??0;
            $headers['Content-MD5'] = LogUtil::calMD5($body);
        } else {
            $headers['Content-Length'] = 0;
            $headers["x-log-bodyrawsize"] = 0;
            $headers['Content-Type'] = '';
        }
        $headers['x-log-apiversion'] = self::API_VERSION;
        $headers['x-log-signaturemethod'] = 'hmac-sha1';
        $host = is_null($project)?$endpoint:"{$project}.{$endpoint}";
        $headers['Host'] = $host;
        $headers['Date'] = $this->getGMT();
        $signature = LogUtil::getSignature($method, $resource, $secretKey, $params, $headers);
        $headers['Authorization'] = "LOG $accessKey:$signature";
        $url = "http://{$host}{$resource}";
        if ($params) {
            $url .= '?' . LogUtil::urlEncode($params);
        }

        return $this->sendRequest($method, $url, $body, $headers);
    }

    /**
     * User：YM
     * @param array $contents
     * @param string $topic
     * @param null $project
     * @param null $logstore
     * @param null $shardKey
     * @return mixed
     */
    public function putLogs(array $contents = [], $topic = '', $project = null, $logstore = null,$shardKey=null)
    {
        $project = $project?:$this->config->get('aliyun_sls.project', '');
        $logstore = $logstore?:$this->config->get('aliyun_sls.logstore', '');
        $source = LogUtil::getLocalIp();
        $logItems = array(make(LogItem::class,[time(),$contents]));
        $request = make(PutLogsRequest::class,[$project,$logstore,$topic,$source,$logItems,$shardKey]);

        if (count($request->getLogItems()) > 4096) {
            throw new \RuntimeException('PutLogs 接口每次可以写入的日志组数据量上限为4096条!');
        }
        $logGroup = make(LogGroup::class);
        $logGroup->setTopic($request->getTopic());
        $logGroup->setSource($request->getSource());
        foreach ($request->getLogItems() as $logItem ) {
            $log = make(Log::class);
            $log->setTime($logItem->getTime());
            $contents = $logItem->getContents();
            foreach($contents as $key => $value ) {
                $content = make(LogContent::class);
                $content->setKey($key);
                $content->setValue($value);
                $log->addContents($content);
            }
            $logGroup->addLogs($log);
        }
        $body = LogUtil::toBytes($logGroup);
        unset($logGroup);
        $bodySize = strlen($body);
        if ($bodySize > 3 * 1024 * 1024) {
            throw new \RuntimeException('PutLogs 接口每次可以写入的日志组数据量上限为3MB!');
        }
        $params = [];
        $headers = [];
        $headers["x-log-bodyrawsize"] = $bodySize;
        $headers['x-log-compresstype'] = 'deflate';
        $headers['Content-Type'] = 'application/x-protobuf';
        if($shardKey) {
            $headers["x-log-hashkey"] = $shardKey;
        }
        $body = gzcompress($body, 6 );
        $resource = "/logstores/" . $request->getLogstore()."/shards/lb";
        list($resp,$header) = $this->send("POST", $project, $body, $resource, $params, $headers);
        $requestId = isset($header['x-log-requestid']) ? $header['x-log-requestid'] : '';
        $resp = $this->parseToJson($resp, $requestId);
        return make(PutLogsResponse::class,[$header]);
    }

}