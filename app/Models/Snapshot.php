<?php

declare(strict_types=1);

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

    /** @return BelongsTo<Repository, $this> */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /** @return HasMany<TestObservation, $this> */
    public function observations(): HasMany
    {
        return $this->hasMany(TestObservation::class);
    }

    /** @return HasMany<ParseFailure, $this> */
    public function parseFailures(): HasMany
    {
        return $this->hasMany(ParseFailure::class);
    }
}
