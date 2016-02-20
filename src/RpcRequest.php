<?php
/**
 * A Slim 3 middleware enabling RabbitMQ to communicate via 'REST'
 * to reduce HTTP overhead.
 *
 * @link        https://github.com/dvomedi/slim-rabbit-rest
 * @copyright   Copyright © 2016 dvomedia
 * @author      Bobby DeVeaux (@bobbyjason), thanks to @pavlakis for the help.
 * @license     https://github.com/dvomedi/slim-rabbit-rest/blob/master/LICENSE (BSD 3-Clause License)
 */
namespace DVO\SlimRabbitRest;


use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use PhpAmqpLib\Connection\AMQPConnection;
use PhpAmqpLib\Message\AMQPMessage;

use Slim\Http;

class RpcRequest
{

    /**
     * @var ServerRequestInterface
     */
    protected $request = null;
    protected $response = null;

    protected $amqp = null;

    // YUCK
    protected $app = null;

    /**
     * undocumented function
     *
     * @return void
     * @author 
     **/
    public function __construct(\Slim\App $app, \AMQPConnection $amqp)
    {
    	$this->amqp = $amqp;
    	$this->app = $app;
    }

    /**
     * Exposed for testing.
     * @return ServerRequestInterface
     */
    public function getRequest()
    {
        return $this->request;
    }

        /**
     * Exposed for testing.
     * @return ServerResponseInterface
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * Get a value from an array if exists otherwise return a default value
     *
     * @param   array   $argv
     * @param   integer $key
     * @param   mixed   $default
     * @return  string
     */
    private function get($argv, $key, $default = '')
    {
        if (!array_key_exists($key, $argv)) {
            return $default;
        }

        return $argv[$key];
    }

    /**
     * Construct the URI if path and params are being passed
     *
     * @param string $path
     * @param string $params
     * @return string
     */
    private function getUri($path, $params)
    {
        $uri = '/';
        if (strlen($path) > 0) {
            $uri = $path;
        }

        if (strlen($params) > 0) {
            $uri .= '?' . $params;
        }

        return $uri;
    }

    /**
     * Invoke middleware
     *
     * @param  ServerRequestInterface   $request  PSR7 request object
     * @param  ResponseInterface        $response PSR7 response object
     * @param  callable                 $next     Next middleware callable
     *
     * @return ResponseInterface PSR7 response object
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, callable $next)
    {
        $this->request = $request;
        $this->response = $response;

        if ("/rpcserver" === (string) $this->request->getUri())
        {
	        $channel = $this->amqp->channel();
	        $channel->queue_declare('rpc_queue', false, false, false, false);

	        echo "\n [x] Awaiting RPC requests\n";

	        $channel->basic_qos(null, 1, null);
	        $channel->basic_consume('rpc_queue', '', false, false, false, false, [$this, 'callback']);

	        while (count($channel->callbacks)) {
	            $channel->wait();
	        }

	        $channel->close();
	        $this->amqp->close();
	    }

        return $next($this->request, $response);
    }

    /**
     * Callback from the rpcserver when it receives a messaged
     * seperate function to help with testing.
     *
     * @return void
     * @author 
     **/
    public function callback($req)
    {
        $message = json_decode($req->body, true);
        echo " [.] " . print_r($message, true) . "\n";

        $this->request = self::createFromEnvironment(\Slim\Http\Environment::mock([
            'REQUEST_METHOD'    => $message['method'],
            'REQUEST_URI'       => $this->getUri($message['path'], http_build_query($message['parameters'])),
            'QUERY_STRING'      => http_build_query($message['parameters']),
            'CONTENT_TYPE'      => 'application/json',
        ]), $message['content']);

        $this->response = $this->app->process($this->request, $this->response);

        $this->sendResponse($req);
    }

    /**
     * Send the response back to the correlation ID
     * called from $this->callback
     *
     * @return void
     * @author 
     **/
    public function sendResponse($req)
    {
    	$msg = new AMQPMessage(
            $this->response,
            ['correlation_id' => $req->get('correlation_id')]
        );

        $req->delivery_info['channel']->basic_publish(
            $msg,
            '',
            $req->get('reply_to')
        );
        $req->delivery_info['channel']->basic_ack(
            $req->delivery_info['delivery_tag']
        );
    }

    /**
     * Create new HTTP request with data extracted from the application
     * Environment object
     *
     * @param  Environment $environment The Slim application Environment
     *
     * @return self
     */
    public static function createFromEnvironment(\Slim\Http\Environment $environment, string $body)
    {
        $method = $environment['REQUEST_METHOD'];
        $uri = \Slim\Http\Uri::createFromEnvironment($environment);
        $headers = \Slim\Http\Headers::createFromEnvironment($environment);
        $cookies = \Slim\Http\Cookies::parseHeader($headers->get('Cookie', []));
        $serverParams = $environment->all();
        file_put_contents('php://filter/write=string.rot13/resource=body.txt', $body);
        $body = new \Slim\Http\Body(fopen('php://filter/read=string.rot13/resource=body.txt', 'r'));

        $uploadedFiles = \Slim\Http\UploadedFile::createFromEnvironment($environment);

        $request = new \Slim\Http\Request($method, $uri, $headers, $cookies, $serverParams, $body, $uploadedFiles);

        if ($method === 'POST' &&
            in_array($request->getMediaType(), ['application/x-www-form-urlencoded', 'multipart/form-data'])
        ) {
            // parsed body must be $_POST
            $request = $request->withParsedBody($_POST);
        }

        return $request;
    }
}
