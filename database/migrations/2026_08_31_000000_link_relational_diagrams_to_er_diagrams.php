<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('diagrams', function (Blueprint $table) {
            $table->foreignId('source_diagram_id')
                ->nullable()
                ->after('type')
                ->constrained('diagrams')
                ->cascadeOnDelete();

            $table->unique('source_diagram_id');
        });
    }

    public function down(): void
    {
        Schema::table('diagrams', function (Blueprint $table) {
            $table->dropUnique(['source_diagram_id']);
            $table->dropConstrainedForeignId('source_diagram_id');
        });
    }
};
