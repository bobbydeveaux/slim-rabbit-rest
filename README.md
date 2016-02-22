![Build Status](https://travis-ci.org/dvomedia/slim-rest-skeleton.svg)

# Slim 3 Framework AMQP/REST Request Middleware

This middleware will take an AMQP message and run the REST request, by-passing HTTP, saving lots of time!

### Add it with composer
```
composer require dvomedia/slim-rabbit-rest
```

Also requires slim-cli, and phpamqp-lib

```
composer require videlalvaro/php-amqplib
composer require pavlakis/slim-cli
```

### Call the RPC Server like so:

```php
php public/index.php /rpcserver GET
```

### Add it in as a route-middleware section of your application, like so:
```
$app->get('/rpcserver', function($req, $res, $args){
	print 'Starting RPC Server';
})->add(function($request, $response, $next) use ($container) {
	return new \DVO\SlimRabbitRest\RpcRequest(
		$container['logger'],
		$container['amqp']
	);
});
```

Simples :)