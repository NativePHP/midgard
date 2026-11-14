<?php

namespace Native\Api\Client\Asgard;

use Native\Api\Client\Asgard\Attributes\LocalOnly;
use Native\Mobile\Attributes\Computed;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\NativeRouter;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * What a component sends to the server as its state: its public, non-static
 * props that are not #[LocalOnly], and the values of its #[Computed]
 * methods that are not #[LocalOnly] either.
 *
 * A computed value goes under the name the component's views read it by,
 * which is the method's name (`revenue()` is `$this->revenue`). To the
 * server it is an ordinary state key. It is worked out afresh for every
 * send by calling the method, never taken from the render memo, so it is
 * what the props it was sent with give. It is read-only: a delta cannot
 * set it (ServerMethods::reconcile()).
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

    /** @var array<class-string, array<string, ReflectionMethod>> */
    protected static array $computed = [];

    /**
     * The values the component sends now. An uninitialised typed prop is
     * left out rather than read: `ReflectionProperty::getValue()` throws on
     * one, and a screen with `public User $user;` filled in by mount() is
     * an ordinary shape.
     *
     * Computed values come after the props. One that throws, or that JSON
     * cannot carry at all, is left out and logged under debug rather than
     * breaking the send.
     */
    public static function of(NativeComponent $component): array
    {
        $state = [];

        foreach (static::properties($component) as $name => $prop) {
            if ($prop->isInitialized($component)) {
                $state[$name] = $prop->getValue($component);
            }
        }

        foreach (static::computed($component) as $name => $method) {
            try {
                $value = $method->invoke($method->isStatic() ? null : $component);
            } catch (\Throwable $e) {
                NativeRouter::debugLog(
                    'Asgard: #[Computed] ['.$name.'] on '.$component::class.' threw ('.$e->getMessage().') — left out of state.'
                );

                continue;
            }

            if (! static::sendable($value)) {
                NativeRouter::debugLog(
                    'Asgard: #[Computed] ['.$name.'] on '.$component::class.' returned a '.get_debug_type($value)
                    .', which cannot be sent — left out of state.'
                );

                continue;
            }

            $state[$name] = $value;
        }

        return $state;
    }

    /**
     * The #[Computed] methods a component sends, by the name its views read
     * them by. Not the #[LocalOnly] ones, and not one a public prop of the
     * same name hides (`$this->name` reads the prop, so the view never sees
     * the method).
     *
     * @return array<string, ReflectionMethod>
     */
    public static function computed(object|string $component): array
    {
        $class = is_object($component) ? $component::class : $component;

        if (! isset(static::$computed[$class])) {
            static::scan($class);
        }

        return static::$computed[$class];
    }

    /** Whether a name is one of the #[Computed] values the component sends. */
    public static function isComputed(object|string $component, string $name): bool
    {
        $class = is_object($component) ? $component::class : $component;

        if (! isset(static::$computed[$class])) {
            static::scan($class);
        }

        return isset(static::$computed[$class][$name]);
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

    /** Whether a public prop or #[Computed] of the component is #[LocalOnly]. */
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

        $computed = [];

        // Read the way NativeComponent::computedMeta() reads them: every
        // method, any visibility, carrying #[Computed] on the method the
        // class ends up with.
        foreach ((new ReflectionClass($class))->getMethods() as $method) {
            $name = $method->getName();

            if ($method->getAttributes(Computed::class) === [] || isset($properties[$name]) || isset($localOnly[$name])) {
                continue;
            }

            if (static::markedLocalOnly($method)) {
                $localOnly[$name] = true;
            } else {
                $computed[$name] = $method;
            }
        }

        static::$properties[$class] = $properties;
        static::$localOnly[$class] = $localOnly;
        static::$computed[$class] = $computed;
    }

    /**
     * Whether JSON can carry a computed value. An object goes as props do
     * (a region request keeps only plain values, a call encodes it), but a
     * closure, a resource, NaN or anything else json_encode() refuses would
     * fail the whole send, so it is left out instead.
     */
    protected static function sendable(mixed $value): bool
    {
        if ($value instanceof \Closure) {
            return false;
        }

        try {
            json_encode($value, JSON_THROW_ON_ERROR);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Attributes are read off the declaring class, so a subclass that
     * declares the prop again (to change its default, say) would drop the
     * mark. Walking up the parents keeps it: a prop or computed method is
     * local-only if any class that declares it says so.
     */
    protected static function markedLocalOnly(ReflectionProperty|ReflectionMethod $member): bool
    {
        $name = $member->getName();
        $isProp = $member instanceof ReflectionProperty;

        for ($class = $member->getDeclaringClass(); $class !== false; $class = $class->getParentClass()) {
            $declared = $isProp
                ? ($class->hasProperty($name) ? $class->getProperty($name) : null)
                : ($class->hasMethod($name) ? $class->getMethod($name) : null);

            if ($declared !== null && $declared->getAttributes(LocalOnly::class) !== []) {
                return true;
            }
        }

        return false;
    }
}
