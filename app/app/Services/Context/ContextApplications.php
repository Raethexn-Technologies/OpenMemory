<?php

namespace App\Services\Context;

use App\Models\ContextApplication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ContextApplications
{
    public function __construct(private readonly ContextAudit $audit) {}

    public function create(User $owner, array $input): array
    {
        ContextInput::validate($input, [
            'name' => 'required|string|max:80',
            'capabilities' => 'present|array|list|max:5',
            'capabilities.*' => 'string|distinct|in:'.implode(',', ContextPolicy::CAPABILITIES),
            'expires_in_days' => 'sometimes|integer|min:1|max:365',
        ]);

        return DB::transaction(function () use ($owner, $input) {
            $token = 'omctx_'.bin2hex(random_bytes(32));
            $app = new ContextApplication([
                'name' => $input['name'], 'token_hash' => hash('sha256', $token),
                'capabilities' => $input['capabilities'], 'grant_revision' => 1,
                'expires_at' => now()->addDays((int) ($input['expires_in_days'] ?? 30)),
            ]);
            $app->owner_id = $owner->id;
            $app->save();
            $this->audit->record(new ContextCaller($owner, $app->id), (string) Str::uuid(), 'application.create', 'allowed');

            return ['application' => $app->summary(), 'token' => $token];
        });
    }

    public function change(User $owner, string $id, array $input): array
    {
        ContextInput::validate($input, [
            'grant_revision' => 'required|integer|min:1|max:2147483646',
            'capabilities' => 'present|array|list|max:5',
            'capabilities.*' => 'string|distinct|in:'.implode(',', ContextPolicy::CAPABILITIES),
        ]);

        return DB::transaction(function () use ($owner, $id, $input) {
            $app = ContextApplication::where('owner_id', $owner->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if($app->revoked_at !== null || $app->grant_revision !== (int) $input['grant_revision'], 409);
            $app->capabilities = $input['capabilities'];
            $app->grant_revision++;
            $app->save();
            $this->audit->record(new ContextCaller($owner, $id), (string) Str::uuid(), 'application.grants', 'allowed');

            return $app->summary();
        });
    }

    public function revoke(User $owner, string $id): void
    {
        DB::transaction(function () use ($owner, $id) {
            $app = ContextApplication::where('owner_id', $owner->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            $app->revoked_at = now();
            $app->save();
            $this->audit->record(new ContextCaller($owner, $id), (string) Str::uuid(), 'application.revoke', 'allowed');
        });
    }
}
