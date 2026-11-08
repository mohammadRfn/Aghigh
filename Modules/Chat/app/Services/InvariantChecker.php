<?php

declare(strict_types=1);

namespace Modules\Chat\Services;

use Illuminate\Support\Facades\DB;
use Modules\Chat\State\ChatState;

/** Chat invariants for chat:verify-invariants (Task 10 after-run). Every check is a set-based query. */
final class InvariantChecker
{
    public function __construct(private readonly ChatState $state) {}

    /** @return list<string> "rule:conversation_id" */
    public function violations(bool $checkQueue = true, int $chunk = 500): array
    {
        $violations = [];

        // seq contiguous 1..last_seq: unique (conversation_id, seq) + count = max = last_seq + min = 1
        $rows = DB::select(<<<'SQL'
            SELECT c.id FROM conversations c
            LEFT JOIN (SELECT conversation_id, COUNT(*) AS n, MIN(seq) AS mn, MAX(seq) AS mx FROM messages GROUP BY conversation_id) m
              ON m.conversation_id = c.id
            WHERE COALESCE(m.n, 0) <> c.last_seq OR (c.last_seq > 0 AND (m.mn <> 1 OR m.mx <> c.last_seq))
            SQL);
        foreach ($rows as $row) {
            $violations[] = 'seq-not-contiguous:'.$row->id;
        }

        foreach (DB::select("SELECT id FROM conversations WHERE status = 'claimed' AND assigned_agent_id IS NULL") as $row) {
            $violations[] = 'claimed-without-agent:'.$row->id;
        }

        $rows = DB::select(<<<'SQL'
            SELECT c.id FROM conversations c
            WHERE c.status = 'claimed' AND (
                SELECT COUNT(*) FROM assignments a
                WHERE a.conversation_id = c.id AND a.released_at IS NULL AND a.agent_id = c.assigned_agent_id
            ) <> 1
            SQL);
        foreach ($rows as $row) {
            $violations[] = 'claimed-without-one-open-assignment:'.$row->id;
        }

        $rows = DB::select(<<<'SQL'
            SELECT DISTINCT c.id FROM conversations c JOIN assignments a ON a.conversation_id = c.id
            WHERE c.status <> 'claimed' AND a.released_at IS NULL
            SQL);
        foreach ($rows as $row) {
            $violations[] = 'open-assignment-on-unclaimed:'.$row->id;
        }

        foreach (DB::select("SELECT customer_id FROM conversations WHERE status <> 'closed' GROUP BY customer_id HAVING COUNT(*) > 1") as $row) {
            $violations[] = 'customer-with-several-open-conversations:'.$row->customer_id;
        }

        if ($checkQueue) {
            DB::table('conversations')->where('status', 'waiting')->select(['id'])->orderBy('id')
                ->chunkById(max(1, $chunk), function ($rows) use (&$violations): void {
                    foreach ($rows as $row) {
                        if ($this->state->queueScore((string) $row->id) === null) {
                            $violations[] = 'waiting-not-in-queue:'.$row->id;
                        }
                    }
                });
            $offset = 0;
            while (($members = $this->state->queueMembers($offset, $chunk)) !== []) {
                $waiting = DB::table('conversations')->whereIn('id', $members)->where('status', 'waiting')->pluck('id')
                    ->map(fn (mixed $id): string => (string) $id)->all();
                foreach (array_diff($members, $waiting) as $member) {
                    $violations[] = 'queued-but-not-waiting:'.$member;
                }
                $offset += count($members);
            }
        }

        return $violations;
    }
}
