<?php

namespace Tests;

use App\Models\Setting;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Setting memoizes resolved values in a static, which - unlike the array
        // cache store and the database - is not torn down between tests. Without
        // this, a value read in one test would still be answered in the next,
        // after the row behind it had been rolled back.
        Setting::flush();
    }
}
