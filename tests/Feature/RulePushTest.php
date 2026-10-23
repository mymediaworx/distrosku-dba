<?php

namespace Tests\Feature;

use App\Filament\Resources\RuleSetResource\Pages\PushRuleSet;
use App\Models\RuleSet;
use App\Services\RuleDiff;
use App\Services\RulePusher;
use Livewire\Livewire;

class RulePushTest extends DbaTestCase
{
    /** A fake ERP that applies rule sets the way the ERP does: upsert by code, never delete, idempotent. */
    private array $erpRows = [];

    private ?int $erpVersion = null;

    private function erpApply(array $body, bool $write): array
    {
        $rs = $body['ruleset'];
        $out = ['version' => $rs['version'], 'installed_version' => $this->erpVersion, 'sets' => [], 'totals' => ['create' => 0, 'update' => 0, 'same' => 0, 'kept_local' => 0]];
        foreach ($rs['sets'] as $set => $rows) {
            foreach ($rows as $row) {
                $cur = $this->erpRows[$set][$row['code']] ?? null;
                $a = $cur === null ? 'create' : ($cur == $row['data'] ? 'same' : 'update');
                $out['sets'][$set][] = ['code' => $row['code'], 'action' => $a, 'fields' => []];
                $out['totals'][$a]++;
                if ($write) {
                    $this->erpRows[$set][$row['code']] = $row['data'];
                }
            }
        }
        if ($write) {
            $this->erpVersion = $rs['version'];
        }

        return $write ? ['ok' => true, 'result' => $out] : ['ok' => true, 'preview' => $out];
    }

    private function setUpErp()
    {
        $i = $this->makeInstance();
        $this->fakeErp($i, ['rules/preview' => fn ($b) => $this->erpApply($b, false), 'rules/apply' => fn ($b) => $this->erpApply($b, true)]);

        return $i;
    }

    public function test_import_v1_from_the_erp_export(): void
    {
        $this->artisan('dba:import-rules', ['--publish' => true])->assertSuccessful();
        $set = RuleSet::first();
        $this->assertSame(1, $set->version);
        $this->assertSame(['license_types' => 5, 'eligibility_rules' => 3, 'compliance_rules' => 14, 'tax_rates' => 11], $set->counts());
        $this->assertSame('published', $set->status);
        $this->assertSame('distrosku-rules/1', $set->toRulesPayload()['format']);
        $this->artisan('dba:import-rules', ['file' => '/nonexistent.json'])->assertFailed();
    }

    public function test_normalise_rejects_bad_files(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RuleSet::normalise(['format' => 'distrosku-rules/1', 'sets' => ['tax_rates' => [['code' => 'a', 'data' => []], ['code' => 'a', 'data' => []]]]]);
    }

    public function test_preview_then_push_is_idempotent_and_tracks_versions(): void
    {
        $i = $this->setUpErp();
        $this->artisan('dba:import-rules', ['--publish' => true]);
        $set = RuleSet::first();
        $p = app(RulePusher::class);

        $pre = $p->preview($set, $i);
        $this->assertTrue($pre->ok);
        $this->assertSame(33, $pre->result['totals']['create']);
        $this->assertNull($this->erpVersion); // preview writes nothing
        $this->assertNull($i->fresh()->rules_version);

        $first = $p->push($set, $i);
        $this->assertSame(33, $first->result['totals']['create']);
        $this->assertSame(1, $i->fresh()->rules_version);
        $again = $p->push($set, $i);
        $this->assertSame(0, $again->result['totals']['create'] + $again->result['totals']['update']);
        $this->assertSame(33, $again->result['totals']['same']);

        // v2: one change, one row dropped -> dropped row stays on the ERP
        $payload = $set->payload;
        $payload['tax_rates'][0]['data']['rate'] = 9.99;
        $dropped = array_pop($payload['license_types']);
        $v2 = RuleSet::create(['version' => 2, 'name' => 'v2', 'payload' => $payload, 'status' => 'published']);
        $diff = RuleDiff::between($set, $v2);
        $this->assertSame([$payload['tax_rates'][0]['code']], $diff['tax_rates']['changed']);
        $this->assertSame([$dropped['code']], $diff['license_types']['dropped']);
        $r = $p->push($v2, $i);
        $this->assertSame(1, $r->result['totals']['update']);
        $this->assertArrayHasKey($dropped['code'], $this->erpRows['license_types']);
        $this->assertSame(2, $i->fresh()->rules_version);
        $this->assertSame(3, \App\Models\AuditLog::where('event', 'rules_pushed')->count());
    }

    public function test_push_page_previews_then_pushes(): void
    {
        $i = $this->setUpErp();
        $this->artisan('dba:import-rules', ['--publish' => true]);
        $set = RuleSet::first();
        $this->actingAs($this->admin);
        Livewire::test(PushRuleSet::class, ['record' => $set->id])
            ->assertFormSet(['instances' => [$i->id]])
            ->call('preview')->assertSet("previews.{$i->id}.ok", true)->assertSee('33 create')
            ->call('push')->assertSet("applied.{$i->id}.ok", true)->assertNotified();
        $this->assertSame(1, $i->fresh()->rules_version);
        $this->assertSame(['rules/preview', 'rules/apply'], array_column($this->erpCalls, 'path'));
    }

    public function test_unreachable_instance_is_reported_not_pushed(): void
    {
        $i = $this->makeInstance();
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response('down', 502)]);
        $this->artisan('dba:import-rules', ['--publish' => true]);
        $push = app(RulePusher::class)->push(RuleSet::first(), $i);
        $this->assertFalse($push->ok);
        $this->assertNull($i->fresh()->rules_version);
        $this->assertDatabaseHas('audit_logs', ['event' => 'rules_pushed', 'ok' => false]);
    }
}
