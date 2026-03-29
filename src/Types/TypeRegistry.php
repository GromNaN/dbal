<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Types;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Types\Exception\TypeAlreadyRegistered;
use Doctrine\DBAL\Types\Exception\TypeNotFound;
use Doctrine\DBAL\Types\Exception\TypeNotRegistered;
use Doctrine\DBAL\Types\Exception\TypesAlreadyExists;
use Doctrine\DBAL\Types\Exception\TypesException;
use Doctrine\DBAL\Types\Exception\UnknownColumnType;

use function spl_object_id;

/**
 * The type registry is responsible for holding a map of all known DBAL types.
 */
final class TypeRegistry
{
    /**
     * The map of built-in Doctrine mapping types.
     *
     * @var array<string, class-string<Type>>
     */
    public const BUILTIN_TYPES_MAP = [
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
    private array $instances;
    /** @var array<int, string> */
    private array $instancesReverseIndex;

    /**
     * Creates a registry pre-populated with all built-in types. Additional types passed via
     * {@param $instances} are registered on top; if a name matches a built-in type it is
     * overridden rather than re-registered.
     *
     * @param array<string, Type> $instances
     *
     * @throws TypesException
     */
    public function __construct(array $instances = [])
    {
        $this->instances             = [];
        $this->instancesReverseIndex = [];

        foreach (self::BUILTIN_TYPES_MAP as $name => $class) {
            $type                                              = new $class();
            $this->instances[$name]                            = $type;
            $this->instancesReverseIndex[spl_object_id($type)] = $name;
        }

        foreach ($instances as $name => $type) {
            if (isset($this->instances[$name])) {
                $this->override($name, $type);
            } else {
                $this->register($name, $type);
            }
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
        if ($type === null) {
            throw UnknownColumnType::new($name);
        }

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
        return isset($this->instances[$name]);
    }

    /**
     * Registers a custom type to the type map.
     *
     * @throws TypesException
     */
    public function register(string $name, Type $type): void
    {
        if (isset($this->instances[$name])) {
            throw TypesAlreadyExists::new($name);
        }

        if ($this->findTypeName($type) !== null) {
            throw TypeAlreadyRegistered::new($type);
        }

        $this->instances[$name]                            = $type;
        $this->instancesReverseIndex[spl_object_id($type)] = $name;
    }

    /**
     * Overrides an already defined type to use a different implementation.
     *
     * @throws Exception
     */
    public function override(string $name, Type $type): void
    {
        $origType = $this->instances[$name] ?? null;
        if ($origType === null) {
            throw TypeNotFound::new($name);
        }

        if (($this->findTypeName($type) ?? $name) !== $name) {
            throw TypeAlreadyRegistered::new($type);
        }

        unset($this->instancesReverseIndex[spl_object_id($origType)]);
        $this->instances[$name]                            = $type;
        $this->instancesReverseIndex[spl_object_id($type)] = $name;
    }

    /**
     * Gets the map of all registered types and their corresponding type instances.
     *
     * @internal
     *
     * @return array<string, Type>
     */
    public function getMap(): array
    {
        return $this->instances;
    }

    private function findTypeName(Type $type): ?string
    {
        return $this->instancesReverseIndex[spl_object_id($type)] ?? null;
    }
}
