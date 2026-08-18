<?php

declare(strict_types=1);

namespace App\Core;

use App\Middleware\AdminMiddleware;
use App\Middleware\AuthMiddleware;
use App\Middleware\GuestMiddleware;
use App\Middleware\MaintenanceMiddleware;
use App\Middleware\VerifiedMiddleware;

final class Router
{
    /** @var array<string, array<int, array{regex:string, params:array<int,string>, action:mixed, middleware:array<int,string>, uri:string}>> */
    private array $routes = [];

    private array $groupStack = [];

    private const MIDDLEWARE = [
        'auth' => AuthMiddleware::class,
        'guest' => GuestMiddleware::class,
        'admin' => AdminMiddleware::class,
        'verified' => VerifiedMiddleware::class,
        'maintenance' => MaintenanceMiddleware::class,
    ];

    public function get(string $uri, mixed $action, array $middleware = []): void
    {
        $this->add('GET', $uri, $action, $middleware);
    }

    public function post(string $uri, mixed $action, array $middleware = []): void
    {
        $this->add('POST', $uri, $action, $middleware);
    }

    public function any(string $uri, mixed $action, array $middleware = []): void
    {
        $this->add('GET', $uri, $action, $middleware);
        $this->add('POST', $uri, $action, $middleware);
    }

    public function group(array $attributes, callable $callback): void
    {
        $this->groupStack[] = $attributes;
        $callback($this);
        array_pop($this->groupStack);
    }

    private function add(string $method, string $uri, mixed $action, array $middleware): void
    {
        $prefix = '';
        $groupMiddleware = [];

        foreach ($this->groupStack as $group) {
            $prefix .= isset($group['prefix']) ? '/' . trim((string) $group['prefix'], '/') : '';
            $groupMiddleware = array_merge($groupMiddleware, $group['middleware'] ?? []);
        }

        $uri = $prefix . '/' . trim($uri, '/');
        $uri = $uri === '/' ? '/' : rtrim($uri, '/');
        if ($uri === '') {
            $uri = '/';
        }

        $params = [];
        $regex = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)(:([^}]+))?\}#',
            static function (array $matches) use (&$params): string {
                $params[] = $matches[1];
                $pattern = $matches[3] ?? '[^/]+';

                return '(' . $pattern . ')';
            },
            $uri
        );

        $this->routes[$method][] = [
            'regex' => '#^' . $regex . '$#',
            'params' => $params,
            'action' => $action,
            'middleware' => array_values(array_unique(array_merge($groupMiddleware, $middleware))),
            'uri' => $uri,
        ];
    }

    public function dispatch(Request $request): void
    {
        $method = $request->method();
        $path = $request->path();

        foreach ($this->routes[$method] ?? [] as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }

            array_shift($matches);
            $params = [];
            foreach ($route['params'] as $index => $name) {
                $params[$name] = $matches[$index] ?? null;
            }
            $request->setRouteParams($params);

            $middleware = $route['middleware'];

            // CSRF protection for every state-changing request unless explicitly excluded.
            if ($request->isPost() && !in_array('nocsrf', $middleware, true)) {
                Csrf::verifyRequest($request);
            }

            foreach ($middleware as $name) {
                if ($name === 'nocsrf') {
                    continue;
                }
                $class = self::MIDDLEWARE[$name] ?? null;
                if ($class === null) {
                    continue;
                }
                (new $class())->handle($request);
            }

            $this->runAction($route['action'], $request);

            return;
        }

        // Path exists under a different verb? Then it is a 405-ish situation; keep it simple with 404.
        throw new HttpException(404, 'We could not find the page you were looking for.');
    }

    private function runAction(mixed $action, Request $request): void
    {
        if (is_callable($action)) {
            $action($request);

            return;
        }

        if (is_array($action) && count($action) === 2) {
            [$class, $method] = $action;
            $controller = new $class();

            if (!method_exists($controller, $method)) {
                throw new HttpException(500, sprintf('Controller method %s::%s() does not exist.', $class, $method));
            }

            $controller->{$method}($request);

            return;
        }

        throw new HttpException(500, 'Invalid route action.');
    }

    /**
     * @return array<int, array{method:string, uri:string}>
     */
    public function routeList(): array
    {
        $list = [];
        foreach ($this->routes as $method => $routes) {
            foreach ($routes as $route) {
                $list[] = ['method' => $method, 'uri' => $route['uri']];
            }
        }

        return $list;
    }
}
