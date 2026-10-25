<?php

namespace Database\Seeders;

use App\Models\SheetSource;
use Illuminate\Database\Seeder;

class SheetConfigSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $spreadsheetId = config('services.google_sheets.seed_spreadsheet_id');

        if (empty($spreadsheetId)) {
            $this->command->warn('GOOGLE_SHEETS_SEED_ID is not set in .env, so nothing was seeded.');
            $this->command->line('Set it to your spreadsheet id, run "php artisan config:clear", then seed again.');

            return;
        }

        // The spreadsheet (file)
        $source = SheetSource::updateOrCreate(
            ['spreadsheet_id' => $spreadsheetId],
            ['name' => 'Sales spreadsheet', 'is_active' => true],
        );

        // The tab inside it
        $tab = $source->tabs()->updateOrCreate(
            ['tab_name' => config('services.google_sheets.seed_tab', 'Sheet1')],
            ['is_active' => true],
        );

        // Mappings: [sheet column, destination table, destination column, type]
        foreach ([
            ['date',         'sales', 'date',          'date'],
            ['product_name', 'sales', 'name',          'string'],
            ['price',        'sales', 'product_price', 'decimal'],
            ['amount',       'sales', 'amount',        'integer'],
        ] as [$sheetColumn, $table, $dbColumn, $type]) {
            $tab->columnMappings()->updateOrCreate(
                ['destination_table' => $table, 'destination_column' => $dbColumn],
                ['source_column' => $sheetColumn, 'destination_type' => $type],
            );
        }
    }
}