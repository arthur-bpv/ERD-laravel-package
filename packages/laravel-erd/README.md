# ERD4Dev

ER and relational diagram editor for Laravel, with JSON/SQL import, database schema snapshots, ER-to-relational conversion, SQL export and Laravel Blueprint drafts.

Requires PHP 8.3+, Laravel 12 and Livewire 4.3+. Composer package: `makermind/erd4dev`. PHP namespace: `Makermind\Erd4dev`.

Developed by Professor Giovani's study group. This is an independent project, with no institutional affiliation to IFRN. Distributed under the MIT license; see `LICENSE` and third-party notices in `licenses/`.

## Installation

The package is not published on Packagist yet. To install locally, add a Composer path repository pointing to this directory:

```json
{
    "repositories": [
        {"type": "path", "url": "/path/to/ERD-laravel-package/packages/laravel-erd"}
    ]
}
```

WireFlow currently requires explicit acceptance of its alpha release. The project does not need to lower its global minimum stability:

```bash
composer require --dev makermind/erd4dev:@dev getartisanflow/wireflow:^0.1.2@alpha
```

Open `/erd` at the address and port already used by your application (for example, `http://localhost:8000/erd`). The package is discovered by Laravel automatically. No additional server, restart, Vite, asset publication or installation command is needed for normal local use.

With `APP_ENV=local`, access does not require login and the package creates its diagram storage table on the first ERD request if it does not exist. It uses the application's configured database; that database must already exist and be reachable. No application migrations are run automatically. All package routes, including compiled assets, examples and panel updates, live under `/erd`.

`erd:install` remains an optional command to publish compiled assets and the package migration. Its `--migrate` flag runs **all pending application migrations**. Outside the local environment, publish and run the migration explicitly. Existing route caches must be regenerated after installing a package; persistent application workers need to load the new code as usual.

After publication, a stable ERD4Dev release will still require explicitly accepting the WireFlow alpha dependency until an upstream stable version is adopted.

## Access through the application server

Outside `APP_ENV=local`, the application must grant the `erd.access` Gate:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('erd.access', fn ($user) => $user->can('manage-diagrams'));
```

The permission in this example must be implemented by the host application. Authorization is checked again on Livewire updates. The package does not supply user registration or authentication.

```bash
php artisan vendor:publish --tag=erd-config
```

Configuration controls the domain, middleware and storage table. The `/erd` prefix is fixed to avoid occupying the application root or its other routes. Routes use `erd.` names and views use `erd::`. The default storage table is `erd_diagrams`. Diagrams form a shared workspace without per-user or tenant isolation.

## Modeling and importing

Start with an ER model and generate a linked relational model, or import SQL/database metadata directly into an independent editable relational board. Structural changes to linked models belong in the ER source.

SQL is parsed as text and never executed. Database import reads schema metadata from connections configured in `config/database.php`; passwords are not entered in the browser. Supported native drivers are SQLite, MySQL/MariaDB, PostgreSQL and SQL Server, with a 250-table limit. SQLite has been tested against a real database; the remaining drivers still require live-server validation. Oracle is supported through SQL import, not connection import.

Snapshots contain tables, columns, primary/unique keys and foreign keys. Data, defaults, checks, views, routines, ordinary indexes, collations and referential actions are outside the diagram representation. Unsupported structures are reported. Editing a snapshot changes only the saved diagram, never the source tables or records. Diagram records themselves use the host application's configured storage database.

On imported boards, **New table** creates a table with an id primary key. Rename tables and columns, add/remove columns, and set PK/UNIQUE keys directly on the canvas. Column types, sizes, enums and nullability are editable in their rows. Enum values retain embedded commas, spaces, empty strings and zero values.

**Relationships** links FK columns, including composite and recursive references. Cardinality follows uniqueness and nullability: a unique source key produces 1:1, otherwise N:1; nullable columns allow optional references. Changes that invalidate referenced keys are rejected.

The header places the database selector on the left, model tabs in the center and actions on the right. New table and Relationships are adjacent. Smaller screens keep a single toolbar row and expose secondary actions through **More**. SQL, Blueprint draft and image exports are in More on all screen sizes.

Public ER examples include only features configurable in the current interface. Readers retain compatibility with legacy diagrams and previously generated SQL headers. Existing user-authored names and saved diagram contents are not translated automatically.

## Blueprint drafts

Choose **More → Generate Blueprint draft**. Each click saves a new file in the consumer project's root:

```text
blueprints/draft-{diagram-id}-{YYYYMMDD}-{HHMMSSmicroseconds}.yaml
```

Exclusive file creation prevents overwrites; collisions receive a suffix. Commit the `blueprints/` directory to version control. Drafts contain the current saved board without credentials or database records.

The result appears in a dismissible dialog with collapsed warnings and a copyable command. Reopen it through **More → Last draft** without generating another file. After review:

```bash
php artisan blueprint:build blueprints/draft-42-20261103-123456123456.yaml --only=models,migrations
```

The Blueprint package is included. Generating a draft only writes YAML. It does not automatically generate PHP files or run migrations. Database configuration, review and migration execution remain the user's responsibility. Drafts describe full schemas, not migration diffs against existing tables.

Actual table names are preserved in `meta.table`. Missing id/timestamp columns are not implicitly added. Composite PK/UNIQUE keys are represented as indexes. Composite FKs and cross-schema references retain their columns but omit constraints that Blueprint cannot faithfully represent, with explicit warnings. Composite or nonstandard primary keys require reviewing the Eloquent model.

Reserved Blueprint names, incompatible identifiers, unsupported custom types and unrepresentable enums block draft generation with an explanation; partial drafts are not saved.

## Development

The Laravel application at the repository root consumes this directory through a Composer path repository. It retains its historical `diagrams` table, existing URLs and Vite development assets. Do not copy the demo configuration into a consumer application.

```bash
docker compose exec -T app php artisan test --compact
docker compose exec -T app vendor/bin/phpunit -c packages/laravel-erd/phpunit.xml.dist
docker compose exec -T vite npm test
docker compose exec -T vite npm run build:package
```

Build from the monorepository root using the installed PHP/Node dependencies. Include `dist/` in releases; consumers do not build frontend assets. License notices are in `licenses/`.

Before publishing, prepare a package-root repository or automated subtree split, verify installation in a fresh Laravel application, automate checks, and create a release tag and changelog. Existing demo data requires a separate migration plan rather than running the new-install migration over its historical table.
