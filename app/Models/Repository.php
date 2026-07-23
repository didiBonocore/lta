<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $full_name
 * @property string $owner
 * @property string $name
 * @property string $url
 * @property string $clone_path
 * @property string $head_sha
 * @property string|null $license
 * @property \Illuminate\Support\Carbon|null $github_created_at
 * @property \Illuminate\Support\Carbon|null $cloned_at
 * @property string|null $primary_test_framework
 */
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
