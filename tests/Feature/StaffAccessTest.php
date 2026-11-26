<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StaffAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_a_catalogue_only_staff_account(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'status' => 'active']);

        $this->actingAs($owner)->postJson('/admin/api/users', [
            'name' => 'Catalogue Manager',
            'email' => 'catalogue@example.com',
            'role' => 'catalog_manager',
            'password' => 'StrongPassword123',
            'password_confirmation' => 'StrongPassword123',
        ])->assertCreated()
            ->assertJsonPath('data.role', 'catalog_manager')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.editable', true);

        $manager = User::query()->where('email', 'catalogue@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('StrongPassword123', $manager->password));
        $this->assertNotNull($manager->email_verified_at);
        $this->assertTrue($manager->isStaff());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'staff.created', 'user_id' => $owner->id, 'auditable_id' => $manager->id,
        ]);
    }

    public function test_catalogue_manager_can_only_reach_catalogue_and_asset_apis(): void
    {
        $manager = User::factory()->create(['role' => 'catalog_manager', 'status' => 'active']);

        $this->actingAs($manager)->get('/admin')->assertOk();
        $this->actingAs($manager)->getJson('/admin/api/catalog')->assertOk();
        $this->actingAs($manager)->getJson('/admin/api/catalog/options')->assertOk();

        $this->actingAs($manager)->getJson('/admin/api/dashboard')->assertForbidden();
        $this->actingAs($manager)->postJson('/admin/api/imports/preview')->assertForbidden();
        $this->actingAs($manager)->getJson('/admin/api/orders')->assertForbidden();
        $this->actingAs($manager)->getJson('/admin/api/integrations')->assertForbidden();
        $this->actingAs($manager)->getJson('/admin/api/commerce-settings')->assertForbidden();
        $this->actingAs($manager)->getJson('/admin/api/contact-settings')->assertForbidden();
        $this->actingAs($manager)->getJson('/admin/api/users')->assertForbidden();
    }

    public function test_non_owners_cannot_manage_staff_and_owner_accounts_are_protected(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'status' => 'active']);
        $editor = User::factory()->create(['role' => 'editor', 'status' => 'active']);
        $manager = User::factory()->create(['role' => 'catalog_manager', 'status' => 'active']);

        $this->actingAs($editor)->getJson('/admin/api/users')->assertForbidden();
        $this->actingAs($manager)->postJson('/admin/api/users', [])->assertForbidden();
        $this->actingAs($owner)->patchJson("/admin/api/users/{$owner->id}", [
            'role' => 'fulfillment', 'status' => 'suspended',
        ])->assertUnprocessable();

        $this->assertSame('owner', $owner->fresh()->role);
        $this->assertSame('active', $owner->fresh()->status);
    }

    public function test_owner_can_suspend_catalogue_manager_access(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'status' => 'active']);
        $manager = User::factory()->create(['role' => 'catalog_manager', 'status' => 'active']);

        $this->actingAs($owner)->patchJson("/admin/api/users/{$manager->id}", [
            'role' => 'catalog_manager', 'status' => 'suspended',
        ])->assertOk()->assertJsonPath('data.status', 'suspended');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'staff.updated', 'user_id' => $owner->id, 'auditable_id' => $manager->id,
        ]);
        $this->actingAs($manager->refresh())->getJson('/admin/api/catalog')->assertForbidden();
    }
}
