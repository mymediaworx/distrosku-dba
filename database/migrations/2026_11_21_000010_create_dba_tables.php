<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('is_admin')->default(false)->after('password');
            // 2FA-ready (TOTP): populated once a 2FA plugin/flow is enabled.
            $t->text('two_factor_secret')->nullable();
            $t->text('two_factor_recovery_codes')->nullable();
            $t->timestamp('two_factor_confirmed_at')->nullable();
            $t->timestamp('last_login_at')->nullable();
        });

        Schema::create('instances', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->string('url');
            $t->string('environment', 10)->default('test'); // prod|test
            $t->string('client')->nullable();
            $t->string('status', 12)->default('active');     // active|suspended|down
            $t->string('version', 20)->nullable();
            $t->text('secret');                               // encrypted cast: needed to compute HMACs, so reversible
            $t->timestamp('last_check_in_at')->nullable();
            $t->timestamp('last_ok_at')->nullable();
            $t->timestamp('last_backup_at')->nullable();
            $t->string('last_backup_status', 20)->nullable();
            $t->unsignedInteger('rules_version')->nullable();
            $t->json('last_status')->nullable();
            $t->unsignedInteger('consecutive_failures')->default(0);
            $t->boolean('alerting')->default(false);
            $t->string('suspend_reason')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });

        Schema::create('instance_checks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('instance_id')->constrained()->cascadeOnDelete();
            $t->boolean('ok');
            $t->string('source', 10)->default('poll'); // poll|push|manual
            $t->unsignedInteger('response_ms')->nullable();
            $t->string('version', 20)->nullable();
            $t->unsignedInteger('queue_pending')->nullable();
            $t->unsignedInteger('queue_failed')->nullable();
            $t->unsignedBigInteger('disk_free')->nullable();
            $t->string('error')->nullable();
            $t->timestamp('checked_at')->index();
        });

        Schema::create('instance_backups', function (Blueprint $t) {
            $t->id();
            $t->foreignId('instance_id')->constrained()->cascadeOnDelete();
            $t->string('filename');
            $t->unsignedBigInteger('size')->default(0);
            $t->string('sha256', 64)->nullable();
            $t->string('trigger', 20)->nullable();
            $t->string('status', 20)->default('present'); // present|verified|corrupt|gone
            $t->timestamp('taken_at')->nullable();
            $t->timestamps();
            $t->unique(['instance_id', 'filename']);
        });

        Schema::create('rule_sets', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('version')->unique();
            $t->string('name');
            $t->text('notes')->nullable();
            $t->json('payload'); // {license_types, eligibility_rules, compliance_rules, tax_rates}
            $t->string('status', 12)->default('draft'); // draft|published
            $t->timestamp('published_at')->nullable();
            $t->string('created_by')->nullable();
            $t->timestamps();
        });

        Schema::create('rule_pushes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('rule_set_id')->constrained()->cascadeOnDelete();
            $t->foreignId('instance_id')->constrained()->cascadeOnDelete();
            $t->string('mode', 10); // preview|apply
            $t->boolean('ok');
            $t->json('result')->nullable();
            $t->string('error')->nullable();
            $t->string('by')->nullable();
            $t->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->string('event', 40)->index();
            $t->foreignId('instance_id')->nullable()->constrained()->nullOnDelete();
            $t->string('user')->nullable();
            $t->string('ip', 45)->nullable();
            $t->boolean('ok')->default(true);
            $t->text('description')->nullable();
            $t->json('meta')->nullable();
            $t->timestamp('created_at')->index();
        });

        Schema::create('dba_nonces', function (Blueprint $t) {
            $t->string('nonce', 90)->primary();
            $t->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        foreach (['dba_nonces', 'audit_logs', 'rule_pushes', 'rule_sets', 'instance_backups', 'instance_checks', 'instances'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['is_admin', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'last_login_at']));
    }
};
