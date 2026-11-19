<?php

use Illuminate\Support\Facades\Vite;

test('vite does not output redundant css preload tags that trigger browser console warnings', function () {
    $tags = (string) Vite::useBuildDirectory('build')(['resources/css/app.css', 'resources/js/app.js']);

    expect($tags)
        ->toContain('rel="stylesheet"')
        ->toContain('rel="modulepreload"')
        ->toContain('type="module"')
        ->not->toContain('rel="preload" as="style"');
});

test('vite outputs modulepreload for javascript assets', function () {
    $tags = (string) Vite::useBuildDirectory('build')('resources/js/passkeys.js');

    expect($tags)
        ->toContain('rel="modulepreload"')
        ->toContain('type="module"');
});
