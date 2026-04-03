<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Types;

use Doctrine\DBAL\Types\Exception\TypeAlreadyRegistered;
use Doctrine\DBAL\Types\Exception\TypeNotFound;
use Doctrine\DBAL\Types\Exception\TypeNotRegistered;
use Doctrine\DBAL\Types\Exception\TypesAlreadyExists;
use Doctrine\DBAL\Types\Exception\TypesException;
use Doctrine\DBAL\Types\Exception\UnknownColumnType;
use InvalidArgumentException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Symfony\Contracts\Service\ServiceProviderInterface;
use WeakMap;

use function array_fill_keys;
use function array_keys;
use function get_debug_type;
use function sprintf;

/**
 * The type registry is responsible for holding a map of all known DBAL types.
 */
final class TypeRegistry
{
    /** Map of type names and their corresponding class names. */
    private const BUILTIN_TYPES_MAP = [
        Types::ASCII_STRING         => AsciiStringType::class,
        Types::BIGINT               => BigIntType::class,
        Types::BINARY               => BinaryType::class,
        Types::BLOB                 => BlobType::class,
        Types::BOOLEAN              => BooleanType::class,
        Types::DATE_MUTABLE         => DateType::class,
        Types::DATE_IMMUTABLE       => DateImmutableType::class,
        Types::DATEINTERVAL         => DateIntervalType::class,
        Types::DATETIME_MUTABLE     => DateTimeType::class,
        Types::DATETIME_IMMUTABLE   => DateTimeImmutableType::class,
        Types::DATETIMETZ_MUTABLE   => DateTimeTzType::class,
        Types::DATETIMETZ_IMMUTABLE => DateTimeTzImmutableType::class,
        Types::DECIMAL              => DecimalType::class,
        Types::NUMBER               => NumberType::class,
        Types::ENUM                 => EnumType::class,
        Types::FLOAT                => FloatType::class,
        Types::GUID                 => GuidType::class,
        Types::INTEGER              => IntegerType::class,
        Types::JSON                 => JsonType::class,
        Types::JSON_OBJECT          => JsonType::class,
        Types::JSONB                => JsonbType::class,
        Types::JSONB_OBJECT         => JsonbType::class,
        Types::SIMPLE_ARRAY         => SimpleArrayType::class,
        Types::SMALLFLOAT           => SmallFloatType::class,
        Types::SMALLINT             => SmallIntType::class,
        Types::STRING               => StringType::class,
        Types::TEXT                 => TextType::class,
        Types::TIME_MUTABLE         => TimeType::class,
        Types::TIME_IMMUTABLE       => TimeImmutableType::class,
    ];

    /** @var array<string, Type> Map of type names and their corresponding flyweight objects. */
    private array $instances = [];

    /**
     * Lazy factories for types not yet instantiated.
     * Values are either a class-string (built-in) or a ContainerInterface (container type).
     *
     * @var array<string, class-string<Type>|ContainerInterface>
     */
    private array $factories = [];

    /** @var WeakMap<Type, string> */
    private WeakMap $instancesReverseIndex;

    /**
     * Creates a registry pre-populated with all built-in types. Additional types passed via
     * {@param $instances} are registered on top; if a name matches a built-in type it is
     * overridden rather than re-registered.
     *
     * A {@see ServiceProviderInterface} can be passed instead of an array to lazy-load type
     * instances from a service container. Types are resolved on first access and cached.
     *
     * @param array<string, Type|ContainerInterface>|ServiceProviderInterface<Type> $instances
     *
     * @throws TypeAlreadyRegistered
     * @throws TypesException
     */
    public function __construct(array|ServiceProviderInterface $instances = [])
    {
        $this->instancesReverseIndex = new WeakMap();

        if ($instances instanceof ServiceProviderInterface) {
            $this->factories = array_fill_keys(array_keys($instances->getProvidedServices()), $instances);
        } else {
            foreach ($instances as $name => $instance) {
                if ($instance instanceof ContainerInterface) {
                    $this->factories[$name] = $instance;
                    continue;
                }

                if (! $instance instanceof Type) {
                    throw new InvalidArgumentException(sprintf(
                        'Unexpected value for type "%s", got "%s".',
                        $name,
                        get_debug_type($instance),
                    ));
                }

                if (isset($this->instancesReverseIndex[$instance])) {
                    throw TypeAlreadyRegistered::new($instance);
                }

                $this->instances[$name]                 = $instance;
                $this->instancesReverseIndex[$instance] = $name;
            }
        }

        foreach (self::BUILTIN_TYPES_MAP as $name => $class) {
            if (isset($this->instances[$name]) || isset($this->factories[$name])) {
                continue;
            }

            $this->factories[$name] = $class;
        }
    }

    /**
     * Finds a type by the given name.
     *
     * @throws TypesException
     */
    public function get(string $name): Type
    {
        $type = $this->instances[$name] ?? null;
        if ($type !== null) {
            return $type;
        }

        $factory = $this->factories[$name] ?? null;
        if ($factory === null) {
            throw TypeNotFound::new($name);
        }

        if ($factory instanceof ContainerInterface) {
            try {
                $type = $factory->get($name);
            } catch (ContainerExceptionInterface $exception) {
                unset($this->factories[$name]);
                if (! $factory->has($name)) {
                    throw UnknownColumnType::new($name, $exception);
                }

                // @phpstan-ignore missingType.checkedException
                throw $exception;
            }
        } else {
            $type = new $factory();
        }

        if (isset($this->instancesReverseIndex[$type])) {
            throw TypeAlreadyRegistered::new($type);
        }

        unset($this->factories[$name]);
        $this->instances[$name]             = $type;
        $this->instancesReverseIndex[$type] = $name;

        return $type;
    }

    /**
     * Finds a name for the given type.
     *
     * @throws TypesException
     */
    public function lookupName(Type $type): string
    {
        $name = $this->findTypeName($type);

        if ($name === null) {
            throw TypeNotRegistered::new($type);
        }

        return $name;
    }

    /**
     * Checks if there is a type of the given name.
     */
    public function has(string $name): bool
    {
        return isset($this->instances[$name]) || isset($this->factories[$name]);
    }

    /**
     * Registers a custom type to the type map.
     *
     * @throws TypesException
     */
    public function register(string $name, Type $type): void
    {
        if (isset($this->instances[$name]) || isset($this->factories[$name])) {
            throw TypesAlreadyExists::new($name);
        }

        if ($this->findTypeName($type) !== null) {
            throw TypeAlreadyRegistered::new($type);
        }

        $this->instances[$name]             = $type;
        $this->instancesReverseIndex[$type] = $name;
    }

    /**
     * Overrides an already defined type to use a different implementation.
     *
     * @throws TypeNotFound
     * @throws TypeAlreadyRegistered
     */
    public function override(string $name, Type $type): void
    {
        $origType = $this->instances[$name] ?? null;
        if ($origType === null) {
            if (! isset($this->factories[$name])) {
                throw TypeNotFound::new($name);
            }

            // Type is not yet instantiated — replace factory with the new instance directly
            if (($this->findTypeName($type) ?? $name) !== $name) {
                throw TypeAlreadyRegistered::new($type);
            }

            unset($this->factories[$name]);
            $this->instances[$name]             = $type;
            $this->instancesReverseIndex[$type] = $name;

            return;
        }

        if (($this->findTypeName($type) ?? $name) !== $name) {
            throw TypeAlreadyRegistered::new($type);
        }

        unset($this->instancesReverseIndex[$origType]);
        $this->instances[$name]             = $type;
        $this->instancesReverseIndex[$type] = $name;
    }

    /**
     * Gets the map of all registered types and their corresponding type instances.
     *
     * @internal
     *
     * @return array<string, Type>
     *
     * @throws TypesException
     */
    public function getMap(): array
    {
        // Ensure all types are loaded before returning the map
        foreach ($this->factories as $name => $factory) {
            $this->get($name);
        }

        return $this->instances;
    }

    private function findTypeName(Type $type): ?string
    {
        return $this->instancesReverseIndex[$type] ?? null;
    }
}
