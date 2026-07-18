<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Twin of PhpUnit/FeatureLoginExample.php — same hand-computed metrics.
it('lets a user log in', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'secret',
    ])->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($user);
});
