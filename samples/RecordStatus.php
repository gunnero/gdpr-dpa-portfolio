<?php
declare(strict_types=1);

namespace Portfolio;

use PDO;
use Throwable;
use InvalidArgumentException;
use RuntimeException;

/**
 * Standalone adaptation of the application's transactional record-status workflow.
 * Uses generic demo tables, an explicit actor, and a simplified audit event.
 * Authentication, authorization and CSRF checks belong at the calling boundary;
 * this sample is not a web endpoint or a complete access-control implementation.
 * The caller must not already have an active transaction.
 */
function set_record_active(PDO $pdo, int $recordId, bool $active, int $actorId): void
{
    if ($recordId <= 0 || $actorId <= 0) {
        throw new InvalidArgumentException('Positive record and actor IDs are required.');
    }

    $pdo->beginTransaction();
    try {
        // The demo requires the parent row to exist. Unlike the original integration,
        // absence is reported explicitly instead of being handled by the caller.
        $check = $pdo->prepare('SELECT id FROM records WHERE id = :id');
        $check->execute([':id' => $recordId]);
        if ($check->fetchColumn() === false) {
            throw new RuntimeException('Record not found.');
        }

        $value = $active ? 1 : 0;
        $record = $pdo->prepare('UPDATE records SET is_active = :active WHERE id = :id');
        $record->execute([':active' => $value, ':id' => $recordId]);

        $audit = $pdo->prepare(
            'INSERT INTO audit_events (record_id, actor_id, event_type) VALUES (:record, :actor, :event)'
        );
        $audit->execute([
            ':record' => $recordId,
            ':actor' => $actorId,
            ':event' => $active ? 'reactivated' : 'deactivated',
        ]);

        $documents = $pdo->prepare('UPDATE documents SET is_active = :active WHERE record_id = :id');
        $documents->execute([':active' => $value, ':id' => $recordId]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}
