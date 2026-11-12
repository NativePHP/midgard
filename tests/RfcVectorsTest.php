<?php

use Native\Api\Client\Asgard\Security\Aes128Gcm;
use Native\Api\Client\Asgard\Security\Base64Url;
use Native\Api\Client\Asgard\Security\ContentDigest;
use Native\Api\Client\Asgard\Security\Hkdf;
use Native\Api\Client\Asgard\Security\SecurityException;
use Native\Api\Client\Asgard\Security\StructuredFields;
use Native\Api\Client\Asgard\Security\Wire;

// Published test vectors. Each test names the RFC section it comes from.

it('matches RFC 5869 A.1 (HKDF-SHA256, basic)', function () {
    $okm = Hkdf::derive(
        hex2bin(str_repeat('0b', 22)), 42, hex2bin('f0f1f2f3f4f5f6f7f8f9'), hex2bin('000102030405060708090a0b0c'),
    );

    expect(bin2hex($okm))->toBe('3cb25f25faacd57a90434f64d0362f2a2d2d0a90cf1a5a4c5db02d56ecc4c5bf34007208d5b887185865');
});

it('matches RFC 5869 A.3 (HKDF-SHA256, empty salt and info)', function () {
    expect(bin2hex(Hkdf::derive(hex2bin(str_repeat('0b', 22)), 42)))
        ->toBe('8da4e775a563c18f715f802a063c5a31b8a11f5c5ee1879ec3454e5f3c738d2d9d201395faa4b61a96c8');
});

it('encrypts RFC 8188 3.1 byte for byte', function () {
    $body = Aes128Gcm::encrypt('I am the walrus', Base64Url::decode('yqdlZ-tYemfogSmv7Ws5PQ'), '', 4096, Base64Url::decode('I1BsxtFttlv3u_Oo94xnmw'));

    expect(Base64Url::encode($body))->toBe('I1BsxtFttlv3u_Oo94xnmwAAEAAA-NAVub2qFgBEuQKRapoZu-IxkIva3MEB1PD-ly8Thjg');
});

it('decrypts RFC 8188 3.1', function () {
    $body = Base64Url::decode('I1BsxtFttlv3u_Oo94xnmwAAEAAA-NAVub2qFgBEuQKRapoZu-IxkIva3MEB1PD-ly8Thjg');

    expect(Aes128Gcm::decrypt($body, Base64Url::decode('yqdlZ-tYemfogSmv7Ws5PQ')))->toBe('I am the walrus')
        ->and(Aes128Gcm::keyId($body))->toBe('');
});

it('decrypts RFC 8188 3.2 (two records, padding, a key id)', function () {
    $body = Base64Url::decode('uNCkWiNYzKTnBN9ji3-qWAAAABkCYTHOG8chz_gnvgOqdGYovxyjuqRyJFjEDyoF1Fvkj6hQPdPHI51OEUKEpgz3SsLWIqS_uA');

    expect(Aes128Gcm::decrypt($body, Base64Url::decode('BO3ZVPxUlnLORbVGMpbT1Q')))->toBe('I am the walrus')
        ->and(Aes128Gcm::keyId($body))->toBe('a1');
});

it('splits RFC 8188 3.2 records the way the RFC does when unpadded', function () {
    // rs 25 leaves 8 bytes of data per record: "I am the" then " walrus".
    $ikm = Base64Url::decode('BO3ZVPxUlnLORbVGMpbT1Q');
    $body = Aes128Gcm::encrypt('I am the walrus', $ikm, 'a1', 25);

    expect(strlen($body))->toBe(16 + 4 + 1 + 2 + 25 + 7 + 17)
        ->and(Aes128Gcm::decrypt($body, $ikm))->toBe('I am the walrus');
});

it('round trips many records at the default record size, including an exact multiple', function (int $length) {
    $ikm = random_bytes(16);
    $plain = $length === 0 ? '' : random_bytes($length);

    expect(Aes128Gcm::decrypt(Aes128Gcm::encrypt($plain, $ikm, 'session'), $ikm))->toBe($plain);
})->with([0, 1, 4079, 4080, 4081, 3 * 4079, 20000]);

it('refuses a truncated, reordered or tampered body', function () {
    $ikm = random_bytes(16);
    $body = Aes128Gcm::encrypt(str_repeat('x', 100), $ikm, '', 25);
    $header = substr($body, 0, 21);
    $records = str_split(substr($body, 21), 25);

    $truncated = $header.implode('', array_slice($records, 0, -1));
    $reordered = $header.$records[1].$records[0].implode('', array_slice($records, 2));
    $tampered = $body;
    $tampered[30] = chr(ord($tampered[30]) ^ 1);

    foreach ([$truncated, $reordered, $tampered, substr($body, 0, 21)] as $bad) {
        expect(fn () => Aes128Gcm::decrypt($bad, $ikm))->toThrow(SecurityException::class);
    }
});

it('matches the RFC 9530 B.1 Content-Digest', function () {
    $content = "{\"hello\": \"world\"}\n";

    expect(ContentDigest::header($content))->toBe('sha-256=:RK/0qy18MlBSVnWgjwz6lZEWjP/lF5HF9bvEF8FabDg=:')
        ->and(ContentDigest::matches('sha-256=:RK/0qy18MlBSVnWgjwz6lZEWjP/lF5HF9bvEF8FabDg=:', $content))->toBeTrue()
        ->and(ContentDigest::matches('sha-512=:abc=:, sha-256=:RK/0qy18MlBSVnWgjwz6lZEWjP/lF5HF9bvEF8FabDg=:', $content))->toBeTrue()
        ->and(ContentDigest::matches('sha-256=:RK/0qy18MlBSVnWgjwz6lZEWjP/lF5HF9bvEF8FabDg=:', $content.' '))->toBeFalse()
        ->and(ContentDigest::matches('sha-256=RK', $content))->toBeFalse()
        ->and(ContentDigest::matches(null, $content))->toBeFalse();
});

it('matches the RFC 9421 B.2.5 hmac-sha256 signature base and value', function () {
    $input = StructuredFields::parseDictionary('sig-b25=("date" "@authority" "content-type");created=1618884473;keyid="test-shared-secret"')['sig-b25'];
    $signature = StructuredFields::parseDictionary('sig-b25=:pxcQw6G3AjtMBQjwo8XzkZf/bws5LelbaMk5rGIGtE8=:')['sig-b25'];

    $values = ['date' => 'Tue, 20 Apr 2021 02:07:55 GMT', '@authority' => 'example.com', 'content-type' => 'application/json'];
    [$serialized, $base] = Wire::signatureBase($input['value'], $input['params'], fn (string $name) => $values[$name]);

    $key = base64_decode('uzvJfB4u3N0Jy4T7NZ75MDVcr8zSTInedJtkgcu46YW4XByzNJjxBdtjUkdJPBtbmHhIDi6pcl8jsasjlTMtDQ==');

    expect($serialized)->toBe('("date" "@authority" "content-type");created=1618884473;keyid="test-shared-secret"')
        ->and($base)->toBe(
            "\"date\": Tue, 20 Apr 2021 02:07:55 GMT\n"
            ."\"@authority\": example.com\n"
            ."\"content-type\": application/json\n"
            .'"@signature-params": ("date" "@authority" "content-type");created=1618884473;keyid="test-shared-secret"'
        )
        ->and(hash_hmac('sha256', $base, $key, true))->toBe($signature['value']);
});

it('parses and re-serialises the structured fields signatures use', function () {
    $dict = StructuredFields::parseDictionary('a=("x" "y";req;key="b");created=1;nonce="n", sig2=:AAE=:, flag, c=?0, t=tok/1');

    expect(StructuredFields::serializeInnerList($dict['a']['value'], $dict['a']['params']))->toBe('("x" "y";req;key="b");created=1;nonce="n"')
        ->and($dict['sig2']['value'])->toBe("\x00\x01")
        ->and($dict['flag']['value'])->toBeTrue()
        ->and($dict['c']['value'])->toBeFalse()
        ->and($dict['t'])->toMatchArray(['type' => 'token', 'value' => 'tok/1']);

    foreach (['a=(', 'a="x', 'a=1,', 'A=1', 'a=:%%:', 'a="\\x"', 'a=1 b=2'] as $bad) {
        expect(fn () => StructuredFields::parseDictionary($bad))->toThrow(SecurityException::class);
    }
});
