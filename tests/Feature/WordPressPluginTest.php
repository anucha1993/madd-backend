<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

class WordPressPluginTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_lists_and_downloads_plugins_as_installable_zips(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->sync(Role::where('key', 'admin')->pluck('id'));
        Sanctum::actingAs($admin);

        $this->getJson('/api/wordpress-plugins')->assertOk()
            ->assertJsonPath('0.slug', 'madd-tracking')
            ->assertJsonPath('0.name', 'MADD Tracking')
            ->assertJsonPath('0.version', '1.1.1');

        $res = $this->get('/api/wordpress-plugins/madd-tracking/download')->assertOk();
        $path = $res->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path));
        $this->assertNotFalse($zip->locateName('madd-tracking/madd-tracking.php'));
        $this->assertNotFalse($zip->locateName('madd-tracking/assets/madd-tracking.js'));
        $zip->close();

        $this->get('/api/wordpress-plugins/unknown/download')->assertNotFound();
    }

    public function test_download_needs_the_public_api_permission(): void
    {
        $staff = User::factory()->create();
        $staff->roles()->sync(Role::where('key', 'staff')->pluck('id'));
        Sanctum::actingAs($staff);

        $this->get('/api/wordpress-plugins/madd-tracking/download')->assertForbidden();
    }
}
