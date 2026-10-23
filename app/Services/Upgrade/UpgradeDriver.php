<?php

namespace App\Services\Upgrade;

use App\Models\Instance;

/**
 * v2 design stub: one-click upgrades.
 * Planned flow: take a pre-upgrade backup (erp:backup) -> upload the release zip to the instance host
 * -> call the ERP's tokenised upgrade page -> poll /dba-api/status until the new version reports -> on failure offer restore.
 */
interface UpgradeDriver
{
    public function available(): bool;

    /** @return array{ok:bool,message:string} */
    public function upgrade(Instance $instance, string $toVersion): array;
}
