<?php

// The container's real env (DB_HOST, DB_PASSWORD, ...) comes from compose's env_file and wins
// over phpunit.xml: <env> only reaches $_ENV/putenv, but Laravel's Env::get() reads $_SERVER
// first. Without this, tests would run against the live MariaDB database. Set the test values
// in every place Laravel looks, before it boots.
$testEnv = [
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DB_HOST' => '',
    'DB_PORT' => '',
    'DB_USERNAME' => '',
    'DB_PASSWORD' => '',
    'DB_URL' => '',
    // The container runs database-backed session/cache; the sqlite :memory: tests have no
    // such tables unless a test migrates, so force the in-process drivers here too.
    'SESSION_DRIVER' => 'array',
    'CACHE_STORE' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'array',
    'BROADCAST_CONNECTION' => 'null',
    'BCRYPT_ROUNDS' => '4',
    'APP_DEBUG' => 'false',
    // The entrypoint runs `optimize` in production only, but a cached route file would hide
    // routes added since. Point the route cache at a path that never exists.
    'APP_ROUTES_CACHE' => sys_get_temp_dir().'/phpunit-no-route-cache.php',
];

foreach ($testEnv as $key => $value) {
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv("$key=$value");
}

// A cached config bakes in the last APP_ENV; delete it so the values above take effect.
$configCache = __DIR__.'/../bootstrap/cache/config.php';
if (file_exists($configCache)) {
    unlink($configCache);
}

require __DIR__.'/../vendor/autoload.php';
