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
