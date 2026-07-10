<?php

namespace GlpiPlugin\Projectmanager\Tests;

use GlpiPlugin\Projectmanager\Config;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    public function testGetFallsBackToDefaultForAnUnknownKey(): void
    {
        $this->assertSame('fallback', Config::get('this_key_does_not_exist', 'fallback'));
        $this->assertNull(Config::get('this_key_does_not_exist'));
    }

    public function testGetReturnsTheStoredValueWhenPresent(): void
    {
        // cascade_auto is a real column with a non-null default (1),
        // so get() must return the DB value, not the fallback.
        $this->assertNotSame('fallback', Config::get('cascade_auto', 'fallback'));
    }

    public function testIsModuleEnabledReturnsABoolean(): void
    {
        $this->assertIsBool(Config::isModuleEnabled('dependencies'));
        // A module name that was never a column just means "not enabled".
        $this->assertFalse(Config::isModuleEnabled('this_module_does_not_exist'));
    }
}
