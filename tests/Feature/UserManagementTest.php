<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    /** @test */
    public function it_lists_users(): void
    {
        $admin = User::factory()->create();
        User::factory()->count(3)->create();

        $this->actingAs($admin, 'sanctum')
            ->get('/users')
            ->assertStatus(200);
    }

    /** @test */
    public function it_creates_a_user(): void
    {
        $admin = User::factory()->create();

        $response = $this->actingAs($admin, 'sanctum')
            ->post('/users', [
                'name' => 'New Operator',
                'email' => 'operator@example.com',
                'password' => 'secure-password',
            ]);

        $response->assertRedirect('/users');
        $this->assertDatabaseHas('users', ['email' => 'operator@example.com']);
    }

    /** @test */
    public function it_updates_a_user_password(): void
    {
        $admin = User::factory()->create();
        $user = User::factory()->create(['email' => 'target@example.com']);

        $response = $this->actingAs($admin, 'sanctum')
            ->put("/users/{$user->id}", [
                'name' => 'Updated Name',
                'email' => 'target@example.com',
                'password' => 'new-password',
            ]);

        $response->assertRedirect('/users');
        $user->refresh();
        $this->assertSame('Updated Name', $user->name);
        $this->assertTrue(Hash::check('new-password', $user->password));
    }

    /** @test */
    public function it_prevents_deleting_the_last_user(): void
    {
        $admin = User::factory()->create();

        $response = $this->actingAs($admin, 'sanctum')
            ->delete("/users/{$admin->id}");

        $response->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    /** @test */
    public function guests_cannot_manage_users(): void
    {
        // Web auth middleware redirects guests to the login page.
        $this->get('/users')->assertStatus(302);
        $this->post('/users', [])->assertStatus(302);
    }
}
