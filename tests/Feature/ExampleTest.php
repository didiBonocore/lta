<?php

declare(strict_types=1);

test('the single web route states the application is CLI-only', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertSee('CLI-only', escape: false)
        ->assertSee('php artisan list analyse', escape: false);
});
