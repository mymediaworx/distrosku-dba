<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Instance;
use App\Models\RulePush;
use App\Models\RuleSet;
use Throwable;

class RulePusher
{
    public function __construct(private ErpClient $client) {}

    public function preview(RuleSet $set, Instance $i): RulePush
    {
        return $this->run($set, $i, 'preview');
    }

    public function push(RuleSet $set, Instance $i): RulePush
    {
        return $this->run($set, $i, 'apply');
    }

    private function run(RuleSet $set, Instance $i, string $mode): RulePush
    {
        try {
            $r = $mode === 'apply' ? $this->client->rulesApply($i, $set->toRulesPayload()) : $this->client->rulesPreview($i, $set->toRulesPayload());
            $push = RulePush::create(['rule_set_id' => $set->id, 'instance_id' => $i->id, 'mode' => $mode, 'ok' => true, 'result' => $r, 'by' => $this->client->by()]);
            if ($mode === 'apply') {
                $i->forceFill(['rules_version' => $set->version])->save();
                AuditLog::record('rules_pushed', $i, "Rule set v{$set->version} applied", ['version' => $set->version, 'totals' => $r['totals'] ?? null]);
            }

            return $push;
        } catch (Throwable $e) {
            $push = RulePush::create(['rule_set_id' => $set->id, 'instance_id' => $i->id, 'mode' => $mode, 'ok' => false, 'error' => mb_substr($e->getMessage(), 0, 250), 'by' => $this->client->by()]);
            if ($mode === 'apply') {
                AuditLog::record('rules_pushed', $i, "Rule set v{$set->version} failed: ".$push->error, ['version' => $set->version], false);
            }

            return $push;
        }
    }
}
