<?php

namespace Tests\Feature;

use App\Filament\Resources\InstanceResource;
use App\Filament\Resources\InstanceResource\Pages\CreateInstance;
use App\Filament\Resources\InstanceResource\Pages\ViewInstance;
use App\Filament\Resources\RuleSetResource;
use App\Models\AuditLog;
use App\Models\Instance;
use App\Models\RuleSet;
use App\Models\User;
use Livewire\Livewire;

class PanelTest extends DbaTestCase
{
    public function test_admin_only(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Sign in');
        $this->actingAs(User::factory()->create(['is_admin' => false]))->get('/')->assertForbidden();
    }

    public function test_every_screen_renders(): void
    {
        $this->artisan('dba:import-rules', ['--publish' => true])->assertSuccessful();
        $i = $this->makeInstance(['version' => '0.20.7']);
        $this->makeInstance(['name' => 'Prod A', 'slug' => 'proda', 'environment' => 'prod', 'version' => '0.20.8', 'status' => 'down']);
        $this->actingAs($this->admin);
        $set = RuleSet::first();
        foreach (['/', '/instances', '/instances/create', "/instances/{$i->id}", "/instances/{$i->id}/edit", '/rule-sets', '/rule-sets/create',
            "/rule-sets/{$set->id}", "/rule-sets/{$set->id}/push", '/releases', '/audit-logs'] as $url) {
            $this->get($url)->assertOk();
        }
        \Livewire\Livewire::test(\App\Filament\Widgets\AttentionTable::class)->assertSee('Needs attention')->assertSee('Prod A')->assertSee('Not responding');
        \Livewire\Livewire::test(\App\Filament\Widgets\FleetOverview::class)->assertSee('Behind latest');
        $this->get('/releases')->assertSee('behind 0.20.8');
        $this->get("/rule-sets/{$set->id}/edit")->assertForbidden(); // published = immutable
    }

    public function test_register_instance_shows_secret_once_and_audits(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(CreateInstance::class)
            ->fillForm(['name' => 'Vega Test 1', 'slug' => 'vegatest1', 'url' => 'https://vegatest1.example.test/', 'environment' => 'test'])
            ->call('create')->assertHasNoFormErrors()->assertNotified();
        $i = Instance::firstWhere('slug', 'vegatest1');
        $this->assertSame('https://vegatest1.example.test', $i->url);
        $this->assertDatabaseHas('audit_logs', ['event' => 'instance_registered', 'instance_id' => $i->id]);
        // the view page never prints the secret
        $this->get(InstanceResource::getUrl('view', ['record' => $i]))->assertDontSee($i->secret);
    }

    public function test_owner_command_requires_a_password_and_creates_an_admin(): void
    {
        $this->artisan('dba:make-owner', ['--email' => 'michael@example.test'])->expectsQuestion('Password (min 12 characters)', 'short')->assertFailed();
        $this->artisan('dba:make-owner', ['--email' => 'michael@example.test'])->expectsQuestion('Password (min 12 characters)', 'a-long-password-123')->assertSuccessful();
        $u = User::firstWhere('email', 'michael@example.test');
        $this->assertTrue($u->is_admin);
        $this->assertTrue(\Hash::check('a-long-password-123', $u->password));
        $this->assertFalse($u->hasTwoFactor());
    }

    public function test_upgrade_is_a_disabled_stub(): void
    {
        $this->actingAs($this->admin);
        $i = $this->makeInstance();
        Livewire::test(ViewInstance::class, ['record' => $i->id])->assertActionDisabled('upgrade');
    }
}
