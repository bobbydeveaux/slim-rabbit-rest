<?php
namespace DVO\SlimRabbitTest\tests;

use DVO\SlimRabbitRest\RpcRequest;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\Body;
use Slim\Http\Headers;
use Slim\Http\Request;
use Slim\Http\Response;
use Slim\Http\Uri;

class RpcRequestTest extends \PHPUnit_Framework_TestCase
{
    /**
     * Taken from: https://github.com/slimphp/Slim-HttpCache/blob/master/tests/CacheTest.php
     * @return Request
     */
    public function requestFactory()
    {
        $uri = Uri::createFromString('http://localhost/rpcserver');
        $headers = new Headers();
        $cookies = [];
        $serverParams = [];
        $parameters = [];
        $body = new Body(fopen('php://temp', 'r+'));
        return new Request('GET', $uri, $headers, $cookies, $serverParams, $body);
    }

    /**
     * Test the RPC correctly gets response for the requested route
     *
     * @dataProvider providerMessages
     *
     * @return void
     * @author
     **/

    public function testCorrectRouteForRpcServer($data, $expected)
    {
        $req  = $this->requestFactory();
        $res  = new Response();
        $next = function (Request $req, Response $res) {
            return $res;
        };

        /** @var CliRequest $cliRequest */
        $mamqpchan = $this->getMock('\AMQPChannel', ['queue_declare', 'basic_qos', 'basic_consume', 'close'], [], '', false);
        $mamqpchan->expects($this->once())
                  ->method('close');

        $mamqpchan->callbacks = [];

        $mamqpconn = $this->getMock('\PhpAmqpLib\Connection\AMQPConnection', ['channel', 'close'], [], '', false);
        $mamqpconn->expects($this->once())
        	      ->method('channel')
                  ->willReturn($mamqpchan);

        $app = new \Slim\App(['settings' => ['displayErrorDetails' => true]]);
        $app->get('/users/{id}', function ($request, $response, $args) {
		    $body = json_encode(['id' => $args['id']]);
	        $response->getBody()->write($body);
	        $response = $response->withHeader('Content-Type', 'application/json');

	        return $response;
		});

		$app->post('/users', function ($request, $response, $args) {
		    $body = $request->getParsedBody();
		    $body = json_encode($body);

	        $response->getBody()->write($body);
	        $response = $response->withHeader('Content-Type', 'application/json');

	        return $response;
		});

		$app->put('/users/{id}', function ($request, $response, $args) {
		    $body = $request->getParsedBody();
		    $body = json_encode(array_merge($args, $body));

	        $response->getBody()->write($body);
	        $response = $response->withHeader('Content-Type', 'application/json');

	        return $response;
		});

		$logger = $this->getMock('\Monolog\Logger', [], [], '', false);

        $rpcRequest = $this->getMock('DVO\SlimRabbitRest\RpcRequest', ['sendResponse'], [$logger, $mamqpconn]);
        $rpcRequest->expects($this->once())
                   ->method('sendResponse')
                   ->with()
                   ->willReturn(true);

        $rpcRequest->setApp($app);

        /** @var  ResponseInterface $res */
        $res = $rpcRequest($req, $res, $next);

        // now test Callback, but don't want to copy paste the above to a new test.
        $message = $this->getMock('stdClass', ['body', 'get'], [], '', false);
       
       	$data['content'] = json_encode($data['parameters']);
        $message->body = json_encode($data);

        
        $rpcRequest->callback($message);

        $this->assertEquals($expected, (string) $rpcRequest->getResponse()->getBody());
    }

    public function providerMessages()
    {
    	return [
    		[['method' => 'GET', 'path' => '/users/1', 'parameters' => []], '{"id":"1"}'],
    		[['method' => 'GET', 'path' => '/users/2', 'parameters' => []], '{"id":"2"}'],
    		[['method' => 'GET', 'path' => '/users/3', 'parameters' => []], '{"id":"3"}'],
    		[['method' => 'POST', 'path' => '/users', 'parameters' => ['name' => 'bobby']], '{"name":"bobby"}'],
    		[['method' => 'PUT', 'path' => '/users/1', 'parameters' => ['name' => 'bobby']], '{"id":"1","name":"bobby"}'],
    	];
    }
}