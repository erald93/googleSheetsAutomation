<?php

namespace App\Console\Commands;

use App\Models\SheetTab;
use App\Models\SyncLog;
use App\Services\SheetImportService;
use Illuminate\Console\Command;
use Throwable;

class ImportGoogleSheet extends Command
{
    protected $signature = 'sheets:import {tabId : The configured sheet tab ID}';

    protected $description = 'Import data from a configured Google Sheets tab';

    public function handle(SheetImportService $importer): int
    {
        $tab = SheetTab::find($this->argument('tabId'));

        if (!$tab) {
            $this->error('Sheet tab configuration not found.');

            return self::FAILURE;
        }

        $log = SyncLog::create([
            'sheet_tab_id' => $tab->id,
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $result = $importer->import($tab);

            $log->update([
                'status' => $result['rows_failed'] > 0 ? 'completed_with_errors' : 'completed',
                'rows_processed' => $result['rows_processed'],
                'rows_imported' => $result['rows_imported'],
                'rows_failed' => $result['rows_failed'],
                'error_message' => !empty($result['errors'])
                    ? json_encode($result['errors'])
                    : null,
                'finished_at' => now(),
            ]);

            $this->info('Import finished.');
            $this->table(
                ['Metric', 'Count'],
                [
                    ['Rows processed', $result['rows_processed']],
                    ['Rows imported', $result['rows_imported']],
                    ['Rows failed', $result['rows_failed']],
                ]
            );

            foreach ($result['errors'] as $error) {
                $this->warn(
                    "Row {$error['row']}: {$error['message']}"
                );
            }
            
            foreach ($result['warnings'] ?? [] as $warning) {
                $this->warn($warning);
            }

            return $result['rows_failed'] > 0
                ? self::FAILURE
                : self::SUCCESS;
        } catch (Throwable $e) {
            $log->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            $this->error('Import failed: ' . $e->getMessage());

            return self::FAILURE;
        }
    }
}

