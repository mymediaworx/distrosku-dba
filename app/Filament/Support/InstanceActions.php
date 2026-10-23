<?php

namespace App\Filament\Support;

use App\Models\AuditLog;
use App\Models\Instance;
use App\Services\ErpClient;
use App\Services\HealthChecker;
use App\Services\Upgrade\UpgradeDriver;
use Filament\Forms;
use Filament\Notifications\Notification;
use Throwable;

/** Shared actions for the instance table (Tables\Actions\Action) and the view page (Actions\Action). */
class InstanceActions
{
    private static function fail(string $title, Throwable $e): void
    {
        Notification::make()->danger()->title($title)->body($e->getMessage())->persistent()->send();
    }

    public static function check(string $class)
    {
        return $class::make('check')->label('Check now')->icon('heroicon-o-signal')->color('gray')
            ->action(function (Instance $record) {
                $c = app(HealthChecker::class)->check($record, 'manual');
                $c->ok ? Notification::make()->success()->title("{$record->name} is up")->body("Version {$c->version} · {$c->response_ms} ms")->send()
                    : Notification::make()->danger()->title("{$record->name} check failed")->body($c->error)->send();
            });
    }

    public static function backup(string $class)
    {
        return $class::make('backup')->label('Back up now')->icon('heroicon-o-archive-box-arrow-down')->color('gray')
            ->requiresConfirmation()->modalDescription('Creates a checksummed backup on the instance host (outside its web root).')
            ->action(function (Instance $record) {
                try {
                    $b = app(ErpClient::class)->backup($record);
                    AuditLog::record('backup', $record, "Backup {$b['file']} created", ['file' => $b['file'], 'sha256' => $b['sha256'] ?? null, 'size' => $b['size'] ?? null]);
                    app(HealthChecker::class)->check($record, 'manual');
                    Notification::make()->success()->title('Backup created')->body($b['file'])->send();
                } catch (Throwable $e) {
                    AuditLog::record('backup', $record, 'Backup failed: '.$e->getMessage(), ok: false);
                    self::fail('Backup failed', $e);
                }
            });
    }

    public static function suspend(string $class)
    {
        return $class::make('suspend')->label('Suspend')->icon('heroicon-o-pause-circle')->color('warning')
            ->visible(fn (Instance $record) => $record->status !== 'suspended')
            ->modalHeading(fn (Instance $record) => "Suspend {$record->name}?")
            ->modalDescription('Every user sees a "suspended" page (admin, portal, POS, driver) and scheduled jobs pause. No data is changed; nightly backups keep running. You can resume at any time.')
            ->form([
                Forms\Components\TextInput::make('reason')->maxLength(200)->placeholder('e.g. Billing hold'),
                Forms\Components\TextInput::make('confirm')->label('Type the instance id to confirm')->required()
                    ->rule(fn (Instance $record) => 'in:'.$record->slug)->validationMessages(['in' => 'Type the instance id exactly.']),
            ])
            ->modalSubmitActionLabel('Suspend')
            ->action(function (Instance $record, array $data) {
                try {
                    app(ErpClient::class)->suspend($record, $data['reason'] ?? null);
                    $record->forceFill(['status' => 'suspended', 'suspend_reason' => $data['reason'] ?? null])->save();
                    AuditLog::record('suspend', $record, 'Suspended'.(! empty($data['reason']) ? ': '.$data['reason'] : ''));
                    Notification::make()->warning()->title("{$record->name} suspended")->send();
                } catch (Throwable $e) {
                    AuditLog::record('suspend', $record, 'Suspend failed: '.$e->getMessage(), ok: false);
                    self::fail('Suspend failed', $e);
                }
            });
    }

    public static function resume(string $class)
    {
        return $class::make('resume')->label('Resume')->icon('heroicon-o-play-circle')->color('success')
            ->visible(fn (Instance $record) => $record->status === 'suspended')
            ->requiresConfirmation()->modalHeading(fn (Instance $record) => "Resume {$record->name}?")
            ->action(function (Instance $record) {
                try {
                    app(ErpClient::class)->resume($record);
                    $record->forceFill(['status' => 'active', 'suspend_reason' => null])->save();
                    AuditLog::record('resume', $record, 'Resumed');
                    Notification::make()->success()->title("{$record->name} resumed")->send();
                } catch (Throwable $e) {
                    AuditLog::record('resume', $record, 'Resume failed: '.$e->getMessage(), ok: false);
                    self::fail('Resume failed', $e);
                }
            });
    }

    public static function restore(string $class)
    {
        return $class::make('restore')->label('Restore…')->icon('heroicon-o-arrow-uturn-left')->color('danger')
            ->modalHeading(fn (Instance $record) => "Restore {$record->name}")
            ->modalDescription('The instance verifies the checksum, takes a safety backup, then replaces its data and storage files with the backup. .env is not touched.')
            ->form(fn (Instance $record) => [
                Forms\Components\Select::make('file')->label('Backup')->required()->native(false)
                    ->options($record->backups()->where('status', '!=', 'gone')->orderByDesc('taken_at')->get()
                        ->mapWithKeys(fn ($b) => [$b->filename => $b->filename.' · '.\Illuminate\Support\Number::fileSize($b->size, 1)])->all()),
                Forms\Components\TextInput::make('confirm')->label('Type the instance id to confirm')->required()
                    ->rule('in:'.$record->slug)->validationMessages(['in' => 'Type the instance id exactly.']),
            ])
            ->modalSubmitActionLabel('Restore')
            ->action(function (Instance $record, array $data) {
                try {
                    $r = app(ErpClient::class)->restore($record, $data['file']);
                    AuditLog::record('restore', $record, "Restored {$data['file']} (safety backup {$r['safety_backup']})", $r);
                    app(HealthChecker::class)->check($record, 'manual');
                    Notification::make()->success()->title('Restore complete')->body("{$r['rows']} rows, {$r['files']} files. Safety backup: {$r['safety_backup']}".($r['version_warning'] ? ' — '.$r['version_warning'] : ''))->persistent()->send();
                } catch (Throwable $e) {
                    AuditLog::record('restore', $record, 'Restore failed: '.$e->getMessage(), ['file' => $data['file']], false);
                    self::fail('Restore failed', $e);
                }
            });
    }

    public static function rotateSecret(string $class)
    {
        return $class::make('rotate')->label('New link secret')->icon('heroicon-o-key')->color('gray')
            ->requiresConfirmation()
            ->modalDescription('Generates a new secret. The ERP stops answering until you paste it into Settings > Instance manager there (or ERP_DBA_SECRET).')
            ->action(function (Instance $record) {
                $record->forceFill(['secret' => Instance::newSecret()])->save();
                AuditLog::record('secret_rotated', $record, 'Link secret rotated');
                self::showSecret($record, 'New link secret');
            });
    }

    /** Shown once, right after create/rotate. Stored encrypted (it must be reversible to sign requests). */
    public static function showSecret(Instance $record, string $title): void
    {
        Notification::make()->warning()->persistent()->title($title.' — copy it now, it is not shown again')
            ->body(new \Illuminate\Support\HtmlString('Paste into the ERP <b>Settings &gt; Instance manager</b> (or <code>ERP_DBA_SECRET</code>), with <code>ERP_INSTANCE='.e($record->slug).'</code>:<br><code style="word-break:break-all;user-select:all">'.e($record->secret).'</code>'))
            ->send();
    }

    public static function upgrade(string $class)
    {
        return $class::make('upgrade')->label('Upgrade (v2)')->icon('heroicon-o-arrow-up-circle')->color('gray')
            ->disabled(fn () => ! app(UpgradeDriver::class)->available())
            ->tooltip('One-click upgrade is planned for v2')
            ->action(fn (Instance $record) => Notification::make()->info()->title(app(UpgradeDriver::class)->upgrade($record, '')['message'])->send());
    }
}
