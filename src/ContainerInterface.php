<?php

namespace Hartmann\Planck;

interface ContainerInterface extends \Psr\Container\ContainerInterface
{
    /**
     * Adds an entry to the container.
     *
     * @param string $id
     * @param mixed  $enty
     */
    public function set(string $id, $enty): void;

    /**
     * Removes an entry by it's identifier
     *
     * @param string $id
     */
    public function unset(string $id): void;

    /**
     * Preserves a callable from being interpreted as a service.
     * This is useful when you want to store a callable as a parameter.
     *
     * @param callable $callable
     *
     * @return callable
     */
    public function preserve(callable $callable): callable;

    /**
     * Marks a callable as being a factory service.
     *
     * @param callable $callable
     *
     * @return callable
     */
    public function factory(callable $callable): callable;

    /**
     * @param string|callable $wireable   Must be a fully-qualified-name for a class or a callable
     * @param array           $parameters Key-value paired array, key = parameter, value = value
     *
     * @return callable
     */
    public function autowire($wireable, array $parameters = []): callable;
}