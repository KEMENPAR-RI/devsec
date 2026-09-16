<?php

namespace Tests;

use App\Models\Setting;
use App\Services\Security\OutboundUrlGuard;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Setting::flush();
        OutboundUrlGuard::flush();
    }
}
