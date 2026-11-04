<?php

namespace Tests\Feature;

use Blueprint\Blueprint;
use Blueprint\Builder;
use Blueprint\Generators\MigrationGenerator;
use Blueprint\Generators\ModelGenerator;
use Blueprint\Lexers\ModelLexer;
use Illuminate\Database\Schema\Blueprint as Table;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Makermind\Erd4dev\Livewire\ProjectDashboard;
use Makermind\Erd4dev\Livewire\RelationalBoard;
use Makermind\Erd4dev\Models\Diagram;
use Makermind\Erd4dev\Services\BlueprintDraftGenerator;
use Makermind\Erd4dev\Support\DatabaseDiagramImporter;
use Makermind\Erd4dev\Support\SqlDiagramImporter;
use Mockery;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class DatabaseImportAndBlueprintTest extends TestCase
{
    use RefreshDatabase;

    private array $drafts = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.source_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true,
        ]]);
        $schema = DB::connection('source_test')->getSchemaBuilder();
        $schema->create('authors', function (Table $table): void {
            $table->id();
            $table->string('name', 80)->unique();
        });
        $schema->create('books', function (Table $table): void {
            $table->id();
            $table->foreignId('author_id')->nullable()->constrained('authors');
            $table->decimal('price', 10, 2);
        });
        DB::connection('source_test')->table('authors')->insert(['id' => 1, 'name' => 'Original']);
        DB::connection('source_test')->enableQueryLog();
    }

    protected function tearDown(): void
    {
        foreach ($this->drafts as $path) {
            if (is_file(base_path($path))) {
                unlink(base_path($path));
            }
        }
        DB::purge('source_test');
        parent::tearDown();
    }

    public function test_database_import_creates_only_an_editable_relational_snapshot(): void
    {
        Livewire::test(ProjectDashboard::class)
            ->call('toggleImport', 'database')
            ->assertSee('Project connection')
            ->set('importProjectName', 'Snapshot')
            ->set('importConnection', 'source_test')
            ->call('importDatabaseProject')
            ->assertSet('importError', '');
        $diagram = Diagram::sole();
        $this->assertSame(Diagram::TYPE_RELATIONAL, $diagram->type);
        $this->assertNull($diagram->source_diagram_id);
        $this->assertSame('database', $diagram->data['origin']['type']);
        $this->assertCount(2, $diagram->data['tables']);
        $this->assertCount(1, $diagram->data['foreignKeys']);
        $table = collect($diagram->data['tables'])->firstWhere('name', 'authors');
        Livewire::test(RelationalBoard::class, ['diagram' => $diagram])
            ->assertSee('Relationships')
            ->call('renameTable', $table['id'], 'writers')
            ->call('renameColumn', $table['id'], $table['columns'][1]['id'], 'display_name')
            ->call('save');
        $queries = DB::connection('source_test')->getQueryLog();
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|create|alter|drop)\b/i', $query['query']);
            $this->assertDoesNotMatchRegularExpression('/select\s+\*\s+from\s+["`]?authors/i', $query['query']);
        }
        $this->assertSame('Original', DB::connection('source_test')->table('authors')->value('name'));
        $this->assertTrue(DB::connection('source_test')->getSchemaBuilder()->hasColumn('authors', 'name'));
        $this->assertSame('writers', $diagram->fresh()->data['tables'][0]['name']);
    }

    public function test_invalid_connections_do_not_create_projects_or_expose_credentials(): void
    {
        Livewire::test(ProjectDashboard::class)->set('importProjectName', 'Invalid')
            ->set('importConnection', 'not_configured')->call('importDatabaseProject')
            ->assertSet('importError', 'Select a connection configured in Laravel.');
        $this->assertSame(0, Diagram::count());
    }

    public function test_connection_errors_do_not_expose_configuration_to_the_browser(): void
    {
        config(['database.connections.missing_source' => [
            'driver' => 'sqlite', 'database' => '/private/secret-source.sqlite',
        ]]);
        Livewire::test(ProjectDashboard::class)->set('importProjectName', 'Missing')
            ->set('importConnection', 'missing_source')->call('importDatabaseProject')
            ->assertSee('Could not read the schema.')
            ->assertDontSee('/private/secret-source.sqlite');
        $this->assertSame(0, Diagram::count());
    }

    public function test_draft_is_accepted_by_blueprint_and_generates_models_and_migrations(): void
    {
        $data = (new DatabaseDiagramImporter)->import('source_test');
        $draft = (new BlueprintDraftGenerator)->generate($data);
        $parsed = Yaml::parse($draft['yaml']);
        $this->assertSame('authors', $parsed['models']['Author']['meta']['table']);
        $this->assertFalse($parsed['models']['Author']['timestamps']);
        $this->assertStringContainsString('decimal:10,2', $parsed['models']['Book']['price']);
        $files = Mockery::mock(Filesystem::class)->makePartial();
        $written = [];
        $files->shouldReceive('put')->andReturnUsing(function ($path, $content) use (&$written): int {
            $written[$path] = $content;

            return strlen($content);
        });
        $files->shouldReceive('makeDirectory')->andReturn(true);
        $blueprint = new Blueprint;
        $blueprint->registerLexer(new ModelLexer);
        $blueprint->registerGenerator(new MigrationGenerator($files));
        $blueprint->registerGenerator(new ModelGenerator($files));
        $files->shouldReceive('get')->with('test-draft.yaml')->andReturn($draft['yaml']);
        $files->shouldReceive('exists')->with('.blueprint')->andReturn(false);
        (new Builder)->execute($blueprint, $files, 'test-draft.yaml', 'models,migrations');
        unset($written['.blueprint']);
        $this->assertCount(4, $written);
        $all = implode("\n", $written);
        $this->assertStringContainsString('class Author extends Model', $all);
        $this->assertStringContainsString("Schema::create('books'", $all);
        $this->assertStringContainsString("->references('id')->on('authors')", $all);
        $this->assertStringNotContainsString('$table->timestamps(', $all);
        foreach ($written as $path => $code) {
            $this->assertStringContainsString('<?php', $code);
            token_get_all($code, TOKEN_PARSE);
        }
        $default = DB::getDefaultConnection();
        config(['database.connections.generated_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true,
        ]]);
        try {
            DB::setDefaultConnection('generated_test');
            foreach ($written as $path => $code) {
                if (str_starts_with($path, 'database/migrations/')) {
                    // Execute only migration code generated from the fixed test fixture.
                    $migration = eval(substr($code, 5));
                    $migration->up();
                }
            }
            $schema = DB::getSchemaBuilder();
            $this->assertTrue($schema->hasColumn('books', 'price'));
            $this->assertCount(1, $schema->getForeignKeys('books'));
            $this->assertCount(2, $schema->getColumns('authors'));
        } finally {
            DB::setDefaultConnection($default);
            DB::purge('generated_test');
        }
    }

    public function test_each_click_saves_a_new_draft_of_the_edited_board_without_running_migrations(): void
    {
        $data = (new SqlDiagramImporter)->parse('CREATE TABLE books (id INT PRIMARY KEY, title VARCHAR(80));', 'sqlite')['relational'];
        $diagram = Diagram::create(['name' => 'SQL', 'type' => Diagram::TYPE_RELATIONAL, 'data' => $data]);
        $table = $data['tables'][0];
        $this->freezeTime();
        $board = Livewire::test(RelationalBoard::class, ['diagram' => $diagram])
            ->call('renameTable', $table['id'], 'publications')
            ->call('generateBlueprintDraft')->assertSet('draftError', null);
        $this->drafts[] = $first = $board->get('draftPath');
        $board->call('generateBlueprintDraft')->assertSet('draftError', null);
        $this->drafts[] = $second = $board->get('draftPath');
        $this->assertNotSame($first, $second);
        $this->assertMatchesRegularExpression('~^blueprints/draft-'.$diagram->id.'-\d{8}-\d{12}(?:-\d+)?\.yaml$~', $first);
        $this->assertFileExists(base_path($first));
        $this->assertFileExists(base_path($second));
        $this->assertStringContainsString('publications', file_get_contents(base_path($first)));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('publications'));
    }

    public function test_composite_foreign_keys_are_explicitly_reported_in_the_draft(): void
    {
        $data = (new SqlDiagramImporter)->parse('CREATE TABLE parents (tenant INT, id INT, PRIMARY KEY(tenant,id)); CREATE TABLE children (tenant INT, parent INT, FOREIGN KEY(tenant,parent) REFERENCES parents(tenant,id));', 'sqlite')['relational'];
        $draft = (new BlueprintDraftGenerator)->generate($data);
        $this->assertStringContainsString('Composite FK', $draft['yaml']);
        $this->assertStringContainsString('Composite PK', $draft['yaml']);
        $this->assertStringNotContainsString('foreign:parents', $draft['yaml']);
        $this->assertArrayHasKey('parent', Yaml::parse($draft['yaml'])['models']['Child']);
    }

    public function test_draft_preserves_enum_values_and_rejects_unsupported_types_without_writing(): void
    {
        $data = (new SqlDiagramImporter)->parse("CREATE TABLE jobs (id INT PRIMARY KEY, state ENUM('in progress','done'));", 'mysql')['relational'];
        $draft = (new BlueprintDraftGenerator)->generate($data);
        $blueprint = app(Blueprint::class);
        $tree = $blueprint->analyze($blueprint->parse($draft['yaml'], false));
        $this->assertSame(['in progress', 'done'], $tree->models()['Job']->columns()['state']->attributes());
        $diagram = Diagram::create(['name' => 'Unsupported', 'type' => Diagram::TYPE_RELATIONAL, 'data' => (new SqlDiagramImporter)->parse('CREATE TABLE special (id INT PRIMARY KEY, location GEOGRAPHY);', 'pgsql')['relational'],
        ]);
        Livewire::test(RelationalBoard::class, ['diagram' => $diagram])
            ->call('generateBlueprintDraft')->assertSet('draftPath', null)
            ->assertSee('unsupported in the draft');
    }
}
