<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read int|null $major Alias of snapshots.framework_version, present when the row
 *                                was selected through DatasetQueries' joined samples.
 */
class TestObservation extends Model
{
    protected $guarded = [];

    protected $casts = [
        'mock_kinds' => 'array',
        'setup_signals' => 'array',
        'uses_refresh_database' => 'boolean',
        'introduced_author_date' => 'datetime',
    ];

    /** @return BelongsTo<Snapshot, $this> */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(Snapshot::class);
    }

    /** @return BelongsTo<Repository, $this> */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }
}
