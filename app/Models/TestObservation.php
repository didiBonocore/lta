<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $snapshot_id
 * @property int $repository_id
 * @property string $file_path
 * @property string $identifier
 * @property string $front_end
 * @property string $test_type
 * @property string|null $test_type_rule
 * @property int $test_assertion_count
 * @property int $mock_assertion_count
 * @property int $total_assertion_count
 * @property float $mock_assertion_ratio
 * @property int $mock_breadth
 * @property int $max_mock_chain_depth
 * @property array|null $mock_kinds
 * @property int $size_statements
 * @property int $size_loc
 * @property int|null $start_line
 * @property int|null $end_line
 * @property bool $uses_refresh_database
 * @property array|null $setup_signals
 * @property string|null $introduced_commit_sha
 * @property \Illuminate\Support\Carbon|null $introduced_author_date
 * @property string|null $ai_window
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
        'mock_assertion_ratio' => 'float',
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
