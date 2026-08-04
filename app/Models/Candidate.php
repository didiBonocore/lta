<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One screened corpus candidate — a row of the published decision log (Appendix A). Keyed
 * by full_name; the repositories link is optional because most candidates are screened,
 * rejected and never mined.
 *
 * @property int $id
 * @property string $full_name
 * @property int|null $repository_id
 * @property string $dependency_ok
 * @property string $majors_ok
 * @property string $suite_ok
 * @property string $cloneable_ok
 * @property string $package_ok
 * @property string $fork_ok
 * @property string $file_proportion_ok
 * @property string $loc_proportion_ok
 * @property string $manual_ok
 * @property string|null $framework_constraint
 * @property string|null $composer_type
 * @property int|null $majors_count
 * @property int|null $suite_routed_files
 * @property int|null $fork_ahead_by
 * @property int|null $test_file_count
 * @property int|null $php_file_count
 * @property float|null $test_file_proportion
 * @property int|null $test_line_count
 * @property int|null $php_line_count
 * @property float|null $test_loc_proportion
 * @property string|null $root_commit_sha
 * @property string|null $shared_history_with
 * @property string|null $shared_divergence_sha
 * @property string|null $manual_decision
 * @property string|null $manual_reason
 * @property Carbon|null $manual_decided_at
 * @property string $verdict
 * @property Carbon|null $verdict_computed_at
 * @property Carbon|null $screened_at
 */
class Candidate extends Model
{
    protected $guarded = [];

    protected $casts = [
        'test_file_proportion' => 'float',
        'test_loc_proportion' => 'float',
        'manual_decided_at' => 'datetime',
        'verdict_computed_at' => 'datetime',
        'screened_at' => 'datetime',
    ];

    /** @return BelongsTo<Repository, $this> */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /** Every automatable phase-1 criterion passed (the pool the quartiles are computed over). */
    public function passesPhaseOne(): bool
    {
        return $this->dependency_ok === 'pass'
            && $this->majors_ok === 'pass'
            && $this->suite_ok === 'pass'
            && $this->cloneable_ok === 'pass'
            && $this->package_ok === 'pass'
            && $this->fork_ok === 'pass';
    }
}
