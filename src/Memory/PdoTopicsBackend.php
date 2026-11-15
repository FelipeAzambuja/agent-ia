<?php

namespace AgentIa\Memory;

/**
 * TopicsBackend over PDO (MySQL, PostgreSQL, SQLite...). Expected table:
 *
 *   CREATE TABLE agent_topics (
 *       id          BIGINT AUTO_INCREMENT PRIMARY KEY,
 *       session_id  VARCHAR(191) NOT NULL,
 *       description TEXT         NOT NULL,
 *       messages    TEXT         NOT NULL,  -- JSON array of strings
 *       notes       TEXT         NOT NULL,  -- JSON array of strings
 *       created_at  DATETIME     NOT NULL,
 *       updated_at  DATETIME     NOT NULL
 *   );
 *   CREATE INDEX idx_agent_topics_session ON agent_topics (session_id, id);
 *
 * (On PostgreSQL, replace BIGINT AUTO_INCREMENT with BIGSERIAL and DATETIME with TIMESTAMP.)
 */
class PdoTopicsBackend implements TopicsBackend
{
    public function __construct(private \PDO $pdo, private string $table = 'agent_topics') {}

    public function all(string $sessionId): array
    {
        $st = $this->pdo->prepare("SELECT id, description, messages, notes FROM {$this->table} WHERE session_id = ? ORDER BY id ASC");
        $st->execute([$sessionId]);
        return array_map(fn (array $row) => [
            'id' => (int) $row['id'],
            'description' => (string) $row['description'],
            'messages' => json_decode((string) $row['messages'], true) ?: [],
            'notes' => json_decode((string) $row['notes'], true) ?: [],
        ], $st->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function create(string $sessionId, string $description, array $messages, array $notes): void
    {
        $now = date('Y-m-d H:i:s');
        $st = $this->pdo->prepare(
            "INSERT INTO {$this->table} (session_id, description, messages, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)",
        );
        $st->execute([
            $sessionId,
            $description,
            json_encode($messages, JSON_UNESCAPED_UNICODE),
            json_encode($notes, JSON_UNESCAPED_UNICODE),
            $now,
            $now,
        ]);
    }

    public function update(int $id, array $messages, array $notes): void
    {
        $st = $this->pdo->prepare("UPDATE {$this->table} SET messages = ?, notes = ?, updated_at = ? WHERE id = ?");
        $st->execute([
            json_encode($messages, JSON_UNESCAPED_UNICODE),
            json_encode($notes, JSON_UNESCAPED_UNICODE),
            date('Y-m-d H:i:s'),
            $id,
        ]);
    }
}
