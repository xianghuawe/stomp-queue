<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Webman\Stomp;

use Workerman\Stomp\Client;
use Webman\Stomp\AmqpLib\Enforcer;

class StompClient extends Client
{
    /**
     * @var string
     */
    public $_configName = '';

    public function __construct($address, $options = [])
    {
        parent::__construct($address, $options);
    }

    /**
     * @param $namespace
     * @param $queueName
     * @return string
     */
    protected function getQueuePath($namespace, $queueName)
    {
        // /amq/queue/[queue_name]
        return "/amq/queue/{$namespace}.{$queueName}";
    }

    /**
     * @param $name
     * @param $queueName
     * @return string
     */
    protected function getExchangePath($name, $queueName)
    {
        // /exchange/[exchangename]/[routing_key]
        $exchangename = Enforcer::$_namespace[$name] . '.' . Enforcer::$_amqpConfig[$name]['exchange_name'];
        $routingKey = Enforcer::$_namespace[$name] . '.' . $queueName;

        return "/exchange/{$exchangename}/{$routingKey}";
    }

    /**
     * subscribe
     * @param $queueName
     * @param callable $callback
     * @param array $headers
     * @return false|mixed|string
     */
    public function subscribe($queueName, $callback, array $headers = [])
    {
        if ($this->checkDisconnecting()) {
            return false;
        }
        $raw_headers = $headers;
        $headers['id'] = $headers['id'] ?? $this->createClientId();
        $headers['ack'] = $headers['ack'] ?? 'auto';
        $subscription = $headers['id'];

        // generate destination
        $configName = Enforcer::$_queueBelongto[$queueName];
        if (empty($configName)) {
            return false;
        }

        $destination = $this->getQueuePath(Enforcer::$_namespace[$configName], $queueName);

        $headers['destination'] = $destination;

        $package = [
            'cmd' => 'SUBSCRIBE',
            'headers' => $headers
        ];

        $this->sendPackage($package);

        $this->_subscriptions[$subscription] = [
            'ack' => $headers['ack'],
            'callback' => $callback,
            'headers' => $raw_headers,
            'destination' => $destination,
        ];
        return $subscription;
    }

    /**
     * @param $queueName
     * @param $callback
     * @param array $headers
     *
     * @return string
     */
    public function subscribeWithAck($queueName, $callback, array $headers = [])
    {
        if (!isset($headers['ack']) || $headers['ack'] === 'auto') {
            $headers['ack'] = 'client';
        }

        // generate destination
        $configName = Enforcer::$_queueBelongto[$queueName];
        if (empty($configName)) {
            return false;
        }

        $destination = $this->getQueuePath(Enforcer::$_namespace[$configName], $queueName);

        return $this->subscribe($destination, $callback, $headers);
    }

    /**
     * @param $queueName
     * @param $body
     * @param int $delay 单位是秒
     * @param array $headers
     */
    public function send($queueName, $body, $delay = 0, array $headers = [])
    {
        // generate destination
        $configName = Enforcer::$_queueBelongto[$queueName];
        if (empty($configName)) {
            return false;
        }

        if ($delay > 0) {
            $headers['x-delay'] = $delay * 1000;
        }

        $headers['destination'] = $this->getExchangePath($configName, $queueName);

        $headers['content-length'] = strlen($body);
        if (!isset($headers['content-type'])) {
            $headers['content-type'] = 'text/plain';
        }

        $package = [
            'cmd' => 'SEND',
            'headers' => $headers,
            'body' => $body
        ];

        $this->sendPackage($package);
    }
}