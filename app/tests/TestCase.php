<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function withOwnerSession(array $data): static
    {
        $ownerKey = $data['chat_user_id'];
        $binding = \App\Models\CorpusOwnerBinding::where('owner_key', $ownerKey)->first();
        $user = $binding
            ? \App\Models\User::findOrFail($binding->user_id)
            : \App\Models\User::factory()->create();
        if (! $binding) {
            \App\Models\CorpusOwnerBinding::where('user_id', $user->id)->update(['owner_key' => $ownerKey]);
        }
        $this->actingAs($user, 'web');

        return $this->withSession(array_merge($data, [
            'authenticated_owner_context' => $user->id.':'.$ownerKey,
            'identity_source' => 'openmemory',
        ]));
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Feature tests assert Inertia responses, not compiled frontend assets.
        $this->withoutVite();
        config(['disclosure.ingest_publication' => true]);
        // Legacy functionality tests opt into their synthetic model workflows.
        // Disclosure boundary tests explicitly revoke these fixture grants.
        config(['disclosure.model_operations' => ['chat', 'history_ask', 'public_extraction',
            'document_processing', 'ingestion', 'consolidation', 'benchmark']]);
    }
}
