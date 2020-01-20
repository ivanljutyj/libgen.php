<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/functions.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;

$rabbitmq = parse_url(getenv('CLOUDAMQP_URL'));

$connection = new AMQPStreamConnection(
    $rabbitmq['host'],
    5672,
    $rabbitmq['user'],
    $rabbitmq['pass'],
    substr($rabbitmq['path'], 1) ?: '/'
);
$channel = $connection->channel();

$channel->queue_declare('books', false, false, false, false);

echo " [*] Waiting for books. To exit press CTRL+C" . PHP_EOL;

$callback = function ($url) {
    echo ' [x] Received ', $url->body . PHP_EOL;
    downloadBook($url->body);
};

$channel->basic_consume('books', '', false, true, false, false, $callback);

while ($channel->is_consuming()) {
    $channel->wait();
}

$channel->close();
$connection->close();