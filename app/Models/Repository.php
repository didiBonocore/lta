<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Repository extends Model
{
    protected $guarded = [];

    protected $casts = [
        'github_created_at' => 'date',
        'cloned_at' => 'datetime',
    ];

    public function snapshots(): HasMany
    {
        return $this->hasMany(Snapshot::class);
    }

    public function observations(): HasMany
    {
        return $this->hasMany(TestObservation::class);
    }
}
