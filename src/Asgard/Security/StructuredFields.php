<?php

namespace Native\Api\Client\Asgard\Security;

/**
 * The parts of Structured Field Values (RFC 8941) that signatures and
 * digests need: parsing a Dictionary, and serialising items, parameters and
 * inner lists.
 *
 * A bare item is ['type' => ..., 'value' => ...] where type is integer,
 * decimal, string, token, bytes or boolean. A member also carries 'params'
 * (name => bare item). An inner list has type 'inner' and a list of members
 * as its value.
 */
final class StructuredFields
{
    private int $i = 0;

    private function __construct(private readonly string $s) {}

    /** @return array<string, array{type: string, value: mixed, params: array<string, array{type: string, value: mixed}>}> */
    public static function parseDictionary(string $input): array
    {
        $parser = new self($input);
        $parser->skip(' ');
        $dictionary = [];

        while (! $parser->done()) {
            $key = $parser->key();

            if ($parser->peek() === '=') {
                $parser->i++;
                $member = $parser->peek() === '(' ? $parser->innerList() : $parser->item();
            } else {
                $member = ['type' => 'boolean', 'value' => true, 'params' => $parser->params()];
            }

            $dictionary[$key] = $member;

            $parser->skip(" \t");
            if ($parser->done()) {
                break;
            }
            $parser->expect(',');
            $parser->skip(" \t");
            if ($parser->done()) {
                throw new SecurityException('A dictionary ends with a comma.');
            }
        }

        return $dictionary;
    }

    // Constructors for bare items.

    public static function string(string $value): array
    {
        return ['type' => 'string', 'value' => $value];
    }

    public static function integer(int $value): array
    {
        return ['type' => 'integer', 'value' => $value];
    }

    public static function bytes(string $value): array
    {
        return ['type' => 'bytes', 'value' => $value];
    }

    public static function boolean(bool $value): array
    {
        return ['type' => 'boolean', 'value' => $value];
    }

    // Serialising.

    /** @param array{type: string, value: mixed} $item */
    public static function serializeBareItem(array $item): string
    {
        return match ($item['type']) {
            'integer' => (string) $item['value'],
            'string' => self::serializeString($item['value']),
            'token' => $item['value'],
            'bytes' => ':'.base64_encode($item['value']).':',
            'boolean' => $item['value'] ? '?1' : '?0',
            default => throw new SecurityException("Cannot serialise a {$item['type']}."),
        };
    }

    /** @param array<string, array{type: string, value: mixed}> $params */
    public static function serializeParams(array $params): string
    {
        $out = '';
        foreach ($params as $name => $value) {
            $out .= ';'.$name;
            if ($value['type'] !== 'boolean' || $value['value'] !== true) {
                $out .= '='.self::serializeBareItem($value);
            }
        }

        return $out;
    }

    /** @param array{type: string, value: mixed, params?: array<string, array{type: string, value: mixed}>} $item */
    public static function serializeItem(array $item): string
    {
        return self::serializeBareItem($item).self::serializeParams($item['params'] ?? []);
    }

    /**
     * @param  list<array{type: string, value: mixed, params?: array}>  $items
     * @param  array<string, array{type: string, value: mixed}>  $params
     */
    public static function serializeInnerList(array $items, array $params): string
    {
        return '('.implode(' ', array_map(self::serializeItem(...), $items)).')'.self::serializeParams($params);
    }

    public static function serializeString(string $value): string
    {
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            throw new SecurityException('A structured string must be printable ASCII.');
        }

        return '"'.addcslashes($value, '"\\').'"';
    }

    // Parsing internals.

    private function done(): bool
    {
        return $this->i >= strlen($this->s);
    }

    private function peek(): string
    {
        return $this->s[$this->i] ?? '';
    }

    private function skip(string $chars): void
    {
        while (! $this->done() && str_contains($chars, $this->s[$this->i])) {
            $this->i++;
        }
    }

    private function expect(string $char): void
    {
        if ($this->peek() !== $char) {
            throw new SecurityException("Expected '{$char}' at {$this->i}.");
        }
        $this->i++;
    }

    private function key(): string
    {
        if (! preg_match('/\G[a-z*][a-z0-9_.*-]*/', $this->s, $m, 0, $this->i)) {
            throw new SecurityException("Expected a key at {$this->i}.");
        }
        $this->i += strlen($m[0]);

        return $m[0];
    }

    private function innerList(): array
    {
        $this->expect('(');
        $items = [];

        while (true) {
            $this->skip(' ');

            if ($this->peek() === ')') {
                $this->i++;

                return ['type' => 'inner', 'value' => $items, 'params' => $this->params()];
            }

            $items[] = $this->item();

            if (! in_array($this->peek(), [' ', ')'], true)) {
                throw new SecurityException("Malformed inner list at {$this->i}.");
            }
        }
    }

    private function item(): array
    {
        return $this->bareItem() + ['params' => $this->params()];
    }

    private function params(): array
    {
        $params = [];

        while ($this->peek() === ';') {
            $this->i++;
            $this->skip(' ');
            $name = $this->key();
            $value = self::boolean(true);

            if ($this->peek() === '=') {
                $this->i++;
                $value = $this->bareItem();
            }

            $params[$name] = $value;
        }

        return $params;
    }

    private function bareItem(): array
    {
        $c = $this->peek();

        if ($c === '-' || ctype_digit($c)) {
            return $this->number();
        }

        if ($c === '"') {
            return $this->sfString();
        }

        if ($c === ':') {
            if (! preg_match('/\G:([A-Za-z0-9+\/=]*):/', $this->s, $m, 0, $this->i)) {
                throw new SecurityException("Malformed byte sequence at {$this->i}.");
            }
            $this->i += strlen($m[0]);
            $bytes = base64_decode($m[1], true);
            if ($bytes === false) {
                throw new SecurityException('Malformed base64 in a byte sequence.');
            }

            return self::bytes($bytes);
        }

        if ($c === '?') {
            $value = $this->s[$this->i + 1] ?? '';
            if ($value !== '0' && $value !== '1') {
                throw new SecurityException("Malformed boolean at {$this->i}.");
            }
            $this->i += 2;

            return self::boolean($value === '1');
        }

        if ($c === '*' || ctype_alpha($c)) {
            preg_match('/\G[A-Za-z*][!#$%&\'*+\-.^_`|~0-9A-Za-z:\/]*/', $this->s, $m, 0, $this->i);
            $this->i += strlen($m[0]);

            return ['type' => 'token', 'value' => $m[0]];
        }

        throw new SecurityException("Unexpected '{$c}' at {$this->i}.");
    }

    private function number(): array
    {
        if (! preg_match('/\G-?[0-9]+(\.[0-9]+)?/', $this->s, $m, 0, $this->i)) {
            throw new SecurityException("Malformed number at {$this->i}.");
        }

        $text = $m[0];
        $this->i += strlen($text);

        if (isset($m[1])) {
            [$whole, $fraction] = explode('.', ltrim($text, '-'));
            if (strlen($whole) > 12 || strlen($fraction) > 3) {
                throw new SecurityException('A decimal is out of range.');
            }

            return ['type' => 'decimal', 'value' => (float) $text];
        }

        if (strlen(ltrim($text, '-')) > 15) {
            throw new SecurityException('An integer is out of range.');
        }

        return self::integer((int) $text);
    }

    private function sfString(): array
    {
        $this->i++;
        $out = '';

        while (! $this->done()) {
            $c = $this->s[$this->i++];

            if ($c === '\\') {
                $next = $this->s[$this->i++] ?? '';
                if ($next !== '"' && $next !== '\\') {
                    throw new SecurityException('Bad escape in a string.');
                }
                $out .= $next;
            } elseif ($c === '"') {
                return self::string($out);
            } elseif (ord($c) < 0x20 || ord($c) > 0x7E) {
                throw new SecurityException('A string holds a character outside printable ASCII.');
            } else {
                $out .= $c;
            }
        }

        throw new SecurityException('A string is not closed.');
    }
}
