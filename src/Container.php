<?php

namespace Planck;


use InvalidArgumentException;
use Planck\Exception\NotFoundException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SplObjectStorage;

class Container implements ContainerInterface
{
    protected $values = [];

    protected $preserved;
    protected $factories;
    //    protected $resolvedEntries = [];

    /**
     * Container constructor.
     *
     * @param \Interop\Container\ServiceProviderInterface[] $providers
     * @param mixed[]                                       $values
     */
    public function __construct(array $providers = [], array $values = [])
    {
        $this->preserved = new SplObjectStorage();
        $this->factories = new SplObjectStorage();

        $this->register($providers, $values);
    }

    /**
     * Finds an entry of the container by its identifier and returns it.
     *
     * @param string $id Identifier of the entry to look for.
     *
     * @return mixed Entry.
     * @throws ContainerExceptionInterface Error while retrieving the entry.
     *
     * @throws NotFoundExceptionInterface  No entry was found for **this** identifier.
     */
    public function get($id)
    {
        if (!$this->has($id)) {
            throw new NotFoundException(sprintf('No entry was found for "%s" identifier', $id));
        }

        /**
         * A normal entry (no object, preserved callable or object without __invoke method) gets returned immediately
         * @todo Check if !method_exists could be replaced by a simple !is_callable
         */
        if (!is_object($this->values[$id]) || $this->preserved->contains($this->values[$id]) || !method_exists($this->values[$id], '__invoke')) {
            return $this->values[$id];
        }

        /** A factory returns a new instance each time! */
        if ($this->factories->contains($this->values[$id])) {
            return $this->values[$id]($this);
        }

        $this->values[$id] = $this->values[$id]($this);

        return $this->values[$id];
    }

    /**
     * @param $id
     * @param $entry
     */
    public function set(string $id, $entry): void
    {
        $this->values[$id] = $entry;
    }

    /**
     * @param $id
     */
    public function unset(string $id): void
    {
        if ($this->has($id)) {
            if (is_object($this->values[$id])) {
                $this->preserved->detach($this->values[$id]);
                $this->factories->detach($this->values[$id]);
            }

            unset($this->values[$id]);
        }
    }

    /**
     * Returns true if the container can return an entry for the given identifier.
     * Returns false otherwise.
     *
     * `has($id)` returning true does not mean that `get($id)` will not throw an exception.
     * It does however mean that `get($id)` will not throw a `NotFoundExceptionInterface`.
     *
     * @param string $id Identifier of the entry to look for.
     *
     * @return bool
     */
    public function has($id): bool
    {
        return isset($this->values[$id]);
    }

    /**
     * Protects a callable object from being used by the container as a factory.
     *
     * @param callable|object $callable
     *
     * @return mixed
     */
    public function preserve(callable $callable): callable
    {
        if (!method_exists($callable, '__invoke')) {
            throw new InvalidArgumentException('Callable is not a Closure or invokable object');
        }

        $this->preserved->attach($callable);

        return $callable;
    }

    /**
     * Marks a callable as being a factory service.
     *
     * @param callable|object $callable
     *
     * @return callable
     */
    public function factory(callable $callable): callable
    {
        if (!method_exists($callable, '__invoke')) {
            throw new InvalidArgumentException('Callable is not a Closure or invokable object');
        }

        $this->factories->attach($callable);

        return $callable;
    }

    /**
     * @param \Interop\Container\ServiceProviderInterface[] $providers
     * @param mixed[]                                       $values
     */
    protected function register(array $providers, array $values): void
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }

        /** Sets the factories from the given set of providers */
        foreach ($providers as $provider) {

            $factories = $provider->getFactories();

            foreach ($factories as $key => $factory) {
                if (is_callable($factory)) {
                    /**
                     * Because $callable can also be given in the [object, 'method'] syntax, the callable execution
                     * gets wrapped in another anonymous function.
                     *
                     * @param \Psr\Container\ContainerInterface $container
                     *
                     * @return mixed
                     */
                    $this->values[$key] = function (\Psr\Container\ContainerInterface $container) use ($factory) {
                        return call_user_func($factory, $container);
                    };

                } else {
                    /** A factory MAY return a non-callable, in this case we act like it is a normal entry. */
                    $this->set($key, $factory);
                }
            }
        }

        /**
         * Iterate one more time to handle the extensions. This is required to ensure Providers can extend
         * entires that may have been declared in a later Provider.
         */
        foreach ($providers as $provider) {

            $extensions = $provider->getExtensions();

            foreach ($extensions as $key => $extensionFactory) {
                if ($this->has($key)) {
                    /**
                     * To extend an entry, the extension factory must own two parameters, the first one is the container,
                     * the second the entry to extend (nullable).
                     * @see https://github.com/container-interop/service-provider#extensions
                     **
                     * The extendsion factory is wrapped by an anonymous function to ensure that the factory can be executed properly
                     * even if it comes with the array-callable syntax.
                     */
                    $this->values[$key] = $this->extend($key, function (\Psr\Container\ContainerInterface $container, $previous) use ($extensionFactory) {
                        return call_user_func($extensionFactory, $container, $previous);
                    });

                } else {
                    $this->values[$key] = $extensionFactory;
                }
            }
        }
    }

    public function extend($id, $callable)
    {
        if (!$this->has($id)) {
            throw new NotFoundException(sprintf('Identifier "%s" is not defined.', $id));
        }

        /** We cant extend an array-like callable, so we need an actual closure or invokable object */
        if (!is_object($callable) || !method_exists($callable, '__invoke')) {
            throw new InvalidArgumentException('Extension service definition is not a Closure or invokable object.');
        }

        $factory = $this->values[$id];

        /**
         * Wraps (again) the already wrapped factory to "hide" the previous requirement, this gets "lexical scoped" (nice)
         * to the actual factory so there must only be passed a fitting implementation of the PSR-11-Container Interface
         *
         * @param \Psr\Container\ContainerInterface $container
         *
         * @return mixed
         */
        $extended = function (\Psr\Container\ContainerInterface $container) use ($callable, $factory) {
            # if the entry to extend is not a callable, we pass it as is
            $previous = is_callable($factory) ? $factory($container) : $factory;

            return $callable($container, $previous);
        };

        if (is_object($factory) && $this->factories->contains($factory)) {
            $this->factories->detach($factory);
            $this->factories->attach($extended);
        }

        $this->values[$id] = $extended;

        return $this->values[$id];
    }
}