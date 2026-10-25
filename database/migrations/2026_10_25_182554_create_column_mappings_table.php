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
        Schema::create('column_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sheet_tab_id')->constrained('sheet_tabs')->cascadeOnDelete();
            $table->string('source_column');
            $table->string('destination_column');
            $table->string('destination_table'); // allows the same source column to map to different tables while preventing duplicate mappings to the same destination column.
            $table->string('destination_type')->default('string');
            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique([
                'sheet_tab_id',
                'source_column',
                'destination_table',
                'destination_column',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('column_mappings');
    }
};
