<?php
// Application middleware

// e.g: $app->add(new \Slim\Csrf\Guard);
//
$app->add(new \Slim\HttpCache\Cache('public', 300));

$app->add(new \Adbar\SessionMiddleware($settings['settings']['session']));
