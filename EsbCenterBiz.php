<?php

namespace app\api\Biz\Http;

use Carbon\Carbon;
use EasyWeChat\Kernel\Support\XML;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use think\Collection;
use think\Exception;
use think\facade\Log;
use function EasyWeChat\Kernel\Support\get_encrypt_method;

class EsbCenterBiz
{
    const URL = 'http://exchange.highstore.cn/esbcenter/api/esb/';

    protected Collection $config;

    protected Client $client;

    /**
     * ShipService constructor.
     * @param array $config
     * @throws Exception
     */
    public function __construct(array $config = [])
    {
        !empty($config) && $this->setConfig($config);

        $stack = new HandlerStack();

        $stack->setHandler(new CurlHandler());

        $stack->push(Middleware::mapRequest(function (RequestInterface $request) {
            Log::channel('erp')->INFO("Api:request Path:{$request->getUri()->getPath()} Query:{$request->getUri()->getQuery()} Method:{$request->getMethod()} Body:{$request->getBody()->getContents()}");
            return $request;
        }));

        $stack->push(Middleware::mapResponse(function (ResponseInterface $response) {
            Log::channel('erp')->INFO("Api:response Status:{status} Body:{body}", ['status' => $response->getStatusCode(), 'body' => $response->getBody()->getContents()]);
            return $response;
        }));

        $this->client = new Client(['base_uri' => self::URL, 'handler' => $stack, 'verify' => false]);
    }

    /**
     * @param array $config
     * @return void
     * @throws Exception
     */
    public function setConfig(array $config): void
    {
        $requiredFields = ['app_key', 'customer_id', 'secret'];
        foreach ($requiredFields as $field) {
            if (empty($config[$field])) {
                throw new Exception("$field can not be empty");
            }
        }
        $this->config = collect(array_merge($config, ['version' => $config['version'] ?? 1.0]));
    }

    /**
     * @return Collection
     */
    public function getConfig(): Collection
    {
        return $this->config;
    }



    /**
     * @param array $params
     * @param string $secretKey
     * @param string $body
     * @param mixed $encryptMethod
     * @return string
     */
    public static function sign(array $params, string $secretKey, string $body, $encryptMethod = 'md5'): string
    {
        ksort($params);
        return strtoupper(
            call_user_func_array(
                $encryptMethod,
                [$secretKey . str_replace('&', '', http_build_query($params)) . $body . $secretKey]
            )
        );
    }

    public function createDeliveryOrder(array $params)
    {
        return $this->request('deliveryorder.create',$params);
    }

    /**
     * @param string $apiMethod
     * @param array $params
     * @param string $method
     * @param string $format
     * @param string $encryptMethod
     * @return void
     * @throws GuzzleException
     * @throws \Exception
     */
    public function request(string $apiMethod, array $params, string $method = 'POST', string $format = 'xml', string $encryptMethod = 'md5')
    {

        $body = call_user_func([$this,$format], $params,'<?xml version="1.0" encoding="utf-8"?>');

        $secretKey = $this->config->offsetGet('secret');

        $sign = self::sign($baseParams = [
            'method'      => $apiMethod,
            'timestamp'   => (string) Carbon::now(),
            'format'      => $format,
            'app_key'     => $this->config->offsetGet('app_key'),
            'v'           => $this->config->offsetGet('version'),
            'sign_method' => $encryptMethod,
            'customerId'  => $this->config->offsetGet('customer_id'),
        ], $secretKey, $body, get_encrypt_method($encryptMethod,$secretKey));

        $baseParams['sign'] = $sign;

        /**
         * @var ResponseInterface $response
         */
        $response = retry(3, function () use ($method, $baseParams, $body) {
            $response = $this->client->request($method, '?'.urlencode(http_build_query($baseParams)), ['body' => $body]);
            $response->getBody()->rewind();
            return $response;
        },3);

        dd($response->getBody()->getContents());
    }

    /**
     * @param array $attributes
     * @param string $header
     * @return string
     */
    public function xml(array $attributes,string $header = ''): string
    {
        return $header . XML::build($attributes, 'request');
    }
}
