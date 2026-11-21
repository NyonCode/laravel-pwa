<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

function packagePath(string $path = ''): string
{
    return dirname(__DIR__, 2).($path !== '' ? '/'.$path : '');
}

function nodeBinary(): ?string
{
    return (new ExecutableFinder)->find('node');
}

/**
 * @param  list<array<string, mixed>>  $requests
 * @return list<array<string, mixed>>
 */
function mcp(array $requests): array
{
    $input = implode("\n", array_map(fn (array $r): string|false => json_encode(['jsonrpc' => '2.0', ...$r]), $requests))."\n";
    $process = new Process([nodeBinary(), packagePath('ai/mcp/server.mjs')], input: $input, timeout: 20);
    $process->mustRun();

    return array_map(
        fn (string $line): mixed => json_decode($line, true),
        array_values(array_filter(explode("\n", $process->getOutput()))),
    );
}

it('passes the MCP self-test', function (): void {
    $process = new Process([nodeBinary(), packagePath('ai/mcp/server.mjs'), '--self-test'], timeout: 20);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput())
        ->and($process->getOutput())->toContain('Self-test passed');
})->skip(fn (): bool => nodeBinary() === null, 'node is not installed');

it('speaks MCP over stdio', function (): void {
    [$init, $list, $config, $commands, $api] = mcp([
        ['id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']],
        ['id' => 2, 'method' => 'tools/list'],
        ['id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'describe_config', 'arguments' => ['key' => 'display.strategy']]],
        ['id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'list_commands', 'arguments' => []]],
        ['id' => 5, 'method' => 'tools/call', 'params' => ['name' => 'describe_api', 'arguments' => ['method' => 'cspHashes']]],
    ]);

    expect($init['result']['serverInfo']['name'])->toBe('laravel-pwa')
        ->and(array_column($list['result']['tools'], 'name'))->toBe([
            'search_docs', 'list_docs', 'get_doc', 'list_api', 'describe_api',
            'describe_config', 'list_commands', 'describe_client',
        ])
        ->and($config['result']['content'][0]['text'])->toContain("pwa.display.strategy` = `env('PWA_DISPLAY_STRATEGY', 'override')`")
        ->and($commands['result']['content'][0]['text'])->toContain('php artisan pwa:build')->toContain('pwa:doctor')
        ->and($api['result']['content'][0]['text'])->toContain('Pwa::cspHashes(string $view');
})->skip(fn (): bool => nodeBinary() === null, 'node is not installed');

it('lists every documentation page in every language, with front matter', function (): void {
    $nav = json_decode(File::get(packagePath('docs/nav.json')), true);
    $pages = collect($nav['sections'])->flatMap(fn (array $section) => $section['pages']);

    expect($pages)->not->toBeEmpty()
        ->and(array_keys($nav['languages']))->toBe(['en', 'cs'])
        ->and($nav['default'])->toBe('en');

    foreach (array_keys($nav['languages']) as $language) {
        foreach ($pages as $page) {
            $file = packagePath("docs/{$language}/{$page}");
            expect(File::exists($file))->toBeTrue("docs/{$language}/{$page} is listed in nav.json but missing");

            expect(File::get($file))->toMatch('/^---\n.*title: .+\n.*description: .+\n.*---/sU', "docs/{$language}/{$page} needs title and description");
        }

        $onDisk = collect(File::files(packagePath("docs/{$language}")))
            ->map(fn ($file) => $file->getFilename())
            ->filter(fn (string $name): bool => str_ends_with($name, '.md'));

        expect($onDisk->diff($pages)->values()->all())->toBe([], "every docs/{$language}/*.md must be listed in nav.json");
    }
});

it('passes the documentation check (parity, links, anchors)', function (): void {
    $process = new Process([nodeBinary(), packagePath('scripts/docs-check.mjs')], timeout: 20);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
})->skip(fn (): bool => nodeBinary() === null, 'node is not installed');

it('keeps the agent guide in step with the code', function (): void {
    $guide = File::get(packagePath('ai/AGENTS.md'));

    foreach (File::files(packagePath('src/Console')) as $file) {
        preg_match("/protected \\\$signature = '([\\w:]+)/", File::get($file->getPathname()), $m);
        expect($guide)->toContain($m[1]);
    }

    foreach (array_keys(require packagePath('config/pwa.php')) as $key) {
        expect($guide)->toContain($key);
    }

    foreach (['displayMode', 'isInstalled', 'isIos', 'install', 'update', 'keepAlive', 'warm', 'version', 'badge.set'] as $member) {
        expect($guide)->toContain($member);
    }

    preg_match_all("/emit\\('([\\w-]+)'/", File::get(packagePath('resources/js/pwa.js')), $events);
    foreach (array_unique($events[1]) as $event) {
        expect($guide)->toContain("`{$event}`");
    }

    foreach (['pwa.remember', 'pwa.cache', 'sw.import', 'X-PWA-Cache', 'X-PWA-Logout'] as $concept) {
        expect($guide)->toContain($concept);
    }
});

it('installs and removes the agent support in an application', function (): void {
    $app = sys_get_temp_dir().'/pwa-ai-'.uniqid();
    File::ensureDirectoryExists($app.'/vendor/nyoncode');
    File::put($app.'/composer.json', '{"name":"acme/app"}');
    File::put($app.'/AGENTS.md', "# AGENTS.md\n\nOwn rules.\n");
    symlink(packagePath(), $app.'/vendor/nyoncode/laravel-pwa');

    $run = function (string ...$args) use ($app): Process {
        $process = new Process([PHP_BINARY, packagePath('bin/pwa-ai'), ...$args, '--project='.$app], env: ['NO_COLOR' => '1']);
        $process->run();

        return $process;
    };

    expect($run('install')->getExitCode())->toBe(0);
    expect(File::get($app.'/AGENTS.md'))
        ->toContain('Own rules.')
        ->toContain('<!-- laravel-pwa:start -->')
        ->toContain('vendor/nyoncode/laravel-pwa/ai/AGENTS.md')
        ->and(File::exists($app.'/.claude/skills/laravel-pwa/SKILL.md'))->toBeTrue()
        ->and(json_decode(File::get($app.'/.mcp.json'), true)['mcpServers']['laravel-pwa']['args'])
        ->toBe(['vendor/nyoncode/laravel-pwa/ai/mcp/server.mjs']);

    expect($run('install')->getOutput())->toContain('Already up to date');
    expect($run('status')->getExitCode())->toBe(0);

    expect($run('remove')->getExitCode())->toBe(0);
    expect(File::get($app.'/AGENTS.md'))->toBe("# AGENTS.md\n\nOwn rules.\n")
        ->and(File::exists($app.'/.claude/skills/laravel-pwa/SKILL.md'))->toBeFalse()
        ->and(json_decode(File::get($app.'/.mcp.json'), true)['mcpServers'])->toBe([]);

    File::deleteDirectory($app);
});
