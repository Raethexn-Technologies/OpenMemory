<?php

namespace App\Services\NativeMemory;

use App\Models\NativeMemory;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NativeMemoryService
{
    public const FORMAT = 'openmemory-export-v1';

    private function owned(User $owner): Builder
    {
        abort_unless($owner->exists, 403);

        return NativeMemory::where('owner_id', $owner->getKey());
    }

    private function locked(User $owner, callable $operation): mixed
    {
        return DB::transaction(function () use ($owner, $operation) {
            // Serialize owner mutations, including imports and replacement links.
            User::whereKey($owner->getKey())->lockForUpdate()->firstOrFail();

            return $operation();
        }, 3);
    }

    public function get(User $owner, string $id): NativeMemory
    {
        return $this->owned($owner)->where('memory_id', $id)->firstOrFail();
    }

    public function listing(User $owner, array $filters = [], bool $export = false): array
    {
        MemoryInput::validate($filters, [
            'state' => 'sometimes|in:active,archived,superseded,all',
            'q' => 'sometimes|nullable|string|max:200',
            'after' => 'sometimes|nullable|uuid',
            'limit' => 'sometimes|integer|min:1|max:1000',
        ]);
        $limit = (int) ($filters['limit'] ?? 100);
        $query = $this->owned($owner)->orderBy('memory_id');
        $state = $export ? 'all' : ($filters['state'] ?? 'active');
        if ($state !== 'all') {
            $query->where('state', $state);
        }
        if (! empty($filters['after'])) {
            $query->where('memory_id', '>', $filters['after']);
        }
        if (! $export && isset($filters['q']) && $filters['q'] !== '') {
            $literal = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['q']);
            $query->whereRaw("content LIKE ? ESCAPE '!'", ['%'.$literal.'%']);
        }
        $rows = $query->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);
        if ($export) {
            foreach ($rows as $row) {
                MemoryInput::content($row->content);
            }
        }

        return [
            'data' => $rows->map(fn (NativeMemory $row) => $row->portable())->values()->all(),
            'next_cursor' => $more ? $rows->last()->memory_id : null,
        ];
    }

    public function create(User $owner, array $input): NativeMemory
    {
        MemoryInput::validate($input, ['content' => 'required|string|max:8000']);
        MemoryInput::content($input['content']);

        return $this->locked($owner, fn () => $this->insert($owner, $input['content']));
    }

    private function insert(User $owner, string $content): NativeMemory
    {
        $memory = new NativeMemory([
            'memory_id' => (string) Str::uuid(),
            'content' => $content,
            'attribution' => 'user_asserted',
            'state' => 'active',
            'revision' => 1,
        ]);
        $memory->owner_id = $owner->getKey();
        $memory->save();

        return $memory;
    }

    private function current(User $owner, string $id, int $revision): NativeMemory
    {
        $memory = $this->get($owner, $id);
        abort_unless($memory->revision === $revision, 409);

        return $memory;
    }

    public function update(User $owner, string $id, array $input): NativeMemory
    {
        MemoryInput::validate($input, [
            'revision' => 'required|integer',
            'content' => 'sometimes|required|string|max:8000',
            'state' => 'sometimes|required|in:active,archived',
        ]);
        MemoryInput::revision($input['revision']);
        if (! isset($input['content']) && ! isset($input['state'])) {
            MemoryInput::invalid();
        }
        if (isset($input['content'])) {
            MemoryInput::content($input['content']);
        }

        return $this->locked($owner, function () use ($owner, $id, $input) {
            $memory = $this->current($owner, $id, $input['revision']);
            abort_if($memory->state === 'superseded' || $memory->revision === 2147483647, 409);
            $memory->fill(array_intersect_key($input, array_flip(['content', 'state'])));
            $memory->revision++;
            $memory->save();

            return $memory;
        });
    }

    public function supersede(User $owner, string $id, array $input): NativeMemory
    {
        MemoryInput::validate($input, ['revision' => 'required|integer', 'content' => 'required|string|max:8000']);
        MemoryInput::revision($input['revision']);
        MemoryInput::content($input['content']);

        return $this->locked($owner, function () use ($owner, $id, $input) {
            $old = $this->current($owner, $id, $input['revision']);
            abort_if($old->state === 'superseded' || $old->revision === 2147483647, 409);
            $new = $this->insert($owner, $input['content']);
            $old->state = 'superseded';
            $old->superseded_by = $new->memory_id;
            $old->revision++;
            $old->save();

            return $new;
        });
    }

    public function delete(User $owner, string $id, array $input): void
    {
        MemoryInput::validate($input, ['revision' => 'required|integer']);
        MemoryInput::revision($input['revision']);
        $this->locked($owner, function () use ($owner, $id, $input) {
            $memory = $this->current($owner, $id, $input['revision']);
            $this->owned($owner)->where('superseded_by', $id)->update([
                'superseded_by' => null,
                'revision' => DB::raw('CASE WHEN revision < 2147483647 THEN revision + 1 ELSE revision END'),
                'updated_at' => now(),
            ]);
            $memory->delete();
        });
    }

    public function export(User $owner, array $filters): array
    {
        MemoryInput::validate($filters, [
            'after' => 'sometimes|nullable|uuid',
            'limit' => 'sometimes|integer|min:1|max:1000',
        ]);
        $page = $this->listing($owner, $filters, true);
        $records = [];
        $bytes = 0;
        foreach ($page['data'] as $record) {
            // Leave space for the envelope and stricter JSON escaping by clients.
            $size = strlen(json_encode($record, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR)) + 1;
            if ($bytes + $size > 8 * 1024 * 1024) {
                $page['next_cursor'] = $records[array_key_last($records)]['id'];
                break;
            }
            $records[] = $record;
            $bytes += $size;
        }

        return [
            'format' => self::FORMAT,
            'exported_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'native_memories' => $records,
            'external_references' => [],
            'next_cursor' => $page['next_cursor'],
        ];
    }

    public function import(User $owner, array $input): array
    {
        MemoryInput::validate($input, [
            'format' => 'required|in:'.self::FORMAT,
            'exported_at' => 'required|date_format:Y-m-d\TH:i:s\Z',
            'native_memories' => 'present|array|list|max:1000',
            'external_references' => 'present|array|size:0',
            'next_cursor' => 'present|nullable|uuid',
        ]);
        $records = [];
        foreach ($input['native_memories'] as $record) {
            if (! is_array($record)) {
                MemoryInput::invalid();
            }
            $this->validateRecord($record);
            if (isset($records[$record['id']])) {
                MemoryInput::invalid();
            }
            $records[$record['id']] = $record;
        }

        return $this->locked($owner, function () use ($owner, $records) {
            $existing = $this->owned($owner)->whereIn('memory_id', array_keys($records))
                ->get()->keyBy('memory_id');
            $new = [];
            $skipped = 0;
            foreach ($records as $id => $record) {
                if (isset($existing[$id])) {
                    foreach ($existing[$id]->portable() as $field => $value) {
                        abort_unless($value === $record[$field], 409);
                    }
                    $skipped++;
                } else {
                    $new[$id] = $record;
                }
            }
            $links = $this->owned($owner)->whereNotNull('superseded_by')
                ->pluck('superseded_by', 'memory_id')->all();
            foreach ($new as $id => $record) {
                $links[$id] = $record['superseded_by'];
            }
            foreach (array_keys($new) as $id) {
                $seen = [];
                while (isset($links[$id])) {
                    if (isset($seen[$id])) {
                        MemoryInput::invalid();
                    }
                    $seen[$id] = true;
                    $id = $links[$id];
                }
            }
            foreach ($new as $record) {
                $memory = new NativeMemory;
                $memory->owner_id = $owner->getKey();
                $memory->memory_id = $record['id'];
                $memory->fill(array_diff_key($record, ['id' => true]));
                $memory->timestamps = false;
                $memory->save();
            }

            return ['imported' => count($new), 'skipped' => $skipped];
        });
    }

    private function validateRecord(array $record): void
    {
        MemoryInput::validate($record, [
            'id' => ['required', 'uuid', 'regex:/^[0-9a-f-]{36}$/'],
            'content' => 'required|string|max:8000',
            'attribution' => 'required|in:user_asserted',
            'state' => 'required|in:active,archived,superseded',
            'superseded_by' => ['present', 'nullable', 'uuid', 'regex:/^[0-9a-f-]{36}$/'],
            'revision' => 'required|integer',
            'created_at' => 'required|date_format:Y-m-d\TH:i:s\Z|after_or_equal:1970-01-01|before_or_equal:now',
            'updated_at' => 'required|date_format:Y-m-d\TH:i:s\Z|after_or_equal:created_at|before_or_equal:now',
        ]);
        MemoryInput::revision($record['revision']);
        MemoryInput::content($record['content']);
        if (($record['state'] !== 'superseded' && $record['superseded_by'] !== null)
            || $record['superseded_by'] === $record['id']) {
            MemoryInput::invalid();
        }
    }
}
