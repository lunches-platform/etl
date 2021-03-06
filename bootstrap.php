<?php


use GuzzleHttp\Client;
use Monolog\Logger;
use Silex\Application;

require_once __DIR__.'/vendor/autoload.php';

$app = new \Silex\Application();

$app['debug'] = true;
$app['root_dir'] = __DIR__ . '/';
/** @var array $parameters */
$parameters = require __DIR__.'/parameters.php';
foreach ($parameters as $configKey => $configValue) {
    /** @var $configKey string */
    $app[$configKey] = $configValue;
}

$app->register(new \Silex\Provider\MonologServiceProvider());
$app->extend('monolog', function(Logger $monolog) {
    $monolog->pushHandler(new \Monolog\Handler\StreamHandler(__DIR__.'/var/logs/synchronizers.log'));

    return $monolog;
});
$app->register(new Knp\Provider\ConsoleServiceProvider(), [
    'console.name'              => 'Lunches ETL',
    'console.version'           => '0.1.0',
    'console.project_directory' => __DIR__.'/..'
]);
$app['google:client'] = function (Application $app) {
    $client = new Google_Client();
    $client->setAuthConfig($app['root_dir'].$app['google:auth:service-account-json']);
    $client->setScopes([Google_Service_Sheets::SPREADSHEETS_READONLY]);
    return $client;
};
$app['google:sheets-service'] = function (Application $app) {
    return new Google_Service_Sheets($app['google:client']);
};
// register services for each instance
/** @var array $instances */
$instances = $app['instances'];
foreach ($instances as $instance) {
    $key = $instance['key'];

    $app["guzzle:{$key}-api"] = function() use ($instance) {
        return new Client([
            'base_uri' => $instance['api-base-uri'],
        ]);
    };
    $apiClient = $app["guzzle:{$key}-api"];

    $app["service:orders:{$key}"] = function (Application $app) use ($apiClient, $instance) {
        return new \Lunches\ETL\Service\OrdersService($apiClient, $app['api:access-token'], $instance['company']);
    };
    $app["service:menus:{$key}"] = function (Application $app) use ($apiClient, $instance) {
        return new \Lunches\ETL\Service\MenusService($apiClient, $app['api:access-token'], $instance['company']);
    };
    $app["service:prices:{$key}"] = function (Application $app) use ($apiClient, $instance) {
        return new \Lunches\ETL\Service\PricesService($apiClient, $app['api:access-token'], $instance['company']);
    };
    $app["service:dishes:{$key}"] = function (Application $app) use ($apiClient, $instance) {
        return new \Lunches\ETL\Service\DishesService($apiClient, $app['api:access-token'], $instance['company']);
    };
    $app["service:users:{$key}"] = function (Application $app) use ($apiClient, $instance) {
        return new \Lunches\ETL\Service\UsersService($apiClient, $app['api:access-token'], $instance['company']);
    };

    $app["synchronizer:menus:{$key}"] = function(Application $app) use ($key) {
        return new \Lunches\ETL\Synchronizer\MenusSynchronizer(
            $app['google:sheets-service'],
            $app["service:menus:{$key}"],
            $app["synchronizer:dishes:{$key}"],
            $app["prices-generator:{$key}"],
            $app['logger']
        );
    };
    $app["synchronizer:dishes:{$key}"] = function(Application $app) use ($key) {
        return new \Lunches\ETL\Synchronizer\DishesSynchronizer(
            $app["service:dishes:{$key}"],
            $app['logger']
        );
    };
    $app["synchronizer:orders:{$key}"] = function (Application $app) use ($key) {
        return new \Lunches\ETL\Synchronizer\OrdersSynchronizer(
            $app['google:sheets-service'],
            $app["service:menus:{$key}"],
            $app["service:users:{$key}"],
            $app["service:orders:{$key}"],
            $app['logger']
        );
    };
    $app["prices-generator:{$key}"] = function (Application $app) use ($key) {
        return new \Lunches\ETL\PricesGenerator(
            $app["service:prices:{$key}"]
        );
    };
}
$app['get-services'] = $app->protect(function ($name) use ($app, $instances) {
    return array_map(function ($instance) use ($app, $name) {
        return $app["service:{$name}:{$instance['key']}"];
    }, $instances);
});

$app['cooking-packing-report'] = function (Application $app) {
    return new \Lunches\ETL\CookingPackingReport(
        $app['get-services']('orders'),
        $app['get-services']('menus'),
        $app['plates'],
        $app['logger']
    );
};
$app['plates'] = function () {
    return new League\Plates\Engine(__DIR__.'/templates');
};

return $app;
