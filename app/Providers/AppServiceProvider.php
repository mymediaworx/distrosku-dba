<?php

namespace App\Providers;

use App\Services\Upgrade\NullUpgradeDriver;
use App\Services\Upgrade\UpgradeDriver;
use Illuminate\Support\Facades\Event;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(UpgradeDriver::class, NullUpgradeDriver::class);
    }

    public function boot(): void
    {
        Event::listen(Login::class, function (Login $e) {
            $e->user->forceFill(['last_login_at' => now()])->saveQuietly();
            \App\Models\AuditLog::record('login', null, 'Signed in', user: $e->user->email);
        });
    }
}
