<?php

declare(strict_types=1);

use Marko\Encryption\Contracts\EncryptorInterface;
use Marko\Encryption\Exceptions\DecryptionException;
use Marko\Testing\Exceptions\AssertionFailedException;
use Marko\Testing\Fake\FakeEncryptor;

it('implements EncryptorInterface', function () {
    expect(new FakeEncryptor())->toBeInstanceOf(EncryptorInterface::class);
});

it('round-trips a value through encrypt and decrypt', function () {
    $encryptor = new FakeEncryptor();

    $encrypted = $encryptor->encrypt('top-secret');

    expect($encrypted)->toStartWith('fake-encrypted:')
        ->and($encrypted)->not->toContain('top-secret')
        ->and($encryptor->decrypt($encrypted))->toBe('top-secret');
});

it('round-trips binary values', function () {
    $encryptor = new FakeEncryptor();
    $bytes = random_bytes(32);

    expect($encryptor->decrypt($encryptor->encrypt($bytes)))->toBe($bytes);
});

it('produces different ciphertext for each call, like a real encryptor', function () {
    $encryptor = new FakeEncryptor();

    expect($encryptor->encrypt('same'))->not->toBe($encryptor->encrypt('same'));
});

it('decrypts values encrypted by another instance', function () {
    $encrypted = new FakeEncryptor()->encrypt('shared', 'users.ssn');

    expect(new FakeEncryptor()->decrypt($encrypted, 'users.ssn'))->toBe('shared');
});

it('round-trips a value with matching associated data', function () {
    $encryptor = new FakeEncryptor();

    $encrypted = $encryptor->encrypt('123-45-6789', 'users.ssn');

    expect($encryptor->decrypt($encrypted, 'users.ssn'))->toBe('123-45-6789');
});

it('throws when decrypt is given different associated data than encrypt', function () {
    $encryptor = new FakeEncryptor();
    $encrypted = $encryptor->encrypt('123-45-6789', 'users.ssn');

    expect(fn () => $encryptor->decrypt($encrypted, 'users.email'))
        ->toThrow(
            DecryptionException::class,
            "FakeEncryptor: decrypt() was given associated data 'users.email', "
            . "but the value was encrypted with 'users.ssn'",
        );
});

it('throws when decrypt omits associated data that encrypt was given', function () {
    $encryptor = new FakeEncryptor();
    $encrypted = $encryptor->encrypt('value', 'users.ssn');

    expect(fn () => $encryptor->decrypt($encrypted))
        ->toThrow(
            DecryptionException::class,
            "was given associated data '', but the value was encrypted with 'users.ssn'",
        );
});

it('throws an invalid payload exception for values it did not encrypt', function () {
    $encryptor = new FakeEncryptor();

    expect(fn () => $encryptor->decrypt('not-valid-ciphertext'))
        ->toThrow(DecryptionException::class, 'The encrypted payload is invalid')
        ->and(fn () => $encryptor->decrypt('fake-encrypted:garbage'))
        ->toThrow(DecryptionException::class, 'The encrypted payload is invalid');
});

it('records encrypt calls with the value, associated data and result', function () {
    $encryptor = new FakeEncryptor();

    $encrypted = $encryptor->encrypt('secret', 'users.ssn');

    expect($encryptor->encrypted)->toBe([
        ['value' => 'secret', 'aad' => 'users.ssn', 'encrypted' => $encrypted],
    ]);
});

it('records decrypt calls with the payload, associated data and result', function () {
    $encryptor = new FakeEncryptor();
    $encrypted = $encryptor->encrypt('secret', 'users.ssn');

    $encryptor->decrypt($encrypted, 'users.ssn');

    expect($encryptor->decrypted)->toBe([
        ['encrypted' => $encrypted, 'aad' => 'users.ssn', 'value' => 'secret'],
    ]);
});

it('does not record a decrypt call that fails', function () {
    $encryptor = new FakeEncryptor();

    try {
        $encryptor->decrypt('not-valid-ciphertext');
    } catch (DecryptionException) {
    }

    expect($encryptor->decrypted)->toBe([]);
});

it('passes assertEncrypted when a matching value was encrypted', function () {
    $encryptor = new FakeEncryptor();
    $encryptor->encrypt('secret', 'users.ssn');

    $encryptor->assertEncrypted();
    $encryptor->assertEncrypted('secret');
    $encryptor->assertEncrypted(aad: 'users.ssn');
    $encryptor->assertEncrypted('secret', 'users.ssn');

    expect($encryptor->encrypted)->toHaveCount(1);
});

it('throws from assertEncrypted when nothing was encrypted', function () {
    new FakeEncryptor()->assertEncrypted();
})->throws(AssertionFailedException::class, 'Expected at least one encrypted value');

it('throws from assertEncrypted when no call matches the value or associated data', function () {
    $encryptor = new FakeEncryptor();
    $encryptor->encrypt('secret', 'users.ssn');

    expect(fn () => $encryptor->assertEncrypted('other'))
        ->toThrow(AssertionFailedException::class)
        ->and(fn () => $encryptor->assertEncrypted('secret', 'users.email'))
        ->toThrow(AssertionFailedException::class, "associated data 'users.email'");
});

it('passes assertDecrypted when a matching value was decrypted', function () {
    $encryptor = new FakeEncryptor();
    $encryptor->decrypt($encryptor->encrypt('secret', 'users.ssn'), 'users.ssn');

    $encryptor->assertDecrypted();
    $encryptor->assertDecrypted('secret');
    $encryptor->assertDecrypted(aad: 'users.ssn');
    $encryptor->assertDecrypted('secret', 'users.ssn');

    expect($encryptor->decrypted)->toHaveCount(1);
});

it('throws from assertDecrypted when no call matches', function () {
    $encryptor = new FakeEncryptor();
    $encryptor->encrypt('secret', 'users.ssn');

    expect(fn () => $encryptor->assertDecrypted())
        ->toThrow(AssertionFailedException::class, 'Expected at least one decrypted value')
        ->and(fn () => $encryptor->assertDecrypted('secret'))
        ->toThrow(AssertionFailedException::class);
});

it('asserts nothing was encrypted or decrypted', function () {
    $encryptor = new FakeEncryptor();

    $encryptor->assertNothingEncrypted();
    $encryptor->assertNothingDecrypted();

    $encrypted = $encryptor->encrypt('secret');

    expect(fn () => $encryptor->assertNothingEncrypted())
        ->toThrow(AssertionFailedException::class, 'Expected no encrypted values')
        ->and(fn () => $encryptor->assertNothingDecrypted())->not->toThrow(AssertionFailedException::class);

    $encryptor->decrypt($encrypted);

    expect(fn () => $encryptor->assertNothingDecrypted())
        ->toThrow(AssertionFailedException::class, 'Expected no decrypted values');
});

it('clears recorded calls', function () {
    $encryptor = new FakeEncryptor();
    $encryptor->decrypt($encryptor->encrypt('secret'));

    $encryptor->clear();

    expect($encryptor->encrypted)->toBe([])
        ->and($encryptor->decrypted)->toBe([]);
});
