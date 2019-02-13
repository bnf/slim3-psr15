<?php
declare(strict_types=1);

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
     * Resolve toResolve into a callable that the router can dispatch.
     *
     * If toResolve is of the format 'class:method', then try to extract 'class'
     * from the container otherwise instantiate it and then dispatch 'method'.
     *
     * @param mixed $toResolve
     *
     * @return callable
     *
     * @throws RuntimeException if the callable does not exist
     * @throws RuntimeException if the callable is not resolvable
     * @throws RuntimeException if the argument does not reference a callable
     */
    public function resolve($toResolve)
    {
        if (is_object($toResolve)) {
            return $this->resolveFromObject($toResolve);
        }

        if (is_callable($toResolve)) {
            return $toResolve;
        }

        if (is_string($toResolve)) {
            // check for slim callable as "class:method"
            $callablePattern = '!^([^\:]+)\:([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)$!';
            if (preg_match($callablePattern, $toResolve, $matches) === 1) {
                $class = $matches[1];
                $method = $matches[2];
                return $this->resolve([$this->get($class), $method]);
            }
            return $this->resolve($this->get($toResolve));
        }

        throw new RuntimeException(sprintf('%s is not resolvable', json_encode($toResolve)));
    }

    /**
     * @param mixed $object
     * @return callable
     */
    private function resolveFromObject(object $object): callable
    {
        if ($object instanceof MiddlewareInterface) {
            return new PsrMiddleware($object);
        }

        if ($object instanceof RequestHandlerInterface) {
            return [$object, 'handle'];
        }

        if ($object instanceof \Closure) {
            // @todo shouldn't we explicitly bind `null` in case the container is `null`?
            // (blocker: currently some tests rely on non-overwritten binding when container is null)
            return $this->container === null ? $object : $object->bindTo($this->container);
        }

        if (is_callable($object)) {
            return $object;
        }

        throw new RuntimeException(sprintf('%s is not callable', json_encode($object)));
    }

    /**
     * @param string $class
     * @return mixed
     */
    private function get(string $class)
    {
        if ($this->container !== null && $this->container->has($class)) {
            return $this->container->get($class);
        }

        if (class_exists($class)) {
            return new $class($this->container);
        }

        throw new RuntimeException(sprintf('Callable %s does not exist', $class));
    }
}
