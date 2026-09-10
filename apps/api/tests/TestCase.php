<?php

namespace Tests;

use App\Backups\DeletionLedger;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Fakes\InMemoryDeletionLedger;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(DeletionLedger::class, new InMemoryDeletionLedger);
    }
}
