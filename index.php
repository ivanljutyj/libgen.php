<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/functions.php';

use Klein\Klein;

const BASE_URL = 'http://gen.lib.rus.ec';

$klein = new Klein();

$klein->respond('GET', '/', function($request, $response) {
  return $response->json([
    'name' => 'Genesis',
    'version' => '1.0',
    'author' => 'Ivan Ivanoff <contact@ivanon.io>'
  ]);
});

$klein->respond('GET', '/books/[:name]', function($request, $response) {
  return $response->json(parseBooks($request->name));
});

$klein->respond('GET', '/books/download/[:md5]', function($request, $response) {
    addToQueue(getDownloadUrl(BASE_URL.'/book/index.php?md5='.$request->md5));
});

$klein->dispatch();
