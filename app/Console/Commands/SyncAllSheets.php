<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\SheetTab;

class SyncAllSheets extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sheets:sync';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import every active tab of every active spreadsheet';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $tabs = SheetTab::query()
            ->where('is_active', true)
            ->whereHas('source', fn ($query) => $query->where('is_active', true))
            ->with('source')
            ->orderBy('id')
            ->get();

        if ($tabs->isEmpty()) {
            $this->warn('Nothing to sync: no active tabs are configured.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($tabs as $tab) {
            $this->newLine();
            $this->info("[{$tab->source->name}] tab '{$tab->tab_name}' (id {$tab->id})");

            // One failing tab must not stop the others
            if ($this->call('sheets:import', ['tabId' => $tab->id]) !== self::SUCCESS) {
                $failed++;
            }
        }

        $this->newLine();
        $this->line('Tabs synced: ' . ($tabs->count() - $failed) . ', failed: ' . $failed);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
