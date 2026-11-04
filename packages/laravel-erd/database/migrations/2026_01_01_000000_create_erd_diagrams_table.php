<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Local first access may have already initialized the package table.
        if (Schema::hasTable(config('erd.table'))) {
            return;
        }

        Schema::create(config('erd.table'), function (Blueprint $table): void {
            $table->id();
            $table->string('name')->default('Untitled diagram');
            $table->json('data');
            $table->unsignedTinyInteger('type')->default(1);
            $table->foreignId('source_diagram_id')->nullable()
                ->constrained(config('erd.table'))->cascadeOnDelete();
            $table->unique('source_diagram_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('erd.table'));
    }
};
