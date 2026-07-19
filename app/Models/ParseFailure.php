<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParseFailure extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<Repository, $this> */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /** @return BelongsTo<Snapshot, $this> */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(Snapshot::class);
    }
}
