# Planck

Planck is a minimalistic dependency injection container with [PSR-11](https://www.php-fig.org/psr/psr-11/)[+](https://github.com/container-interop/service-provider) support, (heavily) inspired by Pimple/Simplex. For now, i even use most of their documentation, but i'll change that later.


- `Hartmann\Planck\Container` implements [`ContainerInterface`](https://github.com/container-interop/container-interop/blob/master/src/Interop/Container/ContainerInterface.php) and fully supports container-interop's [`ServiceProviderInterface`](https://github.com/container-interop/service-provider/blob/master/src/ServiceProviderInterface.php)

    - `$container->extend()` 
        - Can be used to extend scalar values, factories and services
    - `$container->factory()` 
        - Can be used to mark a callable as being a factory service. If so, each time the entry gets requested, a new instance is returned
    - `$container->preserve()` 
        - Can be used to protect/preserve a function from being used by the container as a service factory.
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
$container->set('random_bytes', $container->preserve(function () {
    return random_bytes(4);
}));
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
`array $parameters = []`.   
If you know the `Container` is not able to resolve a parameter or you wish to pass your own value, you can easily do so:

```php
class Foo {
    ...
}

$container->set(Foo::class, new Foo());
$container->set('autowired', $container->autowire(function (Foo $foo, $bar) {
    var_dump($foo) // object Foo
    var_dump($bar) // string 'foo'
}, ['bar' => 'foo']]));
```

Since version `1.0.3` it is possible to pass callables in form of arrays.  
This allows to autowire static and non-static object methods, which can be useful for an incredible number of things, such as controllers:

```php
class HomeController {
    
    protected $logger
    
    public function __contruct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }
    
    public function index(Request $request, Response $response): Response 
    {
        $this->logger->info('someone visited my site!');
        
        return $response->write('Hello');
    }    
}

// adding the required classes to the container ...
$container->autowire([HomeController::class, 'index']);
```

This also works with already instanciated objects:
```php
$container->autowire([$homeControllerInstance, 'index']);
```

Extended classes behave normally as long as the dependencies are registered in the container.

```php
class Request
{
    public function __construct(string $method, UriInterface $uri, HeadersInterface $headers, ...);
}

class CreateUserRequest extends Request {
    ... 
}

// adding the required classes to the container ...
$container->autowire(CreateUserRequest::class, ['method' => $requestMethod]);
```

## Hinted parameters & autowiring:

With php 5 and 7 named parameters were added. Planck can handle builtin and normal hints.  
The following constellations are possible. 

```php
// unresolvable, must be passed directly to the parameters
function ($foo) {  
}                             

// unresolvable, must be passed directly to the parameters
function (string|int|float|array|bool $foo) {  
}

// hinted, required
function (Foo $foo) { 
}                         

// hinted, optional
function (Foo $foo = null) {  
}                  

// hinted, nullable
function (?Foo $foo) {  
}                        
```

If no value could be found for nullable parameters, null is passed.  
If no value could be found for optional parameters, the default value is passed.

**Referenced parameters are NOT supported**, you have to register such entries using the `set` method.

## Implicit autowiring:

Planck also offers the option of implicit autowiring, i.e. classes that have not yet been stored in the container but are requested can be created automatically.  

To activate this, the following method must be called:

```php
$container->enableImplicitAutowiring(true); // enable
$container->enableImplicitAutowiring(false); // disable
```

Now the following can be called without errors:

```php
$container = new \Hartmann\Planck\Container
$container->enableImplicitAutowiring(true);

$container->set('autowired', $container->autowire(function(Foo $foo, Bar $bar) {
    return ...
}));

$value = $container->get('autowired');
```

After the class has been implicitly loaded, it is stored directly in the container.  
Only classes can be loaded implicitly.

## Resolve Strategies
Resolve strategies can be used to automatically resolve classes that can be similarly created.  
For example, if you use FormRequests to validate input fields, they can be resolved using a corresponding strategy without having to create a service factory for each one.

This could look like this:
```php
use \Hartmann\ResolveStrategy\ResolveStrategyInterface

class RequestResolveStrategy implements ResolveStrategyInterface
{
    public function suitable(string $class): bool
    {
        return method_exists($class, 'createFromEnvironment') && in_array(FormRequest::class, class_parents($class));
    }

    public function resolve(\Psr\Container\ContainerInterface $container, string $class)
    {
        return call_user_func([$class, 'createFromEnvironment'], $container->get('environment'));
    }
}

$container = new \Hartmann\Planck\Container();

$container->enableImplicitAutowiring(true);
$container->addResolveStrategy(new RequestResolveStrategy());

$container->get(CreateUserFormRequest::class);
$container->get(DeletePostFormRequest::class);
$container->get(LoginFormRequest::class);
```

___For this to work, implicit autowiring must be enabled.___
