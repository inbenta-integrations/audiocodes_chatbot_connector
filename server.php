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
$router->respond(array('GET', 'POST'), '/CreateConversation', function () use ($app) {
    $response = $app->createConversation();
    return json_encode($response, JSON_UNESCAPED_SLASHES);
});

$router->with('/conversation', function () use ($router, $app) {
    // Receive messages
    $router->respond('POST', '/[:id]/activities', function () use ($app) {
        $response = $app->handleRequest();
        return json_encode($response, JSON_UNESCAPED_SLASHES);
    });

    // Keep alive
    $router->respond('POST', '/[:id]/refresh', function () use ($app) {
        $response = $app->refresh();
        return json_encode($response, JSON_UNESCAPED_SLASHES);
    });

    // Disconnect
    $router->respond('POST', '/[:id]/disconnect', function () use ($app) {
        $response = $app->disconnect();
        return json_encode($response, JSON_UNESCAPED_SLASHES);
    });
});

$router->dispatch();
