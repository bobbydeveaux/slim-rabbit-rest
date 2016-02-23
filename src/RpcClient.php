<?php

namespace DVO\SlimRabbitRest;

use PhpAmqpLib\Connection\AMQPConnection;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * DVO RPC Client
 *
 * Perhaps this needs to confirm to some PSR..
 * But it's AMQP/RPC, and is not HTTP at all.
 *
 * Usage: $RpcClient->get($path, $params)
 * e.g. $RpcClient->get('/user/1', [])
 *      $RpcClient->post('/user', ['name' => 'bob'])
 *      $RpcClient->put('/user/1', ['name' => 'bobby'])
 *
 **/
class RpcClient
{
    protected $connection;
    protected $channel;
    protected $callback_queue;
    protected $response;
    protected $corr_id;

    public function __construct(AMQPConnection $connection)
    {
        $this->connection = $connection;
        $this->channel    = $this->connection->channel();
        list($this->callback_queue, ,) = $this->channel->queue_declare(
            "",
            false,
            false,
            true,
            false
        );
        $this->channel->basic_consume(
            $this->callback_queue,
            '',
            false,
            false,
            false,
            false,
            [$this, 'onResponse']
        );
    }

    public function onResponse($rep)
    {
        if ($rep->get('correlation_id') == $this->corr_id) {
            $this->response = $rep->body;
        }
    }

    protected function call($method, $path, $data)
    {
        $body     = json_encode($data);
        $message  = json_encode([
            'method'     => $method,
            'path'       => $path,
            'parameters' => $data,
            'content'    => $body
        ]);

        $this->response = null;
        $this->corr_id  = uniqid();

        $msg = new AMQPMessage(
            (string) $message,
            [
                'correlation_id' => $this->corr_id,
                'reply_to'       => $this->callback_queue
            ]
        );
        $this->channel->basic_publish($msg, '', 'rpc_queue');

        while (!$this->response) {
            $this->channel->wait();
        }

        return $this->response;
    }

    public function get($path = '/', $data = [])
    {
        return $this->call('GET', $path, $data);
    }

    public function post($path = '/', $data = [])
    {
        return $this->call('POST', $path, $data);
    }

    public function put($path = '/', $data = [])
    {
        return $this->call('PUT', $path, $data);
    }

    public function delete($path = '/', $data = [])
    {
        return $this->call('DELETE', $path, $data);
    }
}
