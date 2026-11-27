<?php

use Native\Api\Client\Asgard\CanonicalState;

it('writes a state as RFC 8785 does', function (string $json, string $canonical) {
    expect(CanonicalState::encode(json_decode($json)))->toBe($canonical)
        ->and(CanonicalState::encode(json_decode($json, true)))->toBe($canonical);
})->with([
    'RFC 8785 §3.2.2 values' => [
        '{"numbers":[333333333.33333329,1E30,4.50,2e-3,0.000000000000000000000000001],"string":"\u20ac$\u000F\u000aA\'\u0042\u0022\u005c\\\\\"\/","literals":[null,true,false]}',
        '{"literals":[null,true,false],"numbers":[333333333.3333333,1e+30,4.5,0.002,1e-27],"string":"€$\u000f\nA\'B\"\\\\\\\\\"/"}',
    ],
    'sorted by UTF-16 code units' => [
        '{"\u20ac":1,"\r":2,"\ufb33":3,"1":4,"\ud83d\ude00":5,"\u0080":6,"\u00f6":7}',
        "{\"\\r\":2,\"1\":4,\"\u{80}\":6,\"\u{f6}\":7,\"\u{20ac}\":1,\"\u{1f600}\":5,\"\u{fb33}\":3}",
    ],
    'nested objects sorted, arrays kept in order' => [
        '{"b":{"z":[3,2,1],"a":{"y":null,"b":true}},"a":[{"k":"b","a":1}]}',
        '{"a":[{"a":1,"k":"b"}],"b":{"a":{"b":true,"y":null},"z":[3,2,1]}}',
    ],
    'numbers as ECMAScript writes doubles' => [
        '[0,-0,1.0,1e2,10.50,1e21,0.000001,1e-7,9007199254740993,5e-324,-1.5E-3]',
        '[0,0,1,100,10.5,1e+21,0.000001,1e-7,9007199254740992,5e-324,-0.0015]',
    ],
    'only controls, quote and backslash escaped' => [
        '{"ctl":"\u0000\b\t\u001f\u007f","esc":"\"\\\\\/","ls":"\u2028"}',
        "{\"ctl\":\"\\u0000\\b\\t\\u001f\u{7f}\",\"esc\":\"\\\"\\\\/\",\"ls\":\"\u{2028}\"}",
    ],
]);

it('gives one canonical state for every spelling of the same state', function () {
    $spellings = [
        '{"b":1,"a":[1.0,"x",{"d":false,"c":null}]}',
        '{ "a" : [ 1, "\u0078", { "c" : null, "d" : false } ], "b" : 1e0 }',
        '{"a":[10e-1,"x",{"c":null,"d":false}],"b":100E-2}',
    ];

    expect(array_unique(array_map(fn ($json) => CanonicalState::encode(json_decode($json, true)), $spellings)))
        ->toBe(['{"a":[1,"x",{"c":null,"d":false}],"b":1}']);
});

it('encodes an empty state cast to object as an empty JSON object', function () {
    expect(CanonicalState::encode((object) []))->toBe('{}');
});

it('refuses a value with no canonical form', function (mixed $value) {
    CanonicalState::encode($value);
})->with([
    'not UTF-8' => ["\xff"],
    'not UTF-8 as a member name' => [["\xff" => 1]],
    'not finite' => [INF],
    'not JSON' => [new DateTime],
])->throws(InvalidArgumentException::class);
