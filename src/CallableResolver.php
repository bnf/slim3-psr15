<?php
/**
 * Slim Framework (https://slimframework.com)
 *
 * @link      https://github.com/slimphp/Slim
 * @copyright Copyright (c) 2011-2017 Josh Lockhart
 * @license   https://github.com/slimphp/Slim/blob/4.x/LICENSE.md (MIT License)
 */
namespace Bnf\Slim3Psr15;

use Bnf\Slim3Psr15\Adapter\PsrMiddleware;
use Psr\Container\ContainerInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Slim\Interfaces\CallableResolverInterface;

/**
 * This class resolves a string of the format 'class:method' into a closure
 * that can be dispatched.
 */
final class CallableResolver implements CallableResolverInterface
{
    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * @param ContainerInterface|null $container
     */
    public function __construct(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    /**
     * Resolve toResolve into a callable that that the router can dispatch.
     *
     * If toResolve is of the format 'class:method', then try to extract 'class'
     * from the container otherwise instantiate it and then dispatch 'method'.
     *
     * @param mixed $toResolve
     * @param bool  $resolveMiddleware
     *
     * @return callable
     *
     * @throws RuntimeException if the callable does not exist
     * @throws RuntimeException if the callable is not resolvable
     */
    public function resolve($toResolve, $resolveMiddleware = true)
    {
        if ($resolveMiddleware && $toResolve instanceof MiddlewareInterface) {
            return new PsrMiddleware($toResolve);
        }

        if ($toResolve instanceof RequestHandlerInterface) {
            return [$toResolve, 'handle'];
        }

        if (is_callable($toResolve)) {
            if ($toResolve instanceof \Closure && $this->container instanceof ContainerInterface) {
                return $toResolve->bindTo($this->container);
            }
            return $toResolve;
        }

        $resolved = $toResolve;
        if (is_string($toResolve)) {
            $class = $toResolve;
            $method = null;
            $instance = null;

            // check for slim callable as "class:method"
            $callablePattern = '!^([^\:]+)\:([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)$!';
            if (preg_match($callablePattern, $toResolve, $matches) === 1) {
                $class = $matches[1];
                $method = $matches[2];
            }

            if ($this->container instanceof ContainerInterface && $this->container->has($class)) {
                $instance = $this->container->get($class);
            } else {
                if (!class_exists($class)) {
                    throw new RuntimeException(sprintf('Callable %s does not exist', $class));
                }
                $instance = new $class($this->container);
            }

            if ($resolveMiddleware && $method === null && $instance instanceof MiddlewareInterface) {
                return new PsrMiddleware($instance);
            }

            if ($method === null && $instance instanceof RequestHandlerInterface) {
                return [$instance, 'handle'];
            }

            $resolved = [$instance, $method ?: '__invoke'];
        }

        if (!is_callable($resolved)) {
            throw new RuntimeException(sprintf(
                '%s is not resolvable',
                is_array($toResolve) || is_object($toResolve) ? json_encode($toResolve) : $toResolve
            ));
        }

        return $resolved;
    }
}
