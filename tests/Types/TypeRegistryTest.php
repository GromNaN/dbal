<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Tests\Types;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Types\BinaryType;
use Doctrine\DBAL\Types\BlobType;
use Doctrine\DBAL\Types\Exception\TypeNotRegistered;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\TextType;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\TypeRegistry;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Contracts\Service\ServiceProviderInterface;

use function array_map;
use function count;
use function interface_exists;
use function sprintf;

class TypeRegistryTest extends TestCase
{
    private const TEST_TYPE_NAME       = 'test';
    private const OTHER_TEST_TYPE_NAME = 'other';

    private TypeRegistry $registry;
    private BlobType $testType;
    private BinaryType $otherTestType;

    protected function setUp(): void
    {
        $this->testType      = new BlobType();
        $this->otherTestType = new BinaryType();

        $this->registry = new TypeRegistry([
            self::TEST_TYPE_NAME       => $this->testType,
            self::OTHER_TEST_TYPE_NAME => $this->otherTestType,
        ]);
    }

    public function testGet(): void
    {
        self::assertSame($this->testType, $this->registry->get(self::TEST_TYPE_NAME));
        self::assertSame($this->otherTestType, $this->registry->get(self::OTHER_TEST_TYPE_NAME));

        $this->expectException(Exception::class);
        $this->registry->get('unknown');
    }

    public function testGetReturnsSameInstances(): void
    {
        self::assertSame(
            $this->registry->get(self::TEST_TYPE_NAME),
            $this->registry->get(self::TEST_TYPE_NAME),
        );
    }

    public function testLookupName(): void
    {
        self::assertSame(
            self::TEST_TYPE_NAME,
            $this->registry->lookupName($this->testType),
        );
        self::assertSame(
            self::OTHER_TEST_TYPE_NAME,
            $this->registry->lookupName($this->otherTestType),
        );

        $this->expectException(TypeNotRegistered::class);
        $this->registry->lookupName(new TextType());
    }

    public function testHas(): void
    {
        self::assertTrue($this->registry->has(self::TEST_TYPE_NAME));
        self::assertTrue($this->registry->has(self::OTHER_TEST_TYPE_NAME));
        self::assertFalse($this->registry->has('unknown'));
    }

    public function testRegister(): void
    {
        $newType = new TextType();

        $this->registry->register('some', $newType);

        self::assertTrue($this->registry->has('some'));
        self::assertSame($newType, $this->registry->get('some'));
    }

    public function testRegisterWithAlreadyRegisteredName(): void
    {
        $this->registry->register('some', new TextType());

        $this->expectException(Exception::class);
        $this->registry->register('some', new TextType());
    }

    public function testRegisterWithAlreadyRegisteredInstance(): void
    {
        $newType = new TextType();

        $this->registry->register('type1', $newType);
        $this->expectException(Exception::class);
        $this->registry->register('type2', $newType);
    }

    public function testConstructorWithDuplicateInstance(): void
    {
        $newType = new TextType();

        $this->expectException(Exception::class);
        new TypeRegistry(['a' => $newType, 'b' => $newType]);
    }

    public function testOverride(): void
    {
        $baseType     = new TextType();
        $overrideType = new StringType();

        $this->registry->register('some', $baseType);
        $this->registry->override('some', $overrideType);

        self::assertSame($overrideType, $this->registry->get('some'));
    }

    public function testOverrideAllowsExistingInstance(): void
    {
        $type = new TextType();

        $this->registry->register('some', $type);
        $this->registry->override('some', $type);

        self::assertSame($type, $this->registry->get('some'));
    }

    public function testOverrideWithUnknownType(): void
    {
        $this->expectException(Exception::class);
        $this->registry->override('unknown', new TextType());
    }

    public function testOverrideWithAlreadyRegisteredInstance(): void
    {
        $newType = new TextType();

        $this->registry->register('first', $newType);
        $this->registry->register('second', new StringType());

        $this->expectException(Exception::class);
        $this->registry->override('second', $newType);
    }

    public function testGetMap(): void
    {
        $registeredTypes = $this->registry->getMap();

        // Built-in types plus the two registered in setUp()
        self::assertGreaterThan(2, count($registeredTypes));
        self::assertArrayHasKey(self::TEST_TYPE_NAME, $registeredTypes);
        self::assertArrayHasKey(self::OTHER_TEST_TYPE_NAME, $registeredTypes);
        self::assertSame($this->testType, $registeredTypes[self::TEST_TYPE_NAME]);
        self::assertSame($this->otherTestType, $registeredTypes[self::OTHER_TEST_TYPE_NAME]);
    }

    private function requireServiceProvider(): void
    {
        if (interface_exists(ServiceProviderInterface::class)) {
            return;
        }

        self::markTestSkipped('symfony/service-contracts is not installed.');
    }

    /** @param array<string, Type> $types */
    private function createServiceProvider(array $types): ServiceProviderInterface
    {
        return new class ($types) implements ServiceProviderInterface {
            /** @param array<string, Type> $types */
            public function __construct(private array $types)
            {
            }

            public function get(string $id): Type
            {
                return $this->types[$id];
            }

            public function has(string $id): bool
            {
                return isset($this->types[$id]);
            }

            /** @return array<string, string> */
            public function getProvidedServices(): array
            {
                return array_map(static fn (Type $t): string => $t::class, $this->types);
            }
        };
    }

    public function testServiceProviderIsLazy(): void
    {
        $this->requireServiceProvider();

        $type     = new BlobType();
        $provider = new class ($type) implements ServiceProviderInterface {
            public int $resolved = 0;

            public function __construct(private Type $type)
            {
            }

            public function get(string $id): Type
            {
                ++$this->resolved;

                return $this->type;
            }

            public function has(string $id): bool
            {
                return $id === 'custom';
            }

            /** @return array<string, string> */
            public function getProvidedServices(): array
            {
                return ['custom' => BlobType::class];
            }
        };

        $registry = new TypeRegistry($provider);

        self::assertSame(0, $provider->resolved, 'Provider must not be called during construction.');
        self::assertTrue($registry->has('custom'));
        self::assertSame(0, $provider->resolved, 'Provider must not be called by has().');

        $registry->get('custom');
        self::assertSame(1, $provider->resolved, 'Provider must be called exactly once on first get().');

        $registry->get('custom');
        self::assertSame(1, $provider->resolved, 'Provider must not be called again after the instance is cached.');
    }

    public function testServiceProviderLookupName(): void
    {
        $this->requireServiceProvider();

        $type     = new BlobType();
        $registry = new TypeRegistry($this->createServiceProvider(['custom' => $type]));

        self::assertSame('custom', $registry->lookupName($registry->get('custom')));
    }

    public function testServiceProviderBuiltinTypesStillAvailable(): void
    {
        $this->requireServiceProvider();

        $registry = new TypeRegistry($this->createServiceProvider([]));

        self::assertTrue($registry->has(Types::STRING));
        self::assertInstanceOf(StringType::class, $registry->get(Types::STRING));
    }

    public function testServiceProviderCanOverrideBuiltinType(): void
    {
        $this->requireServiceProvider();

        $custom   = new BlobType();
        $registry = new TypeRegistry($this->createServiceProvider([Types::STRING => $custom]));

        self::assertSame($custom, $registry->get(Types::STRING));
    }

    public function testArrayInstancesOverrideBuiltinTypes(): void
    {
        $custom   = new BlobType();
        $registry = new TypeRegistry([Types::STRING => $custom]);

        self::assertSame($custom, $registry->get(Types::STRING));
    }

    public function testServiceProviderGetMapResolvesAllTypes(): void
    {
        $this->requireServiceProvider();

        $type     = new BlobType();
        $registry = new TypeRegistry($this->createServiceProvider(['custom' => $type]));

        $map = $registry->getMap();

        self::assertArrayHasKey('custom', $map);
        self::assertSame($type, $map['custom']);
        self::assertArrayHasKey(Types::STRING, $map);
    }

    public function testServiceProviderUnknownTypeThrows(): void
    {
        $this->requireServiceProvider();

        $registry = new TypeRegistry($this->createServiceProvider([]));

        $this->expectException(Exception::class);
        $registry->get('unknown');
    }

    public function testBuiltinTypesAvailableByDefault(): void
    {
        Type::getTypeRegistry()->register(__FUNCTION__, new class extends StringType {
        });
        $registry = new TypeRegistry();

        // Types from the singleton registry are not registered in a new instance
        self::assertFalse($registry->has(__FUNCTION__));

        // Check that all the constants from Types are registered by default
        $constants = (new ReflectionClass(Types::class))->getConstants();
        foreach ($constants as $typeName) {
            self::assertTrue(
                $registry->has($typeName),
                sprintf('Built-in type "%s" is not registered by default.', $typeName),
            );
        }
    }
}
