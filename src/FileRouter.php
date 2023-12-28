<?php

declare(strict_types=1);

namespace Yiisoft\FileRouter;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Middleware\Dispatcher\MiddlewareDispatcher;

final class FileRouter implements MiddlewareInterface
{
    public string $baseControllerDirectory = 'Controller';
    public string $classPostfix = 'Controller';
    public string $namespace = 'App';

    public function __construct(
        private readonly MiddlewareDispatcher $middlewareDispatcher,
    ) {
    }

    public function withBaseControllerDirectory(string $directory): self
    {
        $new = clone $this;
        $new->baseControllerDirectory = $directory;

        return $new;
    }

    public function withClassPostfix(string $postfix): self
    {
        $new = clone $this;
        $new->classPostfix = $postfix;

        return $new;
    }

    public function withNamespace(string $namespace): self
    {
        $new = clone $this;
        $new->namespace = $namespace;

        return $new;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $controllerClass = $this->parseController($request);
        if ($controllerClass === null) {
            return $handler->handle($request);
        }
        $actions = $controllerClass::$actions ?? [
            'HEAD' => 'head',
            'OPTIONS' => 'options',
            'GET' => 'index',
            'POST' => 'create',
            'PUT' => 'update',
            'DELETE' => 'delete',
        ];
        $action = $actions[$request->getMethod()] ?? null;

        if ($action === null) {
            return $handler->handle($request);
        }

        if (!method_exists($controllerClass, $action)) {
            return $handler->handle($request);
        }

        $middlewares = $controllerClass::$middlewares[$action] ?? [];
        $middlewares[] = [$controllerClass, $action];

        $middlewareDispatcher = $this->middlewareDispatcher->withMiddlewares($middlewares);

        return $middlewareDispatcher->dispatch($request, $handler);
    }

    private function parseController(ServerRequestInterface $request): ?string
    {
        $path = $request->getUri()->getPath();
        if ($path === '/') {
            $controllerName = 'Index';
            $directoryPath = '';
        } else {
            $controllerName = preg_replace_callback(
                '#(/.)#',
                fn(array $matches) => strtoupper($matches[1]),
                $path,
            );

            if (!preg_match('#^(.*?)/([^/]+)/?$#', $controllerName, $matches)) {
                return null;
            }
            $directoryPath = $matches[1];
            $controllerName = $matches[2];
        }

        $controller = $controllerName . $this->classPostfix;

        $className = str_replace(
            ['\\/\\', '\\/', '\\\\'],
            '\\',
            $this->namespace . '\\' . $this->baseControllerDirectory . '\\' . $directoryPath . '\\' . $controller
        );

        if (class_exists($className)) {
            return $className;
        }

        return null;
    }
}
