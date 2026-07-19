<?php

declare(strict_types=1);

use App\Analysis\Versioning\LaravelMajorResolver;

it('resolves real-world framework constraints to integer majors', function (?string $constraint, ?int $expected) {
    expect(new LaravelMajorResolver()->resolve($constraint))->toBe($expected);
})->with([
    'caret' => ['^13.0', 13],
    'caret minor' => ['^11.31', 11],
    'tilde patch' => ['~5.8.0', 5],
    'tilde' => ['~5.8', 5],
    'range lower bound wins' => ['>=5.5 <6.0', 5],
    'wildcard' => ['5.*', 5],
    'legacy wildcard' => ['4.2.*', 4],
    'v prefix' => ['v10.0.1', 10],
    'x-dev branch' => ['10.x-dev', 10],
    'or takes the highest major' => ['^9.0|^10.0', 10],
    'double pipe or' => ['^8.0 || ^9.0', 9],
    'dev branch is unresolvable' => ['dev-master', null],
    'pure wildcard is unresolvable' => ['*', null],
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
