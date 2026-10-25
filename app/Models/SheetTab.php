<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\SheetSource;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\ColumnMapping;
use App\Models\SyncLog;

class SheetTab extends Model
{
    protected $fillable = [
        'sheet_source_id',
        'tab_name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(SheetSource::class, 'sheet_source_id');
    }

    public function columnMappings(): HasMany
    {
        return $this->hasMany(ColumnMapping::class);
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(SyncLog::class);
    }
}
