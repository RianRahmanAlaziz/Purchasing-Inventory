<?php

declare(strict_types=1);

namespace App\Domain\Authorization\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class RbacAuditLogger
{
    /** @param array<string, mixed>|null $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        User $actor,
        Request $request,
        string $event,
        string $subjectType,
        int|string $subjectId,
        ?array $before,
        ?array $after
    ): void {
        DB::table('rbac_audit_logs')->insert([
            'actor_user_id' => $actor->getKey(),
            'event' => $event,
            'subject_type' => $subjectType,
            'subject_id' => (string) $subjectId,
            'before_data' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_data' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
            'created_at' => now(),
        ]);
    }
}
