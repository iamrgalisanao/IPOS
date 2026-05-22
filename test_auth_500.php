<?php

use Illuminate\Support\Facades\Http;
use App\Models\User;
use Illuminate\Support\Facades\Route;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

$request = Illuminate\Http\Request::create('/login', 'GET');
$app->instance('request', $request);

$user = User::first();
echo "Logging in as: " . $user->email . "\n";

// Force authentication for the CLI environment
auth()->login($user);

$routes = Route::getRoutes();

foreach ($routes as $route) {
    if (!in_array('GET', $route->methods()) || str_starts_with($route->uri(), '_') || str_starts_with($route->uri(), 'api/') || str_starts_with($route->uri(), 'up')) {
        continue;
    }
    
    $uri = '/' . ltrim($route->uri(), '/');
    
    // Replace parameters with dummy values for testing
    $uri = preg_replace('/\{[a-zA-Z0-9_]+\??\}/', '1', $uri);

    $req = Illuminate\Http\Request::create($uri, 'GET');
    $app->instance('request', $req);
    $req->setUserResolver(function () use ($user) {
        return $user;
    });

    try {
        $response = $kernel->handle($req);
        if ($response->getStatusCode() == 500) {
            echo "ERROR 500 ON ROUTE: $uri\n";
            echo substr(strip_tags($response->getContent()), 0, 500) . "\n\n";
        } elseif ($response->getStatusCode() == 404) {
            // ignore 404s since we mock params
        } else {
            echo "Success on $uri (" . $response->getStatusCode() . ")\n";
        }
    } catch (\Exception $e) {
        echo "Exception on $uri: " . $e->getMessage() . "\n";
    }
}
