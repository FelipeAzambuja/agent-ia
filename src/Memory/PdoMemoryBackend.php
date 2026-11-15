<?php

namespace AgenteIa\Memory;

/**
 * MemoryBackend sobre PDO (MySQL, PostgreSQL, SQLite...). Tabela esperada:
 *
 *   CREATE TABLE agente_memorias (
 *       id         BIGINT AUTO_INCREMENT PRIMARY KEY,
 *       session_id VARCHAR(191) NOT NULL,
 *       papel      VARCHAR(16)  NOT NULL,
 *       conteudo   TEXT         NOT NULL,
 *       created_at DATETIME     NOT NULL
 *   );
 *   CREATE INDEX idx_agente_memorias_sessao ON agente_memorias (session_id, id);
 *
 * (No PostgreSQL, troque BIGINT AUTO_INCREMENT por BIGSERIAL e DATETIME por TIMESTAMP.)
 */
class PdoMemoryBackend implements MemoryBackend
{
    public function __construct(private \PDO $pdo, private string $tabela = 'agente_memorias') {}

    public function carregar(string $sessionId): array
    {
        $sql = "SELECT papel, conteudo FROM {$this->tabela} WHERE session_id = ? ORDER BY id ASC";
        $st = $this->pdo->prepare($sql);
        $st->execute([$sessionId]);
        return array_map(
            fn (array $row) => ['role' => (string) $row['papel'], 'content' => (string) $row['conteudo']],
            $st->fetchAll(\PDO::FETCH_ASSOC),
        );
    }

    public function gravar(string $sessionId, string $papel, string $conteudo): void
    {
        $st = $this->pdo->prepare(
            "INSERT INTO {$this->tabela} (session_id, papel, conteudo, created_at) VALUES (?, ?, ?, ?)",
        );
        $st->execute([$sessionId, $papel, $conteudo, date('Y-m-d H:i:s')]);
    }
}
