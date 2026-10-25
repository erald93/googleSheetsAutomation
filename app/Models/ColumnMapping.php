<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\SheetTab;

class ColumnMapping extends Model
{
    protected $fillable = [
        'sheet_tab_id',
        'source_column',
        'destination_table',
        'destination_column',
        'destination_type',
        'is_required',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function tab(): BelongsTo
    {
        return $this->belongsTo(SheetTab::class, 'sheet_tab_id');
    }
}
