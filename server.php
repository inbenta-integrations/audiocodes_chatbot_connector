<?php

require "vendor/autoload.php";

use Inbenta\AudiocodesConnector\AudiocodesConnector;
use Klein\Klein as Router;

header('Content-Type: application/json');

// Instance new Connector
$appPath = __DIR__ . '/';
$app = new AudiocodesConnector($appPath);

// Instance the Router
$router = new Router();
$router->respond(array('GET', 'POST'), '/CreateConversation', function ($request, $response) use ($app) {
    $app->createConversation();
});

$router->with('/conversation', function () use ($router, $app) {
    // Receive messages
    $router->respond('POST', '/[:id]/activities', function ($request, $response) use ($app) {

        $app->handleRequest();
    });

    // Keep alive
    $router->respond('POST', '/[:id]/refresh', function ($request, $response) use ($app) {

        $app->refresh();
    });

    // Disconnect
    $router->respond('POST', '/[:id]/disconnect', function ($request, $response) use ($app) {

        $app->disconnect();
    });
});

$router->dispatch();
