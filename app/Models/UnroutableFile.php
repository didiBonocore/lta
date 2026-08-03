<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A test-suite file that parses but no front end owns (e.g. Codeception tests and support
 * classes). Routing correctly declines these; this table makes the exclusion auditable per
 * repository and checkpoint instead of leaving it in a console counter.
 *
 * @property int $id
 * @property int $repository_id
 * @property int $snapshot_id
 * @property string $file_path
 * @property string $commit_sha
 * @property string|null $base_class
 */
class UnroutableFile extends Model
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
