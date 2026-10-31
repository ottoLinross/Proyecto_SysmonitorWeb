<?php

use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Filesystem routes are registered by the framework after provider boot.
            app()->booted(function (): void {
                foreach (Route::getRoutes() as $route) {
                    if (in_array($route->getName(), ['storage.local', 'storage.local.upload'], true)) {
                        $route->middleware(['web', 'auth:web']);

                        if ($route->getName() === 'storage.local.upload') {
                            $route->middleware(EnsureUserIsAdmin::class);
                        }
                    }
                }
            });
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureUserIsAdmin::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
