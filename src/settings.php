<?php
return [
    'settings' => [
        'displayErrorDetails' => true, // set to false in production
        'addContentLengthHeader' => false, // Allow the web server to send the content-length header

        // Renderer settings
        'renderer' => [
            'template_path' => __DIR__ . '/../templates/',
        ],

        // Monolog settings
        'logger' => [
            'name' => 'slim-app',
            'path' => isset($_ENV['docker']) ? 'php://stdout' : __DIR__ . '/../logs/app.log',
            'level' => \Monolog\Logger::DEBUG,
        ],
        'session' => [
            // Session cookie settings
            'name'           => 'slim_session',
            'lifetime'       => 30,
            'path'           => '/',
            'domain'         => 'plundering.rocks',
            'secure'         => true,
            'httponly'       => true,

            // Set session cookie path, domain and secure automatically
            'cookie_autoset' => true,
    
            // Path where session files are stored, PHP's default path will be used if set null
            'save_path'      => null,
    
            // Session cache limiter
            'cache_limiter'  => 'nocache',
    
            // Extend session lifetime after each user activity
            'autorefresh'    => true,
    
            // Encrypt session data if string is set
            'encryption_key' => null,
    
            // Session namespace
            'namespace'      => 'slim_app'
        ],
    ],
];
