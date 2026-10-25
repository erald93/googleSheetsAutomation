<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\SheetTab;

class SyncLog extends Model
{
    protected $fillable = [
        'sheet_tab_id',
        'status',
        'rows_processed',
        'rows_imported',
        'rows_failed',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'rows_processed' => 'integer',
            'rows_imported' => 'integer',
            'rows_failed' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function tab(): BelongsTo
    {
        return $this->belongsTo(SheetTab::class, 'sheet_tab_id');
    }
}
