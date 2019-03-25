<?php


use Interop\Container\ServiceProviderInterface;
use PHPUnit\Framework\TestCase;
use Planck\Container;
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

    //
    //    public function testPreservedFunctionIsNotTreatedAsFactory()
    //    {
    //        $preserved = function ($foo, $bar) {
    //            return $foo + $bar;
    //        };
    //
    //        $container = new Container;
    //        $container->set('nonFactoryFunction', preserve($preserved));
    //
    ////        var_dump($container->get('nonFactoryFunction'));
    //
    //        $this->assertSame($preserved, $container->get('nonFactoryFunction'));
    //    }
    //
    //    public function testTryingToPreserveNonCallableObjectRaisesInvalidArgumentException()
    //    {
    //        $this->expectException(InvalidArgumentException::class);
    //
    //        $container = new Container;
    //        $container->set('nonFactoryFunction', $container->preserve('non callable object'));
    //    }
    //
    //    public function testUnsetRemovesAllRemainingsOfEntry()
    //    {
    //        $this->expectException(NotFoundException::class);
    //
    //        $container = new Container;
    //        $container->set('factory', function (ContainerInterface $container) {
    //            return 'foo';
    //        });
    //        $container->set('preserved', $container->preserve(function (ContainerInterface $container) {
    //            return 'foo';
    //        }));
    //
    //        $container->unset('factory');
    //        $container->unset('preserved');
    //
    //        $container->get('factory');
    //        $container->get('preserved');
    //    }
    //
    //    /**
    //     * @dataProvider valueProvider
    //     */
    //    public function testSetMethodSetsValue($value, $expected)
    //    {
    //        $container = new Container;
    //        $container->set('value', $value);
    //
    //        $this->assertSame($expected, $container->get('value'));
    //    }
    //
    //    /**
    //     * @covers \Planck\Container::register
    //     * @covers \Planck\Container::get
    //     */
    //    public function testContainerRegistersFactories()
    //    {
    //        $object = new stdClass;
    //
    //        $provider = $this->getMockBuilder(ServiceProviderInterface::class)->setMethods([
    //            'getFactories',
    //            'getExtensions',
    //        ])->getMock();
    //
    //        $provider->expects($this->any())->method('getFactories')->willReturn([
    //            'value1' => function () use ($object) {
    //                return $object;
    //            },
    //        ]);
    //
    //        $provider->expects($this->any())->method('getExtensions')->willReturn([]);
    //
    //        $this->assertSame($object, (new Container([$provider]))->get('value1'));
    //    }
    //
    //    /**
    //     * @covers \Planck\Container::register
    //     * @covers \Planck\Container::extend
    //     * @covers \Planck\Container::get
    //     */
    //    public function testContainerRegistersExtensions()
    //    {
    //        $provider = $this->getMockBuilder(ServiceProviderInterface::class)->setMethods([
    //            'getFactories',
    //            'getExtensions',
    //        ])->getMock();
    //
    //        $provider->expects($this->any())->method('getFactories')->willReturn([
    //            'ext' => function () {
    //                return ['hello'];
    //            },
    //        ]);
    //
    //        $provider->expects($this->any())->method('getExtensions')->willReturn([
    //            'new' => function () {
    //                return 123;
    //            },
    //            'ext' => function (ContainerInterface $container, ?array $array) {
    //                $array[] = 'world';
    //
    //                return $array;
    //            },
    //        ]);
    //
    //        $this->assertEquals(['hello', 'world'], (new Container([$provider]))->get('ext'));
    //        $this->assertSame(123, (new Container([$provider]))->get('new'));
    //    }
    //
    //    /**
    //     * @covers \Planck\Container::register
    //     * @covers \Planck\Container::extend
    //     */
    //    public function testContainerThrowsInvalidArgumentExceptionTryingToRegistersNonCallableExtensionFactory()
    //    {
    //        $this->expectException(InvalidArgumentException::class);
    //
    //        $provider = $this->getMockBuilder(ServiceProviderInterface::class)->setMethods([
    //            'getFactories',
    //            'getExtensions',
    //        ])->getMock();
    //
    //        $provider->expects($this->any())->method('getFactories')->willReturn([]);
    //        $provider->expects($this->any())->method('getExtensions')->willReturn([
    //            'new' => 123,
    //        ]);
    //
    //        new Container([$provider]);
    //    }
    //
    //    /**
    //     * @covers \Planck\Container::register
    //     */
    //    public function testContainerThrowsInvalidArgumentExceptionTryingToRegisterNonCallableFactory()
    //    {
    //        $this->expectException(InvalidArgumentException::class);
    //
    //        $provider = $this->getMockBuilder(ServiceProviderInterface::class)->setMethods([
    //            'getFactories',
    //            'getExtensions',
    //        ])->getMock();
    //
    //        $provider->expects($this->any())->method('getFactories')->willReturn([
    //            'id' => '3424fde',
    //        ]);
    //
    //        new Container([$provider]);
    //    }
    //
    //    /**
    //     * @covers \Planck\Container::__construct
    //     */
    //    public function testCanBeInstanciatedWithoutParameters()
    //    {
    //        $this->assertInstanceOf(ContainerInterface::class, new Container());
    //    }
    //
    //    public function testGetThrowsNotFoundExceptionIfUnknownIdPassedToGet()
    //    {
    //        $this->expectException(NotFoundException::class);
    //
    //        (new Container())->get('unknownId');
    //    }
    //
    //    /**
    //     * @dataProvider valueProvider
    //     *
    //     * @param $key
    //     * @param $value
    //     * @param $expected
    //     */
    //    public function testGetProvidesValue($value, $expected)
    //    {
    //        $actual = (new Container([], ['value' => $value]))->get('value');
    //        $this->assertSame($expected, $actual);
    //    }
    //
    //    public function testHasReturnsFalseIfUnknownId()
    //    {
    //        $this->assertEquals(false, (new Container())->has('unknownId'));
    //    }
    //
    //    public function testHasReturnsTrueIfKnownId()
    //    {
    //        $this->assertEquals(true, (new Container([], ['knownId' => 123]))->has('knownId'));
    //    }
}
