<?php

use NyonCode\LaravelPwa\Support\Value;

it('narrows scalars to strings and falls back for everything else', function (mixed $value, string $expected): void {
    expect(Value::string($value, 'default'))->toBe($expected);
})->with([
    ['text', 'text'],
    [42, '42'],
    [true, '1'],
    [null, 'default'],
    [['array'], 'default'],
    [new stdClass, 'default'],
]);

it('keeps null for nullable strings', function (): void {
    expect(Value::nullableString(null))->toBeNull()
        ->and(Value::nullableString(7))->toBe('7');
});

it('narrows numeric values to integers', function (): void {
    expect(Value::int('12'))->toBe(12)
        ->and(Value::int(3.9))->toBe(3)
        ->and(Value::int('abc', 5))->toBe(5)
        ->and(Value::int(null))->toBe(0);
});

it('reads booleans from env() strings and falls back for anything else', function (mixed $value, bool $expected): void {
    expect(Value::bool($value, true))->toBe($expected);
})->with([
    [false, false],
    ['false', false],
    ['0', false],
    ['off', false],
    ['true', true],
    ['1', true],
    [0, false],
    [null, true],
    ['maybe', true],
    [['array'], true],
]);

it('reads integer and boolean settings written as env() strings', function (): void {
    config()->set(['test.minutes' => '120', 'test.flag' => 'false', 'test.broken' => ['x']]);

    expect(Value::configInt('test.minutes', 5))->toBe(120)
        ->and(Value::configInt('test.missing', 5))->toBe(5)
        ->and(Value::configInt('test.broken', 5))->toBe(5)
        ->and(Value::configBool('test.flag', true))->toBeFalse()
        ->and(Value::configBool('test.missing', true))->toBeTrue();
});
