<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackofficeConsoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_console_page_is_served_without_exposing_data_in_the_markup(): void
    {
        // Rendering must not depend on a build artifact: CI runs the suite before (or without)
        // npm build, so the page is asserted exactly as a runner would serve it.
        $this->withoutVite();

        $seller = User::factory()->create(['role' => User::ROLE_SELLER, 'email' => 'secret.seller@example.test']);
        Store::factory()->for($seller, 'owner')->create(['status' => Store::STATUS_APPROVED]);

        // The page is a shell: every record is fetched client-side behind the backoffice gate, so
        // no account or store data may be baked into the HTML.
        $response = $this->get('/backoffice')->assertOk();

        $response->assertDontSee('secret.seller@example.test', false);
        $response->assertSee('Konsol Operasional');
    }

    public function test_console_page_is_not_indexable(): void
    {
        $this->withoutVite();

        $this->get('/backoffice')
            ->assertOk()
            ->assertSee('name="robots" content="noindex, nofollow"', false);
    }

    public function test_console_bundle_is_registered_with_vite(): void
    {
        // The contract is that backoffice.js is a build entry point; assert it against the source
        // of truth (vite.config.js) instead of build output, which may not exist when tests run.
        $config = (string) file_get_contents(base_path('vite.config.js'));

        $this->assertStringContainsString("'resources/js/backoffice.js'", $config);
    }

    public function test_backoffice_capability_matrix_matches_the_console_expectations(): void
    {
        $expected = [
            User::ROLE_BUYER => false,
            User::ROLE_SELLER => false,
            User::ROLE_SUPPORT => true,
            User::ROLE_MODERATOR => true,
            User::ROLE_FINANCE => true,
            User::ROLE_ADMIN => true,
        ];

        foreach ($expected as $role => $allowed) {
            $user = User::factory()->create(['role' => $role]);
            // The console's own gate is a convenience, not the security boundary: the API is. The
            // two must agree, or the UI would offer actions the server rejects.
            $this->assertSame($allowed, $user->canAccessBackoffice(), "Role {$role} berbeda.");
        }
    }

    public function test_console_markup_carries_the_privacy_export_panel(): void
    {
        $this->withoutVite();

        // The panel lives in the shell (no data), while the JSON itself stays behind the audited,
        // step-up protected endpoint. Asserting the wiring exists keeps the feature discoverable
        // in the UI without loosening the server-side gate.
        $this->get('/backoffice')
            ->assertOk()
            ->assertSee('privacy-export', false)
            ->assertSee('Unduh JSON', false);
    }
}
