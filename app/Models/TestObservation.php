<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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
 * @property int $mock_breadth_excluding_facades
 * @property int $max_mock_chain_depth
 * @property array<string>|null $mock_kinds
 * @property int $size_statements
 * @property int $size_loc
 * @property int|null $start_line
 * @property int|null $end_line
 * @property bool $uses_refresh_database
 * @property array<string>|null $setup_signals
 * @property string|null $introduced_commit_sha
 * @property Carbon|null $introduced_author_date
 * @property string|null $ai_window
 * @property bool|null $agent_authored null = not yet blamed; false = blamed, no agent trace
 * @property string|null $agent_tool matched agent pattern (never an author name or email)
 * @property string|null $tool_version Tv — the lta revision that produced this observation
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
        'agent_authored' => 'boolean',
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
