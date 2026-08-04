<?php

/**
 * Pest file that also declares an inline test double at file scope. The class must not veto
 * Pest routing — this is the regex-era defect that silently dropped mock-heavy files — and
 * the double contributes no method records.
 * Hand-computed:
 *   methods = 2 (the two test() calls; FakeDriver contributes none)
 *   testAssertionCount = 2
 *     expect($driver->drive())->toBe('fake')  → 1
 *     expect(true)->toBeTrue()                → 1
 *   mockAssertionCount = 0
 *   totalAssertionCount = 2
 *   mockAssertionRatio = 0.0
 */
class FakeDriver extends SomeCollaborator
{
    public function drive(): string
    {
        return 'fake';
    }
}

test('drives with the fake', function () {
    $driver = new FakeDriver;

    expect($driver->drive())->toBe('fake');
});

test('reports readiness', function () {
    expect(true)->toBeTrue();
});
