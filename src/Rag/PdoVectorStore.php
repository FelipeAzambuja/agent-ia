<?php

namespace AgentIa\Rag;

/**
 * VectorStore over PDO (MySQL, PostgreSQL, SQLite...). Vectors are
 * stored as JSON and cosine similarity is computed in PHP after
 * loading the namespace — simple and portable, adequate for small
 * and medium corpora (up to a few thousand chunks). For larger bases,
 * implement VectorStore over pgvector or a dedicated vector database.
 *
 * Table (created by migrate(), or manually):
 *
 *   CREATE TABLE agent_rag_chunks (
 *       id         BIGINT AUTO_INCREMENT PRIMARY KEY,
 *       namespace  VARCHAR(191) NOT NULL,
 *       content    TEXT         NOT NULL,
 *       embedding  TEXT         NOT NULL,  -- JSON array of floats
 *       metadata   TEXT         NOT NULL,  -- JSON object
 *       created_at DATETIME     NOT NULL
 *   );
 *   CREATE INDEX idx_agent_rag_namespace ON agent_rag_chunks (namespace, id);
 *
 * (On PostgreSQL, replace BIGINT AUTO_INCREMENT with BIGSERIAL and DATETIME with TIMESTAMP.)
 * (The column is "embedding", not "vector": VECTOR is a reserved word in MariaDB 11.7+.)
 */
class PdoVectorStore implements VectorStore
{
    public function __construct(private \PDO $pdo, private string $table = 'agent_rag_chunks') {}

    /** Creates the table when it does not exist (driver-aware). */
    public function migrate(): void
    {
        $driver = (string) $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $sql = match ($driver) {
            'sqlite' => "CREATE TABLE IF NOT EXISTS {$this->table} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                namespace TEXT NOT NULL,
                content TEXT NOT NULL,
                embedding TEXT NOT NULL,
                metadata TEXT NOT NULL,
                created_at TEXT NOT NULL
            )",
            'pgsql' => "CREATE TABLE IF NOT EXISTS {$this->table} (
                id BIGSERIAL PRIMARY KEY,
                namespace VARCHAR(191) NOT NULL,
                content TEXT NOT NULL,
                embedding TEXT NOT NULL,
                metadata TEXT NOT NULL,
                created_at TIMESTAMP NOT NULL
            )",
            default => "CREATE TABLE IF NOT EXISTS {$this->table} (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                namespace VARCHAR(191) NOT NULL,
                content TEXT NOT NULL,
                embedding TEXT NOT NULL,
                metadata TEXT NOT NULL,
                created_at DATETIME NOT NULL
            )",
        };
        $this->pdo->exec($sql);
    }

    public function upsert(string $namespace, array $chunks): void
    {
        if ($chunks === []) return;
        $now = date('Y-m-d H:i:s');
        $st = $this->pdo->prepare(
            "INSERT INTO {$this->table} (namespace, content, embedding, metadata, created_at) VALUES (?, ?, ?, ?, ?)",
        );
        foreach ($chunks as $chunk) {
            $st->execute([
                $namespace,
                (string) $chunk['text'],
                json_encode(array_map('floatval', $chunk['vector'])),
                json_encode((object) ($chunk['metadata'] ?? []), JSON_UNESCAPED_UNICODE),
                $now,
            ]);
        }
    }

    public function similar(string $namespace, array $queryVector, int $limit): array
    {
        $st = $this->pdo->prepare("SELECT content, embedding, metadata FROM {$this->table} WHERE namespace = ?");
        $st->execute([$namespace]);
        $scored = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $vector = json_decode((string) $row['embedding'], true);
            if (!is_array($vector)) continue;
            $scored[] = [
                'text' => (string) $row['content'],
                'score' => self::cosine($queryVector, array_map('floatval', $vector)),
                'metadata' => json_decode((string) $row['metadata'], true) ?: [],
            ];
        }
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($scored, 0, max(1, $limit));
    }

    public function count(string $namespace): int
    {
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->table} WHERE namespace = ?");
        $st->execute([$namespace]);
        return (int) $st->fetchColumn();
    }

    /** Cosine of the angle between two vectors (0 when either is empty). */
    public static function cosine(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n === 0) return 0.0;
        $dot = 0.0; $na = 0.0; $nb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na += $a[$i] * $a[$i];
            $nb += $b[$i] * $b[$i];
        }
        if ($na == 0.0 || $nb == 0.0) return 0.0;
        return $dot / (sqrt($na) * sqrt($nb));
    }
}
