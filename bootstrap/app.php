<?php

use App\Models\Order;
use App\Models\Product;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                $previous = $e->getPrevious();

                if ($previous instanceof ModelNotFoundException) {
                    return match ($previous->getModel()) {
                        Product::class => response()->json(['message' => 'Product not found.'], 404),
                        Order::class => response()->json(['message' => 'Order not found.'], 404),
                        default => response()->json(['message' => 'Resource not found.'], 404),
                    };
                }

                return response()->json([
                    'message' => 'Endpoint not found.',
                ], 404);
            }
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if ($request->is('api/*')) {
                return match ($e->getModel()) {
                    Product::class => response()->json(['message' => 'Product not found.'], 404),
                    Order::class => response()->json(['message' => 'Order not found.'], 404),
                    default => response()->json(['message' => 'Resource not found.'], 404),
                };
            }
        });
    })->create();
