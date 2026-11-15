<?php

namespace AgentIa\Memory;

/**
 * MemoryBackend over PDO (MySQL, PostgreSQL, SQLite...). Expected table:
 *
 *   CREATE TABLE agent_memories (
 *       id         BIGINT AUTO_INCREMENT PRIMARY KEY,
 *       session_id VARCHAR(191) NOT NULL,
 *       role       VARCHAR(16)  NOT NULL,
 *       content    TEXT         NOT NULL,
 *       created_at DATETIME     NOT NULL
 *   );
 *   CREATE INDEX idx_agent_memories_session ON agent_memories (session_id, id);
 *
 * (On PostgreSQL, replace BIGINT AUTO_INCREMENT with BIGSERIAL and DATETIME with TIMESTAMP.)
 */
class PdoMemoryBackend implements MemoryBackend
{
    public function __construct(private \PDO $pdo, private string $table = 'agent_memories') {}

    public function load(string $sessionId): array
    {
        $sql = "SELECT role, content FROM {$this->table} WHERE session_id = ? ORDER BY id ASC";
        $st = $this->pdo->prepare($sql);
        $st->execute([$sessionId]);
        return array_map(
            fn (array $row) => ['role' => (string) $row['role'], 'content' => (string) $row['content']],
            $st->fetchAll(\PDO::FETCH_ASSOC),
        );
    }

    public function save(string $sessionId, string $role, string $content): void
    {
        $st = $this->pdo->prepare(
            "INSERT INTO {$this->table} (session_id, role, content, created_at) VALUES (?, ?, ?, ?)",
        );
        $st->execute([$sessionId, $role, $content, date('Y-m-d H:i:s')]);
    }
}
