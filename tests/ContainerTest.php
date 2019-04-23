<?php


use Hartmann\Planck\Container;
use Hartmann\Planck\Exception\DependencyException;
use Interop\Container\ServiceProviderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

class ContainerTest extends TestCase
{
    public function preserveProvider(): array
    {
        return [
            [
                function ($foo, $bar) {
                    return $foo + $bar;
                },
            ],
            [
                new class
                {
                    public function __invoke()
                    {
                        return 'foo';
                    }
                },
            ],
        ];
    }

    /**
     * @throws \Hartmann\Planck\Exception\NotFoundException
     */
    public function testGetReturnsCorrectValue(): void
    {
        $container = new Container();
        $container->set('foo', 123);

        $this->assertTrue($container->has('foo'));
        $this->assertFalse($container->has('bar'));
    }

    public function testSetMethodSetsValues(): void
    {
        $container = new Container;
        $container->set('scalar', 1234);
        $container->set('object', new class
        {
            protected $id;

            public function __construct()
            {
                $this->id = bin2hex(random_bytes(4));
            }
        });
        $container->set('preserved', $container->preserve(function () {
            return 'foo'.'bar';
        }));
        $container->set('factory', $container->factory(function () {
            return random_int(1000, 9999);
        }));
        $container->set('aurowiredFactory', $container->autowire($container->factory(function () {
            return random_int(1000, 9999);
        })));

        $this->assertEquals(1234, $container->get('scalar'));
        $this->assertIsObject($container->get('object'));
        $this->assertIsCallable($container->get('preserved'));
        $this->assertNotSame($container->get('factory'), $container->get('factory'));
        $this->assertNotSame($container->get('aurowiredFactory'), $container->get('aurowiredFactory'));
    }

    public function testGetThrowsNotFoundExceptionIfEntryNotFound(): void
    {
        $this->expectException(NotFoundExceptionInterface::class);
        (new Container)->get('foo');
    }

    public function testUnsetRemovesAllRemainingsOfEntry(): void
    {
        $this->expectException(NotFoundExceptionInterface::class);

        $container = new Container;

        $container->set('factory', $container->factory(function () {
            return 'foo';
        }));
        $container->set('preserved', $container->preserve(function () {
            return 'foo';
        }));

        $container->unset('factory');
        $container->unset('preserved');

        $container->get('factory');
        $container->get('preserved');
    }

    /**
     * @throws \Hartmann\Planck\Exception\NotFoundException
     */
    public function testRegisterSuccessfullyAddsServiceFactoriesAndNonFactories(): void
    {
        $provider = new class implements ServiceProviderInterface
        {

            public function getFactories(): array
            {
                return [
                    'foo' => function () {
                        return 'bar';
                    },
                    'bar' => 'foo',
                    'callableFoo' => [self::class, 'resolveFoo'],
                    stdClass::class => function ($container) {
                        return $container->autowire(stdClass::class);
                    },
                ];
            }

            public function getExtensions(): array
            {
                return [];
            }

            public static function resolveFoo(): string
            {
                return 'bar';
            }
        };

        $container = new Container([$provider]);

        $this->assertEquals('bar', $container->get('foo'));
        $this->assertEquals('foo', $container->get('bar'));
        $this->assertEquals('bar', $container->get('callableFoo'));
        $this->assertInstanceOf(stdClass::class, $container->get(stdClass::class));
    }

    /**
     * @throws \Hartmann\Planck\Exception\NotFoundException
     */
    public function testRegisterSuccessfullyAddsServiceExtensions(): void
    {
        $provider = new class implements ServiceProviderInterface
        {

            public function getFactories(): array
            {
                return [
                    'foo' => function () {
                        return 'bar';
                    },
                ];
            }

            public function getExtensions(): array
            {
                return [
                    'foo' => function (ContainerInterface $container, $prev) {
                        return 'foo'.$prev;
                    },
                ];
            }
        };

        $container = new Container([$provider]);

        $this->assertIsString($container->get('foo'));
        $this->assertEquals('foobar', $container->get('foo'));
    }

    /**
     * @throws \Hartmann\Planck\Exception\NotFoundException
     */
    public function testAlreadyDefinedEntryCanBeExtended(): void
    {
        $container = new Container;
        $container->set('foo', 'bar');

        $container->extend('foo', function (ContainerInterface $container, $prev) {
            return 'foo'.$prev;
        });

        $this->assertEquals('foobar', $container->get('foo'));
    }

    /**
     * @throws \Hartmann\Planck\Exception\NotFoundException
     */
    public function testExtendThrowsNotFoundExceptionIfUnknownId(): void
    {
        $this->expectException(NotFoundExceptionInterface::class);

        (new Container)->extend('foo', function () {
        });
    }

    /**
     * @throws \Hartmann\Planck\Exception\NotFoundException
     */
    public function testExtendThrowsInvalidArgumentExceptionIfNonCallable(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $container = new Container;
        $container->set('foo', 'bar');
        $container->extend('foo', 'noncollable');
    }

    /**
     * @throws \Hartmann\Planck\Exception\NotFoundException
     */
    public function testExtendingExistingFacotry(): void
    {
        $container = new Container();
        $container->set('factory', $container->factory(function () {
            return new stdClass();
        }));

        $container->extend('factory', function (ContainerInterface $container, $previous) {
            $previous->foo = bin2hex(random_bytes(4));

            return $previous;
        });

        $this->assertInstanceOf(stdClass::class, $container->get('factory'));
        $this->assertIsString($container->get('factory')->foo);
    }

    /**
     * @dataProvider preserveProvider
     *
     * @param callable $function
     *
     * @throws \Hartmann\Planck\Exception\NotFoundException
     */
    public function testPreserveWorksWithFunctionsAndAnonymousClasses($function): void
    {
        $this->assertIsCallable((new Container())->preserve($function));
    }

    public function testPreserveThrowsExceptionIfNonInvokableIsPassed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Container)->preserve([
            new class
            {
                public function method(): void
                {
                }
            },
            'method',
        ]);
    }

    public function testFactoryMethodThrowsInvalidArgumentExceptionIfNonInvokablePassed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Container)->factory([
            new class
            {
                public function method(): void
                {
                }
            },
            'method',
        ]);
    }

    /**
     * @throws \Hartmann\Planck\Exception\NotFoundException
     */
    public function testAutowiringThrowsDependencyExceptionIfUnableToResolve(): void
    {
        $this->expectException(DependencyException::class);
        $this->expectExceptionMessageRegExp('/(.+?) could not be resolved/');

        $class = get_class(new class(new stdClass())
        {
            public $class;

            public function __construct(stdClass $class)
            {
                $this->class = $class;
            }
        });

        $container = new Container();
        $container->autowire($class)($container);
    }

    /**
     * @throws \Hartmann\Planck\Exception\NotFoundException
     */
    public function testAutowiringClassDoesWork(): void
    {
        $class = get_class(new class(new stdClass())
        {
            public $class;

            public function __construct(stdClass $class)
            {
                $this->class = $class;
            }
        });

        $container = new Container();
        $container->set(stdClass::class, new stdClass());
        $container->set('autowired', $container->autowire($class));

        $this->assertInstanceOf(stdClass::class, $container->get('autowired')->class);
    }

    /**
     * @throws \Hartmann\Planck\Exception\NotFoundException
     */
    public function testAutowiringFunctionDoesWork(): void
    {
        $function = function (stdClass $class) {
            return $class;
        };

        $container = new Container;
        $container->set(stdClass::class, new stdClass());

        $container->set('autowiredFunction', $container->autowire($function));

        $this->assertInstanceOf(stdClass::class, $container->get('autowiredFunction'));
    }

    /**
     * @throws \Hartmann\Planck\Exception\NotFoundException
     * @deprecated You can autowire an factory by using autowire(factory())
     */
    public function testAutowiredCanBeUtilizedAsFactory(): void
    {
        $func = function (stdClass $class) {
            $class->id = random_int(1000, 9999);

            return $class;
        };

        $container = new Container();
        $container->set(stdClass::class, new stdClass());
        $container->set('func', $container->factory($container->autowire($func)));

        $this->assertNotEquals($container->get('func')->id, $container->get('func')->id);
    }

    /**
     * @throws \Hartmann\Planck\Exception\NotFoundException
     */
    public function testAutowireThrowsInvalidArgumentExceptionIfNonWireablePassed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Container())->autowire('lol');
    }

    /**
     * @throws \Hartmann\Planck\Exception\NotFoundException
     */
    public function testAutowireThrowsDependencyExceptionIfParameterClassUnresolvable(): void
    {
        $this->expectException(DependencyException::class);

        $container = new Container();
        $container->autowire(function (string $class) {
        })($container);
    }

    public function testAutowireHandlesArrayCallablesStaticMethods(): void
    {
        $class = new class
        {
            public static function foo(stdClass $class): string
            {
                return get_class($class);
            }
        };

        $container = new Container();
        $container->set(stdClass::class, new stdClass());
        $container->set('autowired', $container->autowire([$class, 'foo']));

        $this->assertEquals(stdClass::class, $container->get('autowired'));
    }

    public function testAutowireHandlesArrayCallablesInstanceMethods(): void
    {
        $class = new class
        {
            public function foo(stdClass $class): string
            {
                return get_class($class);
            }
        };

        $container = new Container();
        $container->set(stdClass::class, new stdClass());
        $container->set('autowired', $container->autowire([$class, 'foo']));

        $this->assertEquals(stdClass::class, $container->get('autowired'));
    }

    public function testAutowireAutoloadsClassIfPassedAsStringAndNotManagedByController(): void
    {
        $class = get_class(new class(new stdClass())
        {

            protected $class;

            public function __construct(stdClass $class)
            {
                $this->class = $class;
            }

            public function foo(): string
            {
                return get_class($this->class);
            }
        });

        $container = new Container();
        $container->set(stdClass::class, new stdClass());
        $container->set('autowired', $container->autowire([$class, 'foo']));

        $this->assertEquals(stdClass::class, $container->get('autowired'));
    }

    public function testAutowiringThrowsExceptionIfHandlesNonPublicMethods(): void
    {
        $this->expectExceptionMessage('$wireable must be a full qualified classname or callable');

        $class = new class
        {
            protected function foo(stdClass $class): string
            {
                return get_class($class);
            }
        };

        $container = new Container();
        $container->set('autowired', $container->autowire([$class, 'foo']));
    }

    public function testAutowiringUsesParametersArray(): void
    {
        $container = new Container();
        $container->set('foo', $container->autowire(function (stdClass $foo) {
            return $foo;
        }, ['foo' => new stdClass()]));

        $this->assertInstanceOf(stdClass::class, $container->get('foo'));
    }

    public function testAutowiringPriorizesParametersArrayOverContainer(): void
    {
        $container = new Container();
        $container->set(stdClass::class, function () {
            $class = new stdClass();
            $class->foo = 'bar';

            return $class;
        });
        $container->set('foo', $container->autowire(function (stdClass $foo) {
            return $foo;
        }, ['foo' => new stdClass()]));

        $this->assertInstanceOf(stdClass::class, $container->get('foo'));
        $this->assertObjectNotHasAttribute('foo', $container->get('foo'));
    }

    public function testAutowiringHandlesOptionalParameters(): void
    {
        $container = new Container();
        $container->set('autowired', $container->autowire(function (stdClass $class = null) {
            return $class;
        }));

        $this->assertNull($container->get('autowired'));
    }

    public function testAutowiringHandlesNullableParameters(): void
    {
        $container = new Container();
        $container->set('nullableClass', $container->autowire(function (?stdClass $class) {
            return $class;
        }));
        $container->set('nullableScalar', $container->autowire(function (?string $foo) {
            return $foo;
        }));

        $this->assertNull($container->get('nullableClass'));
        $this->assertNull($container->get('nullableScalar'));
    }

    public function testAutowiringWorksWithoutDefinedConstructor(): void
    {
        $class = get_class(new class()
        {
            public function foo(): string
            {
                return 'foo';
            }
        });

        $container = new Container();

        $autowired = $container->autowire($class);

        $this->assertEquals('foo', $autowired($container)->foo());
    }

    public function testAutowiringWorksWithAutowiredParameter(): void
    {
        $class = get_class(new class(new stdClass())
        {
            public function __construct(stdClass $class)
            {
            }
        });

        $container = new Container();
        $container->set(stdClass::class, $container->autowire(stdClass::class));
        $container->set($class, $container->autowire($class));

        $this->assertInstanceOf($class, $container->get($class));
    }

    public function testImplicitAutowiring(): void
    {
        $container = new Container();
        $container->enableImplicitAutowiring(true);

        $container->set('autowired', $container->autowire(function (stdClass $class) {
            return $class;
        }));

        $this->assertInstanceOf(stdClass::class, $container->get('autowired'));
        $this->assertInstanceOf(SplObjectStorage::class, $container->get(SplObjectStorage::class));
    }

    public function testResolveStrategies(): void
    {
        $container = new Container();
        $container->enableImplicitAutowiring(true);
        $container->addResolveStrategy(new class implements \Hartmann\ResolveStrategy\ResolveStrategyInterface
        {
            public function resolve(ContainerInterface $container, string $class)
            {
                return new $class;
            }

            public function suitable(string $class): bool
            {
                return $class === stdClass::class;
            }
        });

        $this->assertInstanceOf(stdClass::class, $container->get(stdClass::class));
    }
}
