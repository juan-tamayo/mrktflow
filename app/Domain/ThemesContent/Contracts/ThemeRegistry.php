<?php

declare(strict_types=1);

namespace App\Domain\ThemesContent\Contracts;

use App\Domain\ThemesContent\Data\ThemeRegistration;

interface ThemeRegistry
{
    /**
     * Validate the manifest of an installed theme directory and record
     * the outcome. Semantic failures (incompatible versions, escaping or
     * missing paths, unknown fields) are recorded as an `invalid`
     * registration with reasons, never thrown and never silently usable;
     * structurally unusable input (no directory, no manifest, malformed
     * JSON, no usable theme id, a duplicate id owned by another
     * directory) throws before anything is recorded.
     */
    public function register(string $themeDirectory): ThemeRegistration;

    public function find(string $themeId): ?ThemeRegistration;

    /** Only a recorded `valid` registration may ever be activated. */
    public function isActivatable(string $themeId): bool;
}
