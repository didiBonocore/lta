<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TestObservation extends Model
{
    protected $guarded = [];

    protected $casts = [
        'mock_kinds' => 'array',
        'setup_signals' => 'array',
        'uses_refresh_database' => 'boolean',
        'introduced_author_date' => 'datetime',
    ];

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(Snapshot::class);
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }
}
