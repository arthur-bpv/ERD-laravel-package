<?php

namespace Tests\Unit\Support;

use App\Support\DataTypeCatalog;
use PHPUnit\Framework\TestCase;

class DataTypeCatalogTest extends TestCase
{
    public function test_it_resolves_unsupported_types_to_the_closest_available_type(): void
    {
        $this->assertSame('smallint', DataTypeCatalog::resolve('oracle', 'tinyint'));
        $this->assertSame('char', DataTypeCatalog::resolve('pgsql', 'nchar'));
        $this->assertSame('text', DataTypeCatalog::resolve('oracle', 'longtext'));
    }

    public function test_it_normalizes_length_and_decimal_metadata_to_dialect_limits(): void
    {
        $varchar = DataTypeCatalog::normalize('oracle', [
            'type' => 'varchar',
            'length' => 8000,
            'precision' => 10,
            'scale' => 2,
        ]);
        $decimal = DataTypeCatalog::normalize('sqlsrv', [
            'type' => 'decimal',
            'precision' => 90,
            'scale' => 50,
            'length' => 255,
        ]);

        $this->assertSame(4000, $varchar['length']);
        $this->assertNull($varchar['precision']);
        $this->assertNull($varchar['scale']);
        $this->assertNull($decimal['length']);
        $this->assertSame(38, $decimal['precision']);
        $this->assertSame(30, $decimal['scale']);
    }

    public function test_javascript_catalog_contains_labels_types_limits_and_kinds_for_each_dialect(): void
    {
        $catalog = DataTypeCatalog::forJs();

        $this->assertSame(['mysql', 'pgsql', 'oracle', 'sqlsrv'], array_keys($catalog));
        $this->assertSame('PostgreSQL', $catalog['pgsql']['label']);
        $this->assertSame('UUID', $catalog['pgsql']['types']['uuid']);
        $this->assertSame(4000, $catalog['oracle']['limits']['varchar']);
        $this->assertSame('length', $catalog['mysql']['kinds']['varchar']);
        $this->assertSame('decimal', $catalog['sqlsrv']['kinds']['numeric']);
    }
}
