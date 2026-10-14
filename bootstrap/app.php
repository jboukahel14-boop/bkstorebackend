<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            HandleCors::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // This is a JSON-only API. Unauthenticated requests to api/* must get
        // a proper 401 JSON response — never a redirect-to-login or a 500.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });

        // Non-JSON clients hit the Authenticate middleware's redirect-to-login
        // flow, which throws RouteNotFoundException because no 'login' route
        // exists. Return the same clean 401 for those too.
        $exceptions->render(function (RouteNotFoundException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });

        // api/* is JSON-only: model lookups (firstOrFail) and unmatched routes
        // must return JSON 404 regardless of the request's Accept header.
        foreach ([ModelNotFoundException::class, NotFoundHttpException::class] as $notFound) {
            $exceptions->render(function (Throwable $e, Request $request) use ($notFound) {
                if ($e instanceof $notFound && $request->is('api/*')) {
                    return response()->json(['message' => 'Not found.'], 404);
                }
            });
        }
    })->create();