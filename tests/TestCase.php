<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Repository;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\GitFixtureRepo;

abstract class TestCase extends BaseTestCase
{
    public string $root;

    public GitFixtureRepo $repo;

    public string $preCommit;

    public string $postCommit;

    public Repository $repository;
}
