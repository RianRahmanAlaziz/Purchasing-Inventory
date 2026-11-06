<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Authorization\Services\RbacAuditLogger;
use App\Domain\Authorization\Services\RbacMutationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Authorization\SaveUserRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = User::query()->with(['roles', 'employee:id,user_id']);

        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $query->where(static function (Builder $query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%");
            });
        }
        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $page = $query->orderBy('name')->orderBy('id')->paginate((int) ($filters['per_page'] ?? 15));

        return ApiResponse::success(
            data: $page->getCollection()->map(fn (User $user): array => $this->format($user, $request))->all(),
            message: 'Daftar pengguna berhasil diambil.',
            meta: ['pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ]],
        );
    }

    public function store(SaveUserRequest $request, RbacMutationService $mutation, RbacAuditLogger $audit): JsonResponse
    {
        $user = $this->save($request, $mutation, $audit);

        return ApiResponse::success($this->format($user, $request), 'Pengguna berhasil dibuat.', 201);
    }

    public function update(SaveUserRequest $request, User $user, RbacMutationService $mutation, RbacAuditLogger $audit): JsonResponse
    {
        $user = $this->save($request, $mutation, $audit, $user);

        return ApiResponse::success($this->format($user, $request), 'Pengguna berhasil diperbarui.');
    }

    private function save(SaveUserRequest $request, RbacMutationService $mutation, RbacAuditLogger $audit, ?User $user = null): User
    {
        $actor = $request->user();
        $attributes = $request->validated();
        $roles = Arr::pull($attributes, 'roles', []);
        if (isset($attributes['password'])) {
            $attributes['password'] = Hash::make($attributes['password']);
        }

        try {
            return $mutation->execute($actor, function () use ($actor, $request, $audit, $user, $attributes, $roles): User {
                abort_unless($actor->fresh()?->can('users.manage'), 403);

                if ($user === null) {
                    $created = User::query()->create($attributes + ['is_active' => true]);
                    $created->syncRoles($roles);
                    $audit->record($actor, $request, 'user.created', 'user', $created->id, null, [
                        'is_active' => $created->is_active,
                        'roles' => $created->getRoleNames()->sort()->values()->all(),
                    ]);

                    return $created;
                }

                $target = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
                $before = ['is_active' => $target->is_active];
                $target->fill($attributes);
                $changed = array_keys($target->getDirty());
                if ($changed === []) {
                    return $target;
                }

                $revoke = $target->isDirty(['password', 'email', 'is_active']);
                $target->save();
                if ($revoke) {
                    $target->tokens()->delete();
                }
                $audit->record($actor, $request, 'user.updated', 'user', $target->id, $before, [
                    'is_active' => $target->is_active,
                    'changed_fields' => $changed,
                ]);

                return $target;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => ['Email sudah digunakan.']]);
        }
    }

    /** @return array<string, mixed> */
    private function format(User $user, Request $request): array
    {
        return [
            ...(new UserResource($user))->toArray($request),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
