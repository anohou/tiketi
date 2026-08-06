<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression tests for POST /locale.
 *
 * Production hit a 500 here for logged-in platform admins: the central
 * (landlord) users table had no `settings` column, and LocaleController
 * used to persist the choice with $user->save() unconditionally. The
 * column was added via a landlord migration and the controller now
 * guards the write, so the endpoint must never 500 on either DB.
 */
class LocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_central_super_admin_can_switch_locale_without_error(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);

        $response = $this->actingAs($superAdmin)->post(route('locale.update'), ['locale' => 'en']);

        $response->assertRedirect();
        $this->assertSame('en', session('locale'));
    }

    public function test_locale_switch_persists_choice_in_user_settings(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($superAdmin)->post(route('locale.update'), ['locale' => 'en']);

        $this->assertSame('en', $superAdmin->fresh()->settings['locale']);
    }

    public function test_anonymous_visitor_can_switch_locale_without_error(): void
    {
        $response = $this->post(route('locale.update'), ['locale' => 'fr']);

        $response->assertRedirect();
        $this->assertSame('fr', session('locale'));
    }

    public function test_locale_must_be_fr_or_en(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);

        $response = $this->actingAs($superAdmin)
            ->from('/')
            ->post(route('locale.update'), ['locale' => 'de']);

        $response->assertSessionHasErrors('locale');
    }
}
