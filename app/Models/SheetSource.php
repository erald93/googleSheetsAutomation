<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\SheetTab;

class SheetSource extends Model
{
    protected $fillable = [
        'name',
        'spreadsheet_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function tabs(): HasMany
    {
        return $this->hasMany(SheetTab::class);
    }
}
