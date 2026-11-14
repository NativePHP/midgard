<?php

namespace Native\Api\Client\Asgard;

use Native\Api\Client\Asgard\Attributes\LocalOnly;
use Native\Mobile\Edge\NativeComponent;
use ReflectionClass;
use ReflectionProperty;

/**
 * The props a component sends to the server as its state: public, not
 * static, and not #[LocalOnly].
 *
 * Every path that gathers state (region requests and batches, actions,
 * `#[Server]` calls, uploads, and the push subscriptions built from what
 * was sent) reads it from here, so a new path cannot forget a rule. Kept
 * per class, since a class's props cannot change at runtime.
 *
 * Public only, and that is a boundary rather than a convenience: the
 * `native*` internals a component keeps protected never leave the device,
 * and a delta can never reach them, whatever a server puts in it.
 */
class ComponentState
{
    /** @var array<class-string, array<string, ReflectionProperty>> */
    protected static array $properties = [];

    /** @var array<class-string, array<string, true>> */
    protected static array $localOnly = [];

    /**
     * The values the component sends now. An uninitialised typed prop is
     * left out rather than read: `ReflectionProperty::getValue()` throws on
     * one, and a screen with `public User $user;` filled in by mount() is
     * an ordinary shape.
     */
    public static function of(NativeComponent $component): array
    {
        $state = [];

        foreach (static::properties($component) as $name => $prop) {
            if ($prop->isInitialized($component)) {
                $state[$name] = $prop->getValue($component);
            }
        }

        return $state;
    }

    /** @return array<string, ReflectionProperty> */
    public static function properties(object|string $component): array
    {
        $class = is_object($component) ? $component::class : $component;

        if (! isset(static::$properties[$class])) {
            static::scan($class);
        }

        return static::$properties[$class];
    }

    /** Whether a public prop of the component is #[LocalOnly]. */
    public static function isLocalOnly(object|string $component, string $prop): bool
    {
        $class = is_object($component) ? $component::class : $component;

        if (! isset(static::$localOnly[$class])) {
            static::scan($class);
        }

        return isset(static::$localOnly[$class][$prop]);
    }

    protected static function scan(string $class): void
    {
        $properties = [];
        $localOnly = [];

        foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic()) {
                continue;
            }

            if (static::markedLocalOnly($prop)) {
                $localOnly[$prop->getName()] = true;
            } else {
                $properties[$prop->getName()] = $prop;
            }
        }

        static::$properties[$class] = $properties;
        static::$localOnly[$class] = $localOnly;
    }

    /**
     * Attributes are read off the declaring class, so a subclass that
     * declares the prop again (to change its default, say) would drop the
     * mark. Walking up the parents keeps it: a prop is local-only if any
     * class that declares it says so.
     */
    protected static function markedLocalOnly(ReflectionProperty $prop): bool
    {
        for ($class = $prop->getDeclaringClass(); $class !== false; $class = $class->getParentClass()) {
            if ($class->hasProperty($prop->getName())
                && $class->getProperty($prop->getName())->getAttributes(LocalOnly::class) !== []) {
                return true;
            }
        }

        return false;
    }
}
