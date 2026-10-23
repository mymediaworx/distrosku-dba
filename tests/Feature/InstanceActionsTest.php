<?php

namespace Tests\Feature;

use App\Filament\Resources\InstanceResource\Pages\ViewInstance;
use App\Filament\Resources\InstanceResource\RelationManagers\BackupsRelationManager;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

class InstanceActionsTest extends DbaTestCase
{
    public function test_suspend_requires_typed_confirmation_then_resume(): void
    {
        $this->actingAs($this->admin);
        $i = $this->makeInstance();
        $this->fakeErp($i, ['suspend' => ['ok' => true, 'suspended' => true], 'resume' => ['ok' => true, 'suspended' => false]]);
        Livewire::test(ViewInstance::class, ['record' => $i->id])
            ->callAction('suspend', ['reason' => 'Billing hold', 'confirm' => 'wrong'])->assertHasActionErrors(['confirm']);
        $this->assertSame([], $this->erpCalls);
        Livewire::test(ViewInstance::class, ['record' => $i->id])
            ->callAction('suspend', ['reason' => 'Billing hold', 'confirm' => 'vegatest1'])->assertHasNoActionErrors();
        $this->assertSame('suspended', $i->fresh()->status);
        $this->assertSame(['path' => 'suspend', 'body' => ['reason' => 'Billing hold', 'by' => 'owner@example.test']], $this->erpCalls[0]);
        Livewire::test(ViewInstance::class, ['record' => $i->id])->callAction('resume');
        $this->assertSame('active', $i->fresh()->status);
        $this->assertSame(['suspend', 'resume'], AuditLog::orderBy('id')->pluck('event')->all());
    }

    public function test_failed_suspend_does_not_flip_status_and_is_audited(): void
    {
        $this->actingAs($this->admin);
        $i = $this->makeInstance();
        Http::fake(['*' => Http::response(['ok' => false, 'error' => 'bad signature'], 401)]);
        Livewire::test(ViewInstance::class, ['record' => $i->id])->callAction('suspend', ['confirm' => 'vegatest1']);
        $this->assertSame('active', $i->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['event' => 'suspend', 'ok' => false]);
    }

    public function test_backup_now_and_restore_with_confirmation(): void
    {
        $this->actingAs($this->admin);
        $i = $this->makeInstance();
        $file = 'vegatest1-20261022-013000-nightly.zip';
        $this->fakeErp($i, [
            'status' => $this->erpStatus(),
            'backup' => ['ok' => true, 'backup' => ['file' => 'vegatest1-20261022-120000-manager.zip', 'sha256' => str_repeat('c', 64), 'size' => 99]],
            'restore' => fn ($b) => ['ok' => true, 'result' => ['file' => $b['file'], 'safety_backup' => 'vegatest1-x-pre-restore.zip', 'rows' => 10, 'files' => 2, 'tables' => 3, 'version_warning' => null]],
        ]);
        Livewire::test(ViewInstance::class, ['record' => $i->id])->callAction('backup');
        $this->assertDatabaseHas('audit_logs', ['event' => 'backup', 'ok' => true]);
        $this->assertSame(1, $i->backups()->count()); // synced from the status call after the backup

        Livewire::test(ViewInstance::class, ['record' => $i->id])->callAction('restore', ['file' => $file, 'confirm' => 'nope'])->assertHasActionErrors(['confirm']);
        Livewire::test(ViewInstance::class, ['record' => $i->id])->callAction('restore', ['file' => $file, 'confirm' => 'vegatest1'])->assertHasNoActionErrors();
        $restore = collect($this->erpCalls)->firstWhere('path', 'restore');
        $this->assertSame(['file' => $file, 'confirm' => 'vegatest1', 'by' => 'owner@example.test'], $restore['body']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'restore', 'ok' => true]);

        // also from the backups list
        Livewire::test(BackupsRelationManager::class, ['ownerRecord' => $i, 'pageClass' => ViewInstance::class])
            ->assertCanSeeTableRecords($i->backups)
            ->callTableAction('restore', $i->backups()->first(), ['confirm' => 'vegatest1'])->assertHasNoTableActionErrors();
        $this->assertSame(2, AuditLog::where('event', 'restore')->count());
    }

    public function test_rotate_secret_breaks_the_old_link(): void
    {
        $this->actingAs($this->admin);
        $i = $this->makeInstance();
        $old = $i->secret;
        Livewire::test(ViewInstance::class, ['record' => $i->id])->callAction('rotate')->assertNotified();
        $this->assertNotSame($old, $i->fresh()->secret);
        $this->assertDatabaseHas('audit_logs', ['event' => 'secret_rotated']);
    }
}
