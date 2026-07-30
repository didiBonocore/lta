<?php

use App\Models\User;

/**
 * False-positive guard: toArray() matches the /^to[A-Z]/ terminator name pattern but never
 * sits in a chain rooted at expect(), so it must not be counted — neither inside an expect()
 * argument nor as a bare statement.
 * Hand-computed:
 *   testAssertionCount = 1   (toBe — the only terminator whose chain roots at expect())
 *   mockAssertionCount = 0
 *   totalAssertionCount = 1
 *   mockAssertionRatio = 0.0
 */
it('serialises an unsaved model to an empty array', function () {
    $model = new User;

    $model->toArray();

    expect($model->toArray())->toBe([]);
});
