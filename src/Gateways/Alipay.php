<?php

namespace Yansongda\Pay\Gateways;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Yansongda\Pay\Contracts\GatewayApplicationInterface;
use Yansongda\Pay\Contracts\GatewayInterface;
use Yansongda\Pay\Exceptions\InvalidGatewayException;
use Yansongda\Pay\Exceptions\InvalidSignException;
use Yansongda\Pay\Gateways\Alipay\Support;
use Yansongda\Pay\Log;
use Yansongda\Supports\Collection;
use Yansongda\Supports\Config;
use Yansongda\Supports\Str;

/**
 * @method Response app(array $config) APP 支付
 * @method Collection pos(array $config) 刷卡支付
 * @method Collection scan(array $config) 扫码支付
 * @method Collection transfer(array $config) 帐户转账
 * @method Response wap(array $config) 手机网站支付
 * @method Response web(array $config) 电脑支付
 */
class Alipay implements GatewayApplicationInterface
{
    /**
     * Config.
     *
     * @var Config
     */
    protected $config;

    /**
     * Alipay payload.
     *
     * @var array
     */
    protected $payload;

    /**
     * Alipay gateway.
     *
     * @var string
     */
    protected $gateway;

    /**
     * Bootstrap.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param Config $config
     */
    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->gateway = Support::baseUri($this->config->get('mode', 'normal'));
        $this->payload = [
            'app_id'      => $this->config->get('app_id'),
            'method'      => '',
            'format'      => 'JSON',
            'charset'     => 'utf-8',
            'sign_type'   => 'RSA2',
            'version'     => '1.0',
            'return_url'  => $this->config->get('return_url'),
            'notify_url'  => $this->config->get('notify_url'),
            'timestamp'   => date('Y-m-d H:i:s'),
            'sign'        => '',
            'biz_content' => '',
        ];
    }

    /**
     * Magic pay.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param string $method
     * @param array  $params
     *
     * @throws InvalidGatewayException
     *
     * @return Response|Collection
     */
    public function __call($method, $params)
    {
        return $this->pay($method, ...$params);
    }

    /**
     * Pay an order.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param string $gateway
     * @param array  $params
     *
     * @throws InvalidGatewayException
     *
     * @return Response|Collection
     */
    public function pay($gateway, $params = [])
    {
        $this->payload['return_url'] = $params['return_url'] ?? $this->payload['return_url'];
        $this->payload['notify_url'] = $params['notify_url'] ?? $this->payload['notify_url'];
        unset($params['return_url'], $params['notify_url']);

        $this->payload['biz_content'] = json_encode($params);

        $gateway = get_class($this).'\\'.Str::studly($gateway).'Gateway';

        if (class_exists($gateway)) {
            return $this->makePay($gateway);
        }

        throw new InvalidGatewayException("Pay Gateway [{$gateway}] not exists");
    }

    /**
     * Verify sign.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param null $content
     * @param bool $refund
     *
     * @throws InvalidSignException
     * @throws \Yansongda\Pay\Exceptions\InvalidConfigException
     *
     * @return Collection
     */
    public function verify($content = null, $refund = false): Collection
    {
        $request = Request::createFromGlobals();

        $data = $request->request->count() > 0 ? $request->request->all() : $request->query->all();

        $data = Support::encoding($data, 'utf-8', $data['charset'] ?? 'gb2312');

        Log::debug('Receive Alipay Request:', $data);

        if (Support::verifySign($data, $this->config->get('ali_public_key'))) {
            return new Collection($data);
        }

        Log::warning('Alipay Sign Verify FAILED', $data);

        throw new InvalidSignException('Alipay Sign Verify FAILED', $data);
    }

    /**
     * Query an order.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param string|array $order
     * @param bool         $refund
     *
     * @throws InvalidSignException
     * @throws \Yansongda\Pay\Exceptions\GatewayException
     * @throws \Yansongda\Pay\Exceptions\InvalidConfigException
     *
     * @return Collection
     */
    public function find($order, $refund = false): Collection
    {
        $this->payload['method'] = $refund ? 'alipay.trade.fastpay.refund.query' : 'alipay.trade.query';
        $this->payload['biz_content'] = json_encode(is_array($order) ? $order : ['out_trade_no' => $order]);
        $this->payload['sign'] = Support::generateSign($this->payload, $this->config->get('private_key'));

        Log::debug('Alipay Find An Order:', [$this->gateway, $this->payload]);

        return Support::requestApi($this->payload, $this->config->get('ali_public_key'));
    }

    /**
     * Refund an order.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param array $order
     *
     * @throws InvalidSignException
     * @throws \Yansongda\Pay\Exceptions\GatewayException
     * @throws \Yansongda\Pay\Exceptions\InvalidConfigException
     *
     * @return Collection
     */
    public function refund($order): Collection
    {
        $this->payload['method'] = 'alipay.trade.refund';
        $this->payload['biz_content'] = json_encode($order);
        $this->payload['sign'] = Support::generateSign($this->payload, $this->config->get('private_key'));

        Log::debug('Alipay Refund An Order:', [$this->gateway, $this->payload]);

        return Support::requestApi($this->payload, $this->config->get('ali_public_key'));
    }

    /**
     * Cancel an order.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param string|array $order
     *
     * @throws InvalidSignException
     * @throws \Yansongda\Pay\Exceptions\GatewayException
     * @throws \Yansongda\Pay\Exceptions\InvalidConfigException
     *
     * @return Collection
     */
    public function cancel($order): Collection
    {
        $this->payload['method'] = 'alipay.trade.cancel';
        $this->payload['biz_content'] = json_encode(is_array($order) ? $order : ['out_trade_no' => $order]);
        $this->payload['sign'] = Support::generateSign($this->payload, $this->config->get('private_key'));

        Log::debug('Alipay Cancel An Order:', [$this->gateway, $this->payload]);

        return Support::requestApi($this->payload, $this->config->get('ali_public_key'));
    }

    /**
     * Close an order.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param string|array $order
     *
     * @throws InvalidSignException
     * @throws \Yansongda\Pay\Exceptions\GatewayException
     * @throws \Yansongda\Pay\Exceptions\InvalidConfigException
     *
     * @return Collection
     */
    public function close($order): Collection
    {
        $this->payload['method'] = 'alipay.trade.close';
        $this->payload['biz_content'] = json_encode(is_array($order) ? $order : ['out_trade_no' => $order]);
        $this->payload['sign'] = Support::generateSign($this->payload, $this->config->get('private_key'));

        Log::debug('Alipay Close An Order:', [$this->gateway, $this->payload]);

        return Support::requestApi($this->payload, $this->config->get('ali_public_key'));
    }

    /**
     * Download bill.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param string|array $bill
     *
     * @throws InvalidSignException
     * @throws \Yansongda\Pay\Exceptions\GatewayException
     * @throws \Yansongda\Pay\Exceptions\InvalidConfigException
     *
     * @return string
     */
    public function download($bill): string
    {
        $this->payload['method'] = 'alipay.data.dataservice.bill.downloadurl.query';
        $this->payload['biz_content'] = json_encode(is_array($bill) ? $bill : ['bill_type' => 'trade', 'bill_date' => $bill]);
        $this->payload['sign'] = Support::generateSign($this->payload, $this->config->get('private_key'));

        Log::debug('Alipay Download Bill:', [$this->gateway, $this->payload]);

        $result = Support::requestApi($this->payload, $this->config->get('ali_public_key'));

        return ($result instanceof Collection) ? $result->bill_download_url : '';
    }

    /**
     * Reply success to alipay.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @return Response
     */
    public function success(): Response
    {
        return Response::create('success');
    }

    /**
     * Make pay gateway.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param string $gateway
     *
     * @throws InvalidGatewayException
     *
     * @return Response|Collection
     */
    protected function makePay($gateway)
    {
        $app = new $gateway($this->config);

        if ($app instanceof GatewayInterface) {
            return $app->pay($this->gateway, $this->payload);
        }

        throw new InvalidGatewayException("Pay Gateway [{$gateway}] Must Be An Instance Of GatewayInterface");
    }
}
