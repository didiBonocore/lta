<?php

declare(strict_types=1);

use App\Analysis\Versioning\LaravelMajorResolver;

it('resolves constraint shapes to integer majors (minimum major on ranges and unions)', function (?string $constraint, ?int $expected) {
    expect(new LaravelMajorResolver()->resolve($constraint))->toBe($expected);
})->with([
    'caret' => ['^11.0', 11],
    'caret minor' => ['^13.17', 13],
    'tilde' => ['~5.8', 5],
    'tilde patch' => ['~5.8.0', 5],
    'wildcard' => ['5.8.*', 5],
    'legacy wildcard' => ['4.2.*', 4],
    'range takes the minimum major' => ['>=9 <11', 9],
    'range, bounds reversed in the text' => ['<11 >=9', 9],
    'multi-constraint takes the minimum major' => ['^10|^11', 10],
    'double pipe union' => ['^8.0 || ^9.0', 8],
    'v prefix' => ['v10.0.1', 10],
    'x-dev branch' => ['10.x-dev', 10],
    'dev branch is unparseable' => ['dev-master', null],
    'pure wildcard is unparseable' => ['*', null],
    'empty' => ['', null],
    'null' => [null, null],
]);

it('prefers laravel/framework and falls back to illuminate/support', function () {
    $resolver = new LaravelMajorResolver;

    expect($resolver->resolveFromComposer([
        'require' => ['laravel/framework' => '^12.0', 'illuminate/support' => '^11.0'],
    ]))->toBe(12)
        ->and($resolver->resolveFromComposer([
            'require' => ['illuminate/support' => '~5.4'],
        ]))->toBe(5)
        ->and($resolver->resolveFromComposer(['require' => ['symfony/console' => '^6.0']]))->toBeNull()
        ->and($resolver->resolveFromComposer([]))->toBeNull();
});
