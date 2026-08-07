<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The help/aide page was renamed "Documentation" and consolidated into a
 * SINGLE public route (/documentation) that serves both the tenant and the
 * central domains. The old /help (web.php) and /aide (landlord.php) routes
 * were removed — they must 404 now.
 */
class DocumentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_documentation_route_renders_for_anonymous_visitor(): void
    {
        $response = $this->get('/documentation');

        $response->assertOk();
    }

    public function test_documentation_route_is_the_single_canonical_route(): void
    {
        $this->assertStringEndsWith('/documentation', route('documentation.index'));
        $this->assertFalse(app('router')->getRoutes()->hasNamedRoute('help.index'));
        $this->assertFalse(app('router')->getRoutes()->hasNamedRoute('public.help'));
    }

    public function test_old_help_route_returns_404(): void
    {
        $this->get('/help')->assertNotFound();
    }

    public function test_old_aide_route_returns_404(): void
    {
        $this->get('/aide')->assertNotFound();
    }
}
