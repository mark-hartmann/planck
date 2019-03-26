<?php


use Interop\Container\ServiceProviderInterface;
use PHPUnit\Framework\TestCase;
use Planck\Container;
use Planck\Exception\DependencyException;
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

    public function testGetReturnsCorrectValue(): void
    {
        $container = new Container([], [
            'foo' => 123,
        ]);

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

        $this->assertEquals(1234, $container->get('scalar'));
        $this->assertIsObject($container->get('object'));
        $this->assertIsCallable($container->get('preserved'));
        $this->assertNotSame($container->get('factory'), $container->get('factory'));
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

    public function testRegisterSuccessfullyAddsServiceFactories(): void
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
                return [];
            }
        };

        $container = new Container([$provider]);

        $this->assertIsString($container->get('foo'));
    }

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
     * @throws \Planck\Exception\NotFoundException
     */
    public function testAlreadyDefinedEntryCanBeExtended(): void
    {
        $container = new Container([], [
            'foo' => 'bar',
        ]);

        $container->extend('foo', function (ContainerInterface $container, $prev) {
            return 'foo'.$prev;
        });

        $this->assertEquals('foobar', $container->get('foo'));
    }

    /**
     * @throws \Planck\Exception\NotFoundException
     */
    public function testExtendThrowsNotFoundExceptionIfUnknownId(): void
    {
        $this->expectException(NotFoundExceptionInterface::class);

        (new Container)->extend('foo', function () {
        });
    }

    /**
     * @throws \Planck\Exception\NotFoundException
     */
    public function testExtendThrowsInvalidArgumentExceptionIfNonCallable(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Container([], [
            'foo' => 'bar',
        ]))->extend('foo', 'noncallable');
    }

    /**
     * @throws \Planck\Exception\NotFoundException
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
     * @throws \ReflectionException
     * @throws \Planck\Exception\NotFoundException
     */
    public function testAutowiringThrowsDependencyExceptionIfUnableToResolve(): void
    {
        $this->expectException(DependencyException::class);
        $this->expectExceptionMessageRegExp('/^Unable to resolve param/');

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
     * @throws \ReflectionException
     * @throws \Planck\Exception\NotFoundException
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
     * @throws \ReflectionException
     * @throws \Planck\Exception\NotFoundException
     */
    public function testAutowiringFunctionDoesWork(): void
    {
        $function = function (stdClass $class) {
            return $class;
        };

        $container = new Container([], [
            stdClass::class => new stdClass(),
        ]);

        $container->set('autowiredFunction', $container->autowire($function));

        $this->assertInstanceOf(stdClass::class, $container->get('autowiredFunction'));
    }

    /**
     * @throws \ReflectionException
     * @throws \Planck\Exception\NotFoundException
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
     * @throws \ReflectionException
     * @throws \Planck\Exception\NotFoundException
     */
    public function testAutowireThrowsInvalidArgumentExceptionIfNonWireablePassed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Container())->autowire('lol');
    }

    /**
     * @throws \ReflectionException
     * @throws \Planck\Exception\NotFoundException
     */
    public function testAutowireThrowsDependencyExceptionIfParameterClassUnresolvable(): void
    {
        $this->expectException(DependencyException::class);

        $container = new Container();
        $container->autowire(function (string $class) {
        })($container);
    }

    /**
     * @throws \Planck\Exception\NotFoundException
     * @throws \ReflectionException
     */
    public function testAutowireResolvesByParameterNameIfOptionIsSet(): void
    {
        $container = new Container([], [
            'foo' => 'bar'
        ]);
        $container->set('resolveByName', $container->autowire(function (string $foo) {
            return $foo;
        }, true));

        var_dump($container->get('resolveByName'));

        $this->assertEquals('bar', $container->get('resolveByName'));
    }
}
