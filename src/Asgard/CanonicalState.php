<?php

namespace Native\Api\Client\Asgard;

use InvalidArgumentException;
use stdClass;

/**
 * The canonical state of Asgard protocol §5.1: a state serialized with the
 * JSON Canonicalization Scheme (RFC 8785). Members are sorted by their
 * UTF-16 code units, there is no whitespace, numbers are IEEE 754 doubles
 * written the way ECMAScript writes them, and strings escape only control
 * characters, the quote and the backslash. Two states are the same when
 * their canonical states are the same bytes.
 *
 * Takes a value from PHP or json_decode(), with objects either as stdClass or
 * as associative arrays. An empty PHP array cannot distinguish `{}` from `[]`,
 * so callers encoding a root state object pass `(object) $state`.
 */
final class CanonicalState
{
    /** Integers above this are not exact as doubles, so they are written as one. */
    private const MAX_SAFE_INTEGER = 9007199254740992;

    /**
     * @throws InvalidArgumentException for a value with no canonical form:
     *                                  a string that is not UTF-8, a number
     *                                  that is not finite, or anything that
     *                                  is not plain JSON
     */
    public static function encode(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            $value === true => 'true',
            $value === false => 'false',
            is_int($value) => abs($value) <= self::MAX_SAFE_INTEGER ? (string) $value : self::number((float) $value),
            is_float($value) => self::number($value),
            is_string($value) => self::string($value),
            $value instanceof stdClass => self::object(get_object_vars($value)),
            is_array($value) && array_is_list($value) => '['.implode(',', array_map(self::encode(...), $value)).']',
            is_array($value) => self::object($value),
            default => throw new InvalidArgumentException('A state may only hold plain JSON values.'),
        };
    }

    /** @param  array<array-key, mixed>  $members */
    private static function object(array $members): string
    {
        $names = array_map('strval', array_keys($members));
        $sortKeys = array_map(self::utf16(...), $names);
        $values = array_values($members);

        $order = array_keys($names);
        usort($order, fn (int $a, int $b) => strcmp($sortKeys[$a], $sortKeys[$b]));

        $out = [];
        foreach ($order as $i) {
            $out[] = self::string($names[$i]).':'.self::encode($values[$i]);
        }

        return '{'.implode(',', $out).'}';
    }

    /** Big-endian UTF-16, so comparing bytes compares code units. */
    private static function utf16(string $name): string
    {
        if (! mb_check_encoding($name, 'UTF-8')) {
            throw new InvalidArgumentException('A state member name is not valid UTF-8.');
        }

        return mb_convert_encoding($name, 'UTF-16BE', 'UTF-8');
    }

    private static function string(string $value): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException('A state string is not valid UTF-8, so it has no canonical form.');
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR);
    }

    /** A double as ECMAScript's Number.prototype.toString writes it. */
    private static function number(float $value): string
    {
        if (! is_finite($value)) {
            throw new InvalidArgumentException('A state number must be finite.');
        }

        if ($value == 0.0) {
            return '0';
        }

        // The shortest decimal that reads back as the same double.
        $shortest = sprintf('%.17g', $value);
        for ($precision = 0; $precision <= 16; $precision++) {
            $candidate = sprintf("%.{$precision}e", $value);
            if ((float) $candidate === $value) {
                $shortest = $candidate;
                break;
            }
        }

        $sign = $value < 0 ? '-' : '';
        [$mantissa, $exponent] = explode('e', ltrim(strtolower($shortest), '-')) + [1 => '0'];
        [$whole, $fraction] = explode('.', $mantissa) + [1 => ''];

        // value = 0.<digits> × 10^point
        $digits = $whole.$fraction;
        $point = strlen($whole) + (int) $exponent;
        $trimmed = ltrim($digits, '0');
        $point -= strlen($digits) - strlen($trimmed);
        $digits = rtrim($trimmed, '0');
        $count = strlen($digits);

        if ($count <= $point && $point <= 21) {
            return $sign.$digits.str_repeat('0', $point - $count);
        }

        if ($point > 0 && $point <= 21) {
            return $sign.substr($digits, 0, $point).'.'.substr($digits, $point);
        }

        if ($point > -6 && $point <= 0) {
            return $sign.'0.'.str_repeat('0', -$point).$digits;
        }

        $e = $point - 1;

        return $sign.$digits[0].($count > 1 ? '.'.substr($digits, 1) : '').'e'.($e > 0 ? '+' : '-').abs($e);
    }
}
