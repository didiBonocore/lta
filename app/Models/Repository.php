<?php

declare(strict_types=1);

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

    /** @return HasMany<Snapshot, $this> */
    public function snapshots(): HasMany
    {
        return $this->hasMany(Snapshot::class);
    }

    /** @return HasMany<TestObservation, $this> */
    public function observations(): HasMany
    {
        return $this->hasMany(TestObservation::class);
    }
}
