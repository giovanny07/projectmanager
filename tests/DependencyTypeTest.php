<?php

namespace GlpiPlugin\Projectmanager\Tests;

use GlpiPlugin\Projectmanager\DependencyType;
use PHPUnit\Framework\TestCase;

class DependencyTypeTest extends TestCase
{
    public function testValidTypesAreAccepted(): void
    {
        foreach (['FS', 'SS', 'FF', 'SF'] as $type) {
            $this->assertTrue(DependencyType::isValid($type));
        }
    }

    public function testUnknownTypeIsRejected(): void
    {
        $this->assertFalse(DependencyType::isValid('XX'));
        $this->assertFalse(DependencyType::isValid(''));
    }

    public function testGetAllReturnsTheFourTypesKeyedByCode(): void
    {
        $all = DependencyType::getAll();

        $this->assertSame(['FS', 'SS', 'FF', 'SF'], array_keys($all));
        foreach ($all as $label) {
            $this->assertIsString($label);
            $this->assertNotSame('', $label);
        }
    }
}
