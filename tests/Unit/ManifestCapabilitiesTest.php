<?php

use NyonCode\LaravelPwa\Manifest\ManifestValidator;

function capabilities(array $members): array
{
    return (new ManifestValidator)->capabilities(['scope' => '/app/', ...$members]);
}

it('accepts valid OS integration members', function (): void {
    expect(capabilities([
        'shortcuts' => [['name' => 'Orders', 'url' => '/app/orders'], ['name' => 'New', 'url' => '/app/orders/new']],
        'share_target' => ['action' => '/app/share', 'method' => 'POST', 'enctype' => 'multipart/form-data', 'params' => ['files' => [['name' => 'files', 'accept' => ['image/*']]]]],
        'file_handlers' => [['action' => '/app/open', 'accept' => ['text/csv' => ['.csv']]]],
        'protocol_handlers' => [['protocol' => 'web+orders', 'url' => '/app/orders?ref=%s'], ['protocol' => 'mailto', 'url' => '/app/mail?to=%s']],
        'launch_handler' => ['client_mode' => ['navigate-existing', 'auto']],
    ]))->toBe([]);
});

it('flags shortcuts without a name or outside the scope', function (): void {
    expect(capabilities(['shortcuts' => [['url' => '/app/x'], ['name' => 'Admin', 'url' => '/admin']]]))->toBe([
        '[shortcuts.0] needs a name.',
        '[shortcuts.1.url] must be a URL inside the scope [/app/]; browsers drop the shortcut.',
    ]);
});

it('flags a share target that cannot receive files', function (): void {
    expect(capabilities(['share_target' => ['action' => '/share', 'method' => 'GET', 'params' => ['files' => [['name' => 'f']]]]]))->toBe([
        '[share_target.action] must be a URL inside the scope [/app/].',
        '[share_target] with files needs method POST and enctype multipart/form-data.',
        '[share_target.params.files.0] needs a name and accepted MIME types.',
    ]);
});

it('flags file handlers with a wrong accept map', function (): void {
    expect(capabilities(['file_handlers' => [['action' => '/app/open', 'accept' => ['csv' => ['csv']]]]]))
        ->toHaveCount(1)
        ->and(capabilities(['file_handlers' => [['action' => '/app/open', 'accept' => ['csv' => ['csv']]]]])[0])->toContain('file_handlers.0.accept');
});

it('flags protocol handlers with an unknown scheme or no %s', function (): void {
    expect(capabilities(['protocol_handlers' => [['protocol' => 'orders', 'url' => '/app/o?%s'], ['protocol' => 'web+orders', 'url' => '/app/o']]]))->toBe([
        '[protocol_handlers.0.protocol] must be web+name (lowercase letters) or a safelisted scheme such as mailto.',
        '[protocol_handlers.1.url] must be a URL inside the scope [/app/] containing %s.',
    ]);
});

it('flags an unknown launch handler mode', function (): void {
    expect(capabilities(['launch_handler' => ['client_mode' => 'reuse']]))->toHaveCount(1);
});

it('reports capability problems as warnings of validate()', function (): void {
    $result = (new ManifestValidator)->validate(['name' => 'A', 'start_url' => '/', 'display' => 'standalone', 'shortcuts' => [['url' => '/x']]]);

    expect($result['warnings'])->toContain('[shortcuts.0] needs a name.');
});
