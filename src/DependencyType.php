<?php

/**
 * ---------------------------------------------------------------------
 * Project Manager — DependencyType
 * Dependency types between project tasks.
 * ---------------------------------------------------------------------
 *
 * @author    IMAGUNET S.A.S.
 * @license   GPL-3.0-or-later
 */

namespace GlpiPlugin\Projectmanager;

final class DependencyType
{
    const FS = 'FS'; // Finish-to-Start  (most common)
    const SS = 'SS'; // Start-to-Start
    const FF = 'FF'; // Finish-to-Finish
    const SF = 'SF'; // Start-to-Finish  (rare)

    public static function getAll(): array
    {
        return [
            self::FS => __('Finish-to-Start (FS)', 'projectmanager'),
            self::SS => __('Start-to-Start (SS)',  'projectmanager'),
            self::FF => __('Finish-to-Finish (FF)', 'projectmanager'),
            self::SF => __('Start-to-Finish (SF)', 'projectmanager'),
        ];
    }

    public static function isValid(string $type): bool
    {
        return in_array($type, [self::FS, self::SS, self::FF, self::SF], true);
    }
}
