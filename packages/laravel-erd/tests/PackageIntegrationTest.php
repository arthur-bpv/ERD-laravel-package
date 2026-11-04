<?php

namespace Makermind\Erd4dev\Tests;

use ArtisanFlow\WireFlow\WireFlowServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use Makermind\Erd4dev\ErdServiceProvider;
use Makermind\Erd4dev\Livewire\ProjectDashboard;
use Makermind\Erd4dev\Models\Diagram;
use Orchestra\Testbench\TestCase;

class PackageIntegrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LivewireServiceProvider::class, WireFlowServiceProvider::class, ErdServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true,
        ]);
        $app['config']->set('session.driver', 'array');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    }

    protected function setUp(): void
    {
        parent::setUp();
        (require __DIR__.'/../database/migrations/2026_01_01_000000_create_erd_diagrams_table.php')->up();
    }

    public function test_access_is_denied_until_host_grants_permission(): void
    {
        $this->get('/erd')->assertForbidden();
        $this->get('/erd/schema')->assertForbidden();
    }

    public function test_panel_uses_package_views_and_precompiled_assets(): void
    {
        Gate::define('erd.access', fn ($user = null) => true);

        $this->get('/erd')->assertOk()
            ->assertSee('/erd/assets/erd.css', false)
            ->assertSee('/erd/assets/erd.js', false)
            ->assertDontSee('@vite', false);
        $this->get('/erd/schema')->assertOk()->assertSee('flow-container');
        $this->assertTrue(config('livewire.inject_assets'));
        $this->assertTrue(Schema::hasTable('erd_diagrams'));
        $this->assertFalse(Schema::hasTable('diagrams'));
    }

    public function test_create_and_open_diagram_without_demo_application(): void
    {
        Gate::define('erd.access', fn ($user = null) => true);

        Livewire::test(ProjectDashboard::class)
            ->set('projectName', 'Package project')
            ->call('createProject')
            ->assertHasNoErrors();

        $diagram = Diagram::sole();
        $this->assertSame('Package project', $diagram->name);
        $this->get(route('erd.boards.er', $diagram))->assertOk();
    }

    public function test_relational_link_and_cascade_use_package_table(): void
    {
        $source = Diagram::create(['name' => 'ER', 'type' => 1, 'data' => []]);
        $relational = Diagram::create([
            'name' => 'Relational', 'type' => 2, 'data' => [], 'source_diagram_id' => $source->id,
        ]);
        $this->assertTrue($source->relationalDiagram->is($relational));
        $source->delete();
        $this->assertSame(0, Diagram::count());
    }

    public function test_livewire_updates_recheck_access_after_permission_is_revoked(): void
    {
        $allowed = true;
        Gate::define('erd.access', function ($user = null) use (&$allowed): bool {
            return $allowed;
        });
        $response = $this->get('/erd')->assertOk();
        preg_match('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
        $this->assertNotEmpty($matches);
        $allowed = false;

        $this->postJson('/erd/update', [
            '_token' => csrf_token(),
            'components' => [[
                'snapshot' => html_entity_decode($matches[1], ENT_QUOTES),
                'updates' => ['projectName' => 'Forbidden project'],
                'calls' => [['method' => 'createProject', 'params' => [], 'path' => '']],
            ]],
        ], ['X-Livewire' => 'true'])->assertForbidden();
        $this->assertSame(0, Diagram::count());
    }

    public function test_local_environment_can_create_a_project_without_login(): void
    {
        $this->app['env'] = 'local';
        Schema::dropIfExists('erd_diagrams');
        $response = $this->get('/erd')->assertOk();
        preg_match('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
        $this->postJson('/erd/update', [
            '_token' => csrf_token(),
            'components' => [[
                'snapshot' => html_entity_decode($matches[1], ENT_QUOTES),
                'updates' => ['projectName' => 'Local project'],
                'calls' => [['method' => 'createProject', 'params' => [], 'path' => '']],
            ]],
        ], ['X-Livewire' => 'true'])->assertOk();
        $this->assertSame('Local project', Diagram::sole()->name);
        (require __DIR__.'/../database/migrations/2026_01_01_000000_create_erd_diagrams_table.php')->up();
        $this->get('/erd')->assertOk();
        $this->assertSame('Local project', Diagram::sole()->name);
    }

    public function test_assets_are_available_without_publishing_and_paths_are_restricted(): void
    {
        $this->app['env'] = 'local';
        foreach (['erd.js', 'erd.css', 'livewire.js', 'examples/clinic-er.json'] as $file) {
            $this->get('/erd/assets/'.$file)->assertOk();
        }
        $this->get('/erd/assets/composer.json')->assertNotFound();
        $this->get('/erd/assets/../composer.json')->assertNotFound();
    }

    public function test_host_routes_and_livewire_endpoint_are_preserved(): void
    {
        $this->app['env'] = 'local';
        Route::get('/', fn () => 'Host home');
        Route::get('/schema', fn () => 'Host schema');
        $uri = Livewire::getUpdateUri();
        $response = $this->get('/erd')->assertOk()->assertSee('/erd/update', false);
        preg_match('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
        $this->get('/')->assertSee('Host home');
        $this->get('/schema')->assertSee('Host schema');
        $this->assertSame($uri, Livewire::getUpdateUri());
        $this->postJson($uri, ['_token' => csrf_token(), 'components' => [[
            'snapshot' => html_entity_decode($matches[1], ENT_QUOTES), 'updates' => [], 'calls' => [],
        ]]], ['X-Livewire' => 'true'])->assertOk();
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->getName() ?? '', 'erd.')) {
                $this->assertStringStartsWith('erd', $route->uri());
            }
        }
    }

    public function test_installer_publishes_assets_and_an_idempotent_runnable_migration(): void
    {
        $migration = database_path('migrations/2026_01_01_000000_create_erd_diagrams_table.php');
        $this->assertFileDoesNotExist($migration);
        $assets = public_path('vendor/erd');
        $this->assertDirectoryDoesNotExist($assets);
        try {
            $this->artisan('erd:install')->assertSuccessful();
            $this->artisan('erd:install')->assertSuccessful();
            $this->assertFileExists($migration);
            $this->assertFileExists($assets.'/erd.js');
            $this->assertFileExists($assets.'/erd.css');
            $this->assertFileExists($assets.'/examples/er-conversion-cases.json');
            Schema::dropIfExists('erd_diagrams');
            $this->artisan('migrate', ['--path' => $migration, '--realpath' => true])
                ->assertSuccessful();
            $this->assertTrue(Schema::hasTable('erd_diagrams'));
        } finally {
            if (is_file($migration)) {
                unlink($migration);
            }
            (new Filesystem)->deleteDirectory($assets);
        }
    }

    public function test_package_views_can_be_cached(): void
    {
        $this->artisan('view:cache')->assertSuccessful();
        $this->artisan('view:clear')->assertSuccessful();
    }
}
