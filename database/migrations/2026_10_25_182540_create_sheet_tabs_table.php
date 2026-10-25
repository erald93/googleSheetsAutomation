<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sheet_tabs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sheet_source_id')->constrained('sheet_sources')->cascadeOnDelete();
            $table->string('tab_name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['sheet_source_id', 'tab_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sheet_tabs');
    }
};
