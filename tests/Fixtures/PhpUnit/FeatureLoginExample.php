<?php

namespace Tests\Fixtures\PhpUnit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GOLD STANDARD — hand-computed:
 *   type = feature      (R1: $this->post is an HTTP call)
 *   assertions = 2      (assertRedirect, assertAuthenticatedAs)
 *   mocks = 0
 *   usesRefreshDatabase = true
 *   sizeStatements = 3
 */
class FeatureLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'secret',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }
}
