<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Snapshot extends Model
{
    protected $guarded = [];

    protected $casts = [
        'commit_date' => 'datetime',
        'framework_version' => 'integer',
    ];

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    public function observations(): HasMany
    {
        return $this->hasMany(TestObservation::class);
    }
}
