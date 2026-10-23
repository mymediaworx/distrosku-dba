<?php

namespace App\Services\Upgrade;

use App\Models\Instance;

class NullUpgradeDriver implements UpgradeDriver
{
    public function available(): bool
    {
        return false;
    }

    public function upgrade(Instance $instance, string $toVersion): array
    {
        return ['ok' => false, 'message' => 'One-click upgrade is planned for v2. Upgrade with the release zip and the upgrade page for now.'];
    }
}
