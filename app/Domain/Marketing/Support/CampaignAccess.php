<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Support;

use App\Domain\Identity\Models\StaffAccount;

final class CampaignAccess
{
    public static function canView(StaffAccount $staff): bool
    {
        return $staff->hasPermission('marketing.write')
            || $staff->hasPermission('marketing.activate')
            || $staff->hasPermission('marketing.send');
    }
}
