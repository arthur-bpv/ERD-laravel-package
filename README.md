# ERD4Dev development application

Laravel 12, Livewire 4, Alpine and WireFlow application for drawing ER diagrams, converting them to relational models, importing SQL/database schemas, and exporting SQL or Laravel Blueprint drafts.

The distributable package is in [`packages/laravel-erd`](packages/laravel-erd/README.md). The root application consumes `makermind/erd4dev` through a Composer path repository and preserves the development database and routes.

## Run

```bash
docker compose up
```

Open `http://localhost:8000/erd`. The PHP container installs dependencies and runs migrations on its first start; the Vite container supplies development assets. Source changes are mounted into both containers.

For access from another machine, set `DEV_HOST` in `.env` to the Docker server IP/hostname, without a protocol or port:

```dotenv
DEV_HOST=192.168.88.245
```

```bash
docker compose up -d --force-recreate vite
```

Ports 8000 and 5173 must be reachable by the browser. Inspect `docker compose logs app` and `docker compose logs vite` when troubleshooting.

Without Docker, use PHP 8.3+, Composer and Node 22. Install dependencies, configure `.env`, run `php artisan migrate`, then `composer run dev`.

## Pages and workflows

| Route | Purpose |
| --- | --- |
| `/erd` | Project dashboard: create, import, rename and open diagrams. |
| `/erd/schema` | ER editor demonstration. |
| `/erd/boards/er/{diagram}` | Saved ER diagram. |
| `/erd/boards/relational/{diagram}` | Linked or independently imported relational model. |
| `/health`, `/up` | Application health checks. |

ER projects can generate linked relational models. Structural editing remains in the ER source; regeneration is an explicit action that can replace manual relational adjustments. Drift detection reports changes without automatically overwriting a saved model.

SQL and database imports create independent relational boards without an ER model. SQL is parsed as text, never executed. Connection import reads metadata only from connections configured by the user in Laravel. Board edits do not modify source tables or records; diagram records themselves are saved in the application's configured diagram table.

On imported boards, create tables with **New table**, edit table/column names and keys directly on the canvas, and configure foreign keys through **Relationships**. Cardinality follows uniqueness and nullability. Composite and recursive references are supported by the board.

**More** contains SQL, image, JSON and Blueprint exports. JSON copying works over HTTP through a clipboard fallback and offers manual selection if the browser blocks it.

## SQL import limits

Import a schema-only `.sql` file up to 2 MB or paste SQL. Dialect detection recognizes SQLite, MySQL/MariaDB, PostgreSQL, Oracle and SQL Server. Generic SQL may require selecting a dialect.

Tables, columns, types, sizes, nullability, primary/unique keys and foreign keys are represented. Data, views, routines, defaults, checks, automatic generation, collations and ordinary index options are outside the SQL diagram representation. Warnings explain omissions. Expression keys, multiple references on a single column and unsupported ALTER TABLE operations are rejected. Remove unsupported routine bodies and DELIMITER commands before import.

## Blueprint drafts

**More → Generate Blueprint draft** saves a new versionable YAML file under the consumer project's `blueprints/` directory on every click. Existing files are not overwritten. The result dialog includes review warnings and a command to generate models/migrations with the included Blueprint package. The editor does not run that command or execute migrations automatically.

Database configuration, review and execution remain the user's responsibility. See the [package documentation](packages/laravel-erd/README.md#blueprint-drafts) for naming, migration and representation limits.

## Code map

| Responsibility | Location |
| --- | --- |
| ER editor | `packages/laravel-erd/src/Livewire/SchemaBoard.php` and its Blade view. |
| Relational editor | `packages/laravel-erd/src/Livewire/RelationalBoard.php`, `Services/RelationalStructureEditor.php`. |
| ER conversion | `packages/laravel-erd/src/Services/ErToRelationalTransformer.php`. |
| SQL/database import | `packages/laravel-erd/src/Support/SqlDiagramImporter.php`, `DatabaseDiagramImporter.php`. |
| SQL/Blueprint export | `packages/laravel-erd/src/Services/RelationalSqlGenerator.php`, `BlueprintDraftGenerator.php`, `BlueprintDraftWriter.php`. |
| Canvas interactions | `packages/laravel-erd/resources/js/erd/`. |
| Shared UI | `packages/laravel-erd/resources/views/components/`. |

Livewire owns diagram state. WireFlow receives incremental canvas updates. Public ER examples contain only features configurable in the current interface. Legacy file readers retain compatibility; user-authored names and saved data are not translated automatically.

## Verify and build

```bash
docker compose exec -T app php artisan test --compact
docker compose exec -T app vendor/bin/phpunit -c packages/laravel-erd/phpunit.xml.dist
docker compose exec -T vite npm test
docker compose exec -T vite npm run build
docker compose exec -T vite npm run build:package
```

The package's compiled `dist/` files are included in distribution. Consumers do not require Vite or Node. Run `php artisan vendor:publish --tag=erd-assets --force` in the development app when refreshing its published assets.

The root demonstration uses the historical `diagrams` table. Fresh package installations use `erd_diagrams`; migrating historical data requires a separate plan.
