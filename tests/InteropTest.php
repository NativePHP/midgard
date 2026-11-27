<?php

use Native\Api\Client\Asgard\Security\Base64Url;
use Native\Api\Client\Asgard\Security\Ecdh;
use Native\Api\Client\Asgard\Security\Gzip;
use Native\Api\Client\Asgard\Security\SecurityException;
use Native\Api\Client\Asgard\Security\SessionKeys;
use Native\Api\Client\Asgard\Security\StructuredFields;
use Native\Api\Client\Asgard\Security\Wire;

// tests/Fixtures/asgard-interop.json is shared with nativephp/asgard, which
// runs this same test against its own copy of the classes. If either side
// drifts, its suite fails here.

beforeEach(function () {
    $this->v = json_decode(file_get_contents(__DIR__.'/Fixtures/asgard-interop.json'), true, 512, JSON_THROW_ON_ERROR);
    $this->b = fn (string $text) => Base64Url::decode($text);
    $h = $this->v['handshake'];
    $this->keys = SessionKeys::fromSecret(Base64Url::decode($h['secret']));
    $this->session = $h['session'];
});

it('agrees the same secret and keys from both sides of the handshake', function () {
    $h = $this->v['handshake'];
    $client = Ecdh::privateKey(($this->b)($h['client_private']));
    $server = Ecdh::privateKey(($this->b)($h['server_private']));

    expect(Base64Url::encode(Ecdh::publicPoint($client)))->toBe($h['client_public'])
        ->and(Base64Url::encode(Ecdh::publicPoint($server)))->toBe($h['server_public'])
        ->and(Base64Url::encode(Ecdh::agree($client, ($this->b)($h['server_public']))))->toBe($h['shared_secret'])
        ->and(Base64Url::encode(Ecdh::agree($server, ($this->b)($h['client_public']))))->toBe($h['shared_secret']);

    $secret = SessionKeys::secret(($this->b)($h['shared_secret']), ($this->b)($h['client_public']), ($this->b)($h['server_public']));
    $keys = SessionKeys::fromSecret($secret);

    expect(Base64Url::encode($secret))->toBe($h['secret'])
        ->and(Base64Url::encode($keys->sigClient))->toBe($h['keys']['sig_c'])
        ->and(Base64Url::encode($keys->sigServer))->toBe($h['keys']['sig_s'])
        ->and(Base64Url::encode($keys->encClient))->toBe($h['keys']['enc_c'])
        ->and(Base64Url::encode($keys->encServer))->toBe($h['keys']['enc_s']);
});

it('seals the request byte for byte and opens it', function () {
    $r = $this->v['request'];
    $sealed = Wire::sealRequest($this->keys, $this->session, $r['method'], $r['authority'], $r['path'], $r['plaintext'], $r['headers'], $r['created'], $r['nonce'], ($this->b)($r['salt']));

    expect($sealed['headers'])->toBe($r['expected']['headers'])
        ->and(Base64Url::encode($sealed['body']))->toBe($r['expected']['body'])
        ->and(Base64Url::encode($sealed['signature']))->toBe($r['expected']['signature']);

    $input = StructuredFields::parseDictionary($r['expected']['headers']['signature-input'])['asgard'];
    $derived = ['@method' => $r['method'], '@authority' => $r['authority'], '@path' => $r['path']];
    [, $base] = Wire::signatureBase($input['value'], $input['params'], fn ($name) => $derived[$name] ?? $r['expected']['headers'][$name]);

    expect($base)->toBe($r['expected']['signature_base'])
        ->and(Base64Url::encode(hash_hmac('sha256', $base, $this->keys->sigClient, true)))->toBe($r['expected']['signature']);

    $opened = Wire::openRequest($this->keys, $this->session, $r['method'], $r['authority'], $r['path'], $r['expected']['headers'], ($this->b)($r['expected']['body']), $r['created']);

    expect($opened['body'])->toBe($r['plaintext'])
        ->and($opened['nonce'])->toBe($r['nonce'])
        ->and(Base64Url::encode($opened['signature']))->toBe($r['expected']['signature']);
});

it('seals the response byte for byte and opens it against the request', function () {
    $r = $this->v['response'];
    $requestSignature = ($this->b)($this->v['request']['expected']['signature']);
    $sealed = Wire::sealResponse($this->keys, $this->session, $r['status'], $r['plaintext'], $requestSignature, $r['created'], ($this->b)($r['salt']));

    expect($sealed['headers'])->toBe($r['expected']['headers'])
        ->and(Base64Url::encode($sealed['body']))->toBe($r['expected']['body']);

    $input = StructuredFields::parseDictionary($r['expected']['headers']['signature-input'])['asgard'];
    [, $base] = Wire::signatureBase($input['value'], $input['params'], fn ($name) => match ($name) {
        '@status' => (string) $r['status'],
        'signature' => ':'.base64_encode($requestSignature).':',
        default => $r['expected']['headers'][$name],
    });

    expect($base)->toBe($r['expected']['signature_base'])
        ->and(Wire::openResponse($this->keys, $this->session, $r['status'], $r['expected']['headers'], ($this->b)($r['expected']['body']), $requestSignature, $r['created']))
        ->toBe($r['plaintext']);
});

it('seals the stream request and its event byte for byte and opens the event', function () {
    $s = $this->v['stream_request'];
    $sealed = Wire::sealRequest($this->keys, $this->session, $s['method'], $s['authority'], $s['path'], $s['plaintext'], $s['headers'], $s['created'], $s['nonce'], ($this->b)($s['salt']));

    expect($sealed['headers'])->toBe($s['expected']['headers'])
        ->and(Base64Url::encode($sealed['body']))->toBe($s['expected']['body'])
        ->and(Base64Url::encode($sealed['signature']))->toBe($s['expected']['signature']);

    $e = $this->v['stream_event'];
    $data = Wire::sealEvent($this->keys, $this->session, $sealed['signature'], $e['id'], $e['event'], $e['plaintext'], ($this->b)($e['salt']));

    expect($data)->toBe($e['expected']['data'])
        ->and(Wire::eventBase($sealed['signature'], $e['id'], $e['event'], 'identity', json_decode($data, true)['body']))->toBe($e['expected']['signature_base'])
        ->and(Wire::openEvent($this->keys, $this->session, $sealed['signature'], $e['id'], $e['event'], $e['expected']['data']))->toBe($e['plaintext']);
});

it('gzips a large response byte for byte, then seals and opens it', function () {
    $g = $this->v['gzip_response'];
    $requestSignature = ($this->b)($this->v['request']['expected']['signature']);

    expect(Base64Url::encode(Gzip::encode($g['plaintext'])))->toBe($g['expected']['gzip'])
        ->and(Gzip::decode(($this->b)($g['expected']['gzip'])))->toBe($g['plaintext']);

    $sealed = Wire::sealResponse($this->keys, $this->session, $g['status'], $g['plaintext'], $requestSignature, $g['created'], ($this->b)($g['salt']), $g['compress']);

    expect($sealed['headers'])->toBe($g['expected']['headers'])
        ->and($sealed['headers']['asgard-content-coding'])->toBe('gzip')
        ->and(Base64Url::encode($sealed['body']))->toBe($g['expected']['body'])
        ->and(Wire::openResponse($this->keys, $this->session, $g['status'], $g['expected']['headers'], ($this->b)($g['expected']['body']), $requestSignature, $g['created']))
        ->toBe($g['plaintext']);
});

it('gzips a large stream event byte for byte and opens it', function () {
    $g = $this->v['gzip_stream_event'];
    $streamSignature = ($this->b)($this->v['stream_request']['expected']['signature']);

    $data = Wire::sealEvent($this->keys, $this->session, $streamSignature, $g['id'], $g['event'], $g['plaintext'], ($this->b)($g['salt']), $g['compress']);

    expect($data)->toBe($g['expected']['data'])
        ->and(json_decode($data, true)['enc'])->toBe('gzip')
        ->and(Wire::eventBase($streamSignature, $g['id'], $g['event'], 'gzip', json_decode($data, true)['body']))->toBe($g['expected']['signature_base'])
        ->and(Wire::openEvent($this->keys, $this->session, $streamSignature, $g['id'], $g['event'], $g['expected']['data']))->toBe($g['plaintext']);
});

it('signs Asgard-Cache on a response byte for byte, and refuses it unsigned', function () {
    $c = $this->v['cache_response'];
    $requestSignature = ($this->b)($this->v['request']['expected']['signature']);
    $sealed = Wire::sealResponse($this->keys, $this->session, $c['status'], $c['plaintext'], $requestSignature, $c['created'], ($this->b)($c['salt']), false, $c['cache']);

    expect($sealed['headers'])->toBe($c['expected']['headers'])
        ->and($sealed['headers']['asgard-cache'])->toBe('invalidate')
        ->and(Base64Url::encode($sealed['body']))->toBe($c['expected']['body']);

    $input = StructuredFields::parseDictionary($c['expected']['headers']['signature-input'])['asgard'];
    [, $base] = Wire::signatureBase($input['value'], $input['params'], fn ($name) => match ($name) {
        '@status' => (string) $c['status'],
        'signature' => ':'.base64_encode($requestSignature).':',
        default => $c['expected']['headers'][$name],
    });

    expect($base)->toBe($c['expected']['signature_base'])
        ->and(Wire::openResponse($this->keys, $this->session, $c['status'], $c['expected']['headers'], ($this->b)($c['expected']['body']), $requestSignature, $c['created']))
        ->toBe($c['plaintext']);

    $stripped = $c['expected']['headers'];
    unset($stripped['asgard-cache']);

    expect(fn () => Wire::openResponse($this->keys, $this->session, $c['status'], $stripped, ($this->b)($c['expected']['body']), $requestSignature, $c['created']))
        ->toThrow(SecurityException::class);
});

it('opens a stream event regardless of member order and ignores unknown members', function () {
    $streamSignature = ($this->b)($this->v['stream_request']['expected']['signature']);
    $e = $this->v['stream_event'];
    $decoded = json_decode($e['expected']['data'], true);

    $reordered = json_encode(['sig' => $decoded['sig'], 'enc' => $decoded['enc'], 'body' => $decoded['body']], JSON_UNESCAPED_SLASHES);
    $withExtra = json_encode(['extra' => 1, 'body' => $decoded['body'], 'enc' => $decoded['enc'], 'sig' => $decoded['sig']], JSON_UNESCAPED_SLASHES);

    expect(Wire::openEvent($this->keys, $this->session, $streamSignature, $e['id'], $e['event'], $reordered))->toBe($e['plaintext'])
        ->and(Wire::openEvent($this->keys, $this->session, $streamSignature, $e['id'], $e['event'], $withExtra))->toBe($e['plaintext']);

    $missingEnc = json_encode(['body' => $decoded['body'], 'sig' => $decoded['sig']], JSON_UNESCAPED_SLASHES);
    expect(fn () => Wire::openEvent($this->keys, $this->session, $streamSignature, $e['id'], $e['event'], $missingEnc))
        ->toThrow(SecurityException::class);
});

it('refuses a response whose signed Asgard-Protocol header is not 1', function () {
    $r = $this->v['response'];
    $requestSignature = ($this->b)($this->v['request']['expected']['signature']);
    $body = ($this->b)($r['expected']['body']);
    $headers = $r['expected']['headers'];
    $headers['asgard-protocol'] = '2';

    $input = StructuredFields::parseDictionary($headers['signature-input'])['asgard'];
    [, $base] = Wire::signatureBase($input['value'], $input['params'], fn ($name) => match ($name) {
        '@status' => (string) $r['status'],
        'signature' => ':'.base64_encode($requestSignature).':',
        default => $headers[$name],
    });
    $headers['signature'] = 'asgard='.StructuredFields::serializeBareItem(StructuredFields::bytes(hash_hmac('sha256', $base, $this->keys->sigServer, true)));

    expect(fn () => Wire::openResponse($this->keys, $this->session, $r['status'], $headers, $body, $requestSignature, $r['created']))
        ->toThrow(SecurityException::class, 'protocol version');
});
