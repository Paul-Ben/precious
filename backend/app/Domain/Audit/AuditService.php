<?php

namespace App\Domain\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Writes append-only audit records. Secrets (passwords, tokens, keys, codes)
 * are redacted recursively before anything is stored.
 */
class AuditService
{
    public const REDACTED = '[REDACTED]';

    public function __construct(private readonly Request $request) {}

    public function record(
        string $action,
        Model|string|null $subject = null,
        ?array $old = null,
        ?array $new = null,
        array $metadata = [],
        ?User $actor = null,
    ): AuditLog {
        [$type, $id] = $this->describeSubject($subject);

        $actor ??= $this->request->user();

        return AuditLog::create([
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'auditable_type' => $type,
            'auditable_id' => $id,
            'old_values' => $old === null ? null : $this->redact($old),
            'new_values' => $new === null ? null : $this->redact($new),
            'metadata' => $metadata === [] ? null : $this->redact($metadata),
            'ip_address' => $this->request->ip(),
            'user_agent' => Str::limit((string) $this->request->userAgent(), 500, ''),
            'request_id' => $this->request->attributes->get('request_id'),
        ]);
    }

    /**
     * Only the keys that actually changed, for old/new diffs.
     *
     * @return array{0: array, 1: array}
     */
    public function diff(array $before, array $after): array
    {
        $old = [];
        $new = [];

        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            $a = $before[$key] ?? null;
            $b = $after[$key] ?? null;

            if ($a != $b) {
                $old[$key] = $a;
                $new[$key] = $b;
            }
        }

        return [$old, $new];
    }

    public function redact(array $values): array
    {
        $fragments = config('security.audit_redact', []);

        foreach ($values as $key => $value) {
            if (is_string($key) && Str::contains(Str::lower($key), $fragments)) {
                $values[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $values[$key] = $this->redact($value);
            }
        }

        return $values;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function describeSubject(Model|string|null $subject): array
    {
        if ($subject instanceof Model) {
            return [Str::snake(class_basename($subject)), (string) $subject->getKey()];
        }

        return [$subject, null];
    }
}
