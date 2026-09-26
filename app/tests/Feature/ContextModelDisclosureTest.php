<?php

namespace Tests\Feature;

use App\Models\ContextApplication;
use App\Models\User;
use App\Services\Context\ContextApplications;
use App\Services\NativeMemory\NativeMemoryService;
use App\Services\RedactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class ContextModelDisclosureTest extends TestCase
{
    use RefreshDatabase;

    private function setupApplication(?array $model = null): array
    {
        Http::preventStrayRequests();
        $owner = User::factory()->create();
        $memory = app(NativeMemoryService::class)->create($owner, ['content' => 'I prefer portable ledger software.']);
        $registration = app(ContextApplications::class)->create($owner, [
            'name' => 'Synthetic independent application', 'capabilities' => ['context.resolve', 'memory.read', 'memory.disclose'],
            'model_disclosure' => $model,
        ]);
        $this->withHeader('Authorization', 'Bearer '.$registration['token']);

        return [$owner, $memory, $registration];
    }

    private function model(): array
    {
        return ['destination' => 'https://model.example', 'model' => 'fixture/model', 'sources' => ['native_memory']];
    }

    private function resolve()
    {
        return $this->postJson('/api/app/context/resolve', ['version' => 'context-request-v1', 'query' => 'ledger', 'sources' => ['native_memory']]);
    }

    public function test_default_permission_remains_no_onward_disclosure(): void
    {
        $this->setupApplication();
        $this->getJson('/api/app/context/permissions')->assertOk()->assertJsonPath('model_disclosure', null);
        $this->resolve()->assertOk()->assertJsonPath('disclosure.onward_disclosure', 'not_authorized');
        Http::assertNothingSent();
    }

    public function test_named_model_permission_is_source_scoped_and_observable_without_proxying(): void
    {
        [, , $registration] = $this->setupApplication($this->model());
        $this->getJson('/api/app/context/permissions')->assertOk()
            ->assertJsonPath('model_disclosure.destination', 'https://model.example')
            ->assertJsonPath('sources.history.disclosure', false)->assertDontSee($registration['token']);
        $this->resolve()->assertOk()->assertJsonPath('disclosure.model', $this->model())
            ->assertJsonPath('disclosure.onward_disclosure', 'application_responsibility')
            ->assertJsonPath('disclosure.grant_revision', 1)->assertHeader('X-Context-Provider-Requests', '0')
            ->assertHeader('X-Context-Sources-Queried', 'native_memory');
        Http::assertNothingSent();
    }

    public function test_model_permission_does_not_override_source_disclosure_or_accept_an_arbitrary_url(): void
    {
        [$owner] = $this->setupApplication();
        $this->actingAs($owner, 'web');
        foreach ([$this->model(), array_replace($this->model(), ['destination' => 'https://model.example/path?token=secret'])] as $model) {
            $this->postJson('/api/context/applications', ['name' => 'Invalid grant', 'capabilities' => ['context.resolve'], 'model_disclosure' => $model])
                ->assertUnprocessable();
        }
    }

    public function test_application_cannot_grant_itself_model_authority_or_use_a_foreign_owner_registration(): void
    {
        [, , $registration] = $this->setupApplication();
        $this->putJson('/api/context/applications/'.$registration['application']['id'].'/grants', [
            'grant_revision' => 1, 'capabilities' => ['context.resolve', 'memory.read', 'memory.disclose'], 'model_disclosure' => $this->model(),
        ])->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'web');
        $this->putJson('/api/context/applications/'.$registration['application']['id'].'/grants', [
            'grant_revision' => 1, 'capabilities' => ['context.resolve', 'memory.read', 'memory.disclose'], 'model_disclosure' => $this->model(),
        ])->assertNotFound();
    }

    public function test_preflight_observes_changed_model_permission_and_revoked_application(): void
    {
        [$owner, , $registration] = $this->setupApplication($this->model());
        $this->resolve()->assertOk();
        app(ContextApplications::class)->change($owner, $registration['application']['id'], [
            'grant_revision' => 1, 'capabilities' => ['context.resolve', 'memory.read', 'memory.disclose'], 'model_disclosure' => null,
        ]);
        $this->getJson('/api/app/context/permissions')->assertOk()->assertJsonPath('grant_revision', 2)->assertJsonPath('model_disclosure', null);
        app(ContextApplications::class)->revoke($owner, $registration['application']['id']);
        $this->getJson('/api/app/context/permissions')->assertUnauthorized();
    }

    public function test_forged_application_cannot_inspect_permissions(): void
    {
        $this->withHeader('Authorization', 'Bearer omctx_'.str_repeat('0', 64))
            ->getJson('/api/app/context/permissions')->assertUnauthorized();
    }

    public function test_revocation_during_permission_inspection_fails_closed(): void
    {
        [, , $registration] = $this->setupApplication($this->model());
        $audit = Mockery::mock(\App\Services\Context\ContextAudit::class);
        $audit->shouldReceive('record')->once()->andReturnUsing(function () use ($registration) {
            ContextApplication::whereKey($registration['application']['id'])->update(['revoked_at' => now()]);
        });
        $this->app->instance(\App\Services\Context\ContextAudit::class, $audit);
        $this->getJson('/api/app/context/permissions')->assertForbidden()->assertDontSee('model.example');
    }

    public function test_deletion_after_initial_freshness_check_is_caught_by_final_batch(): void
    {
        [, $memory] = $this->setupApplication();
        $redactor = Mockery::mock(RedactionService::class);
        $real = new RedactionService;
        $redactor->shouldReceive('redact')->andReturnUsing(function (...$arguments) use ($real, $memory) {
            $memory->delete();

            return $real->redact(...$arguments);
        });
        $this->app->instance(RedactionService::class, $redactor);
        $this->resolve()->assertOk()->assertJsonCount(0, 'fragments')->assertJsonPath('sources.native_memory.status', 'search_incomplete');
    }

    public function test_application_revocation_during_normalization_prevents_bundle_return(): void
    {
        [, , $registration] = $this->setupApplication($this->model());
        $redactor = Mockery::mock(RedactionService::class);
        $real = new RedactionService;
        $redactor->shouldReceive('redact')->andReturnUsing(function (...$arguments) use ($real, $registration) {
            ContextApplication::whereKey($registration['application']['id'])->update(['revoked_at' => now()]);

            return $real->redact(...$arguments);
        });
        $this->app->instance(RedactionService::class, $redactor);
        $this->resolve()->assertForbidden()->assertDontSee('portable ledger');
    }
}
