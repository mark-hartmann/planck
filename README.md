# Plack

Plack is a minimalistic dependency injection container with [PSR-11](https://www.php-fig.org/psr/psr-11/)[+](https://github.com/container-interop/service-provider) support, (heavily) inspired by Pimple/Simplex. For now, i even use most of their documentation, but i'll change that later.


- `Hartmann\Plack\Container` implements [`ContainerInterface`](https://github.com/container-interop/container-interop/blob/master/src/Interop/Container/ContainerInterface.php) and fully supports container-interop's [`ServiceProviderInterface`](https://github.com/container-interop/service-provider/blob/master/src/ServiceProviderInterface.php)

    - `$container->extend()` 
        - Can be used to extend scalar values, factories and services
    - `$container->factory()` 
        - Can be used to mark a callable as being a factory service. If so, each time the entry gets requested, a new instance is returned
    - `$container->preserve()` 
        - Can be used to protect a function from being used by the container as a service factory.
    - `$container->autowire()` 
        - Can be used to autowire functions and classes.


# Installation

```
composer require hartmann/planck
```

# Usage

Creating a container is a matter of creating a `Container` instance:

```php
$container = new \Hartmann\Planck\Container();
```

## Defining Service Providers

A service provider is an object that does something as part of a larger system. Examples
of services: a database connection, a templating engine, or a mailer. Almost
any **global** object can be a service.

Services are defined by **anonymous functions** that return an instance of an
object:

```php
use Interop\Container\ServiceProviderInterface

class Provider implements ServiceProviderInterface
{
    public function getFactories()
    {
        return [
            stdClass::class => function(ContainerInterface $container) {
                return new stdClass;
            },
            ...
        ];
    }
    
    public function getExtensions()
    {
        return [
            stdClass::class => function(ContainerInterface $container, ?stdClass $class) {
                $class->foo = 'bar';
                
                return $class;
            },
            ...
        ];
    }
}
```

Notice that the anonymous function has access to the current container
instance, allowing references to other services or parameters.

As objects are only created when you get them, the order of the definitions
does not matter.

Using the defined services is also very easy:

```php
$class = $container->get(stdClass::class);
```

## Defining Factory Services

By default, each time you get a service, Planck returns the **same instance**
of it. If you want a different instance to be returned for all calls, wrap your
anonymous function with the `factory()` method

```php
$container->set('factory', $container->factory(function (ContainerInterface $container) {
    return new stdClass;
}));
```

Each call to `$container->get(stdClass::class)` now returns a new instance of stdClass.

## Defining Parameters

Defining a parameter allows to ease the configuration of your container from
the outside and to store global values:

```php
$container->set('cookie_name', 'SESSION_ID');
$container->set('session_storage_class', 'SessionStorage');
```

You can now easily change the cookie name by overriding the
`session_storage_class` parameter instead of redefining the service
definition.

## Preserving / Protecting Parameters

Because Planck sees anonymous functions as service definitions, you need to
wrap anonymous functions with the `preserve()` method to store them as
parameters:

```php
$container['random_bytes'] = $container->protect(function () {
    return random_bytes(4);
});
```

## Modifying Services after Definition

In some cases you may want to modify a service definition after it has been
defined. You can use the `extend()` method to define additional code to be
run on your service just after it is created:

```php
$container->set('session_storage', function (ContainerInterface $container) {
    return new $container->get('session_storage_class')($container->get('cookie_name'));
});

$container->extend('session_storage', function (ContainerInterface $container, ?SessionStorage $storage) {
    $storage->...();

    return $storage;
});
```

## Autowiring

Sometimes it is practical to resolve dependencies on the container itself. To make this possible, the `autowire()` method is used.  
Both classes and anonymous functions can be wired. 

```php
$container->set(Foo::class, new Foo());
$container->set(Bar::class, new Bar());

$container->set('autowired', $container->autowire(function (Foo $foo, Bar $bar) {
    return ...
}));
```

The `autowire()` method has as second parameter
`bool $resolveByName`. If `true` is passed to this parameter, parameters without type hinting or scalar type hints are resolved by their name.

```php
$container->set('foo', new Foo());
$container->set('bar', new Bar());

$container->set('autowired', $container->autowire(function ($foo, $bar) {
    return ...
}, true));
```