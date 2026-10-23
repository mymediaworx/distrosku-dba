<?php

use App\Models\AuditLog;
use App\Models\Instance;
use App\Models\RuleSet;
use App\Models\User;
use App\Services\HealthChecker;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;

Artisan::command('dba:poll {--instance= : slug of one instance}', function (HealthChecker $health) {
    $q = Instance::query()->orderBy('name');
    if ($s = $this->option('instance')) {
        $q->where('slug', $s);
    }
    foreach ($q->get() as $i) {
        $c = $health->check($i);
        $this->line(sprintf('%-24s %s %s', $i->name, $c->ok ? 'OK  ' : 'FAIL', $c->ok ? ($c->version.' '.$c->response_ms.'ms') : $c->error));
    }
    $health->prune();
})->purpose('Poll every ERP instance (health, version, queue, disk, backups)');

Artisan::command('dba:make-owner {--email=} {--name=}', function () {
    $email = $this->option('email') ?: config('dba.owner.email') ?: $this->ask('Owner e-mail');
    $name = $this->option('name') ?: config('dba.owner.name') ?: 'Owner';
    $password = env('DBA_OWNER_PASSWORD') ?: $this->secret('Password (min 12 characters)');
    $v = Validator::make(compact('email', 'password'), ['email' => 'required|email', 'password' => 'required|string|min:12']);
    if ($v->fails()) {
        $this->error(implode(' ', $v->errors()->all()));

        return 1;
    }
    $u = User::firstOrNew(['email' => $email]);
    $u->forceFill(['name' => $u->name ?: $name, 'password' => $password, 'is_admin' => true])->save();
    AuditLog::record('owner_set', null, "Admin {$email} created/updated from the console", user: 'console');
    $this->info("Admin {$email} is ready. Remove DBA_OWNER_PASSWORD from .env if you set it there.");
})->purpose('Create or reset the admin login (password prompted, never stored in code)');

Artisan::command('dba:import-rules {file? : distrosku-rules/1 JSON (default: the bundled v1 export)} {--publish}', function () {
    $file = $this->argument('file') ?: database_path('data/rules-v1.json');
    $data = json_decode((string) @file_get_contents($file), true);
    try {
        $sets = RuleSet::normalise((array) $data);
    } catch (InvalidArgumentException $e) {
        $this->error($e->getMessage());

        return 1;
    }
    $version = RuleSet::nextVersion();
    $set = RuleSet::create(['version' => $version, 'name' => $data['name'] ?? 'Imported', 'payload' => $sets,
        'notes' => 'Imported from '.basename($file).(isset($data['exported_from']) ? ' (ERP '.$data['exported_from'].')' : ''),
        'status' => $this->option('publish') ? 'published' : 'draft', 'published_at' => $this->option('publish') ? now() : null, 'created_by' => 'console']);
    AuditLog::record('rules_imported', null, "Rule set v{$version} imported", ['counts' => $set->counts()], user: 'console');
    $this->info("Rule set v{$version} created: ".json_encode($set->counts()));
})->purpose('Import a rule set exported by the ERP (erp:rules-export) as the next version');

Schedule::command('dba:poll')->everyFiveMinutes()->withoutOverlapping(10);
