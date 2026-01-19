<?php

declare(strict_types=1);

namespace ProPhoto\Access\Support;

use ProPhoto\Access\Models\Studio;
use ProPhoto\Access\Models\Organization;

final class TenantContext
{
    private static ?Studio $studio = null;
    private static ?Organization $organization = null;

    public static function setStudio(?Studio $studio): void
    {
        self::$studio = $studio;
    }

    public static function studio(): ?Studio
    {
        return self::$studio;
    }

    public static function studioId(): ?int
    {
        return self::$studio?->getKey();
    }

    public static function setOrganization(?Organization $organization): void
    {
        self::$organization = $organization;
    }

    public static function organization(): ?Organization
    {
        return self::$organization;
    }

    public static function organizationId(): ?int
    {
        return self::$organization?->getKey();
    }

    public static function clear(): void
    {
        self::$studio = null;
        self::$organization = null;
    }
}
