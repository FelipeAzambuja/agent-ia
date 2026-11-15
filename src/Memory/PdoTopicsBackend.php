<?php

namespace AgenteIa\Memory;

/**
 * TopicsBackend sobre PDO (MySQL, PostgreSQL, SQLite...). Tabela esperada:
 *
 *   CREATE TABLE agente_topicos (
 *       id         BIGINT AUTO_INCREMENT PRIMARY KEY,
 *       session_id VARCHAR(191) NOT NULL,
 *       descricao  TEXT         NOT NULL,
 *       mensagens  TEXT         NOT NULL,  -- JSON array de strings
 *       anotacoes  TEXT         NOT NULL,  -- JSON array de strings
 *       created_at DATETIME     NOT NULL,
 *       updated_at DATETIME     NOT NULL
 *   );
 *   CREATE INDEX idx_agente_topicos_sessao ON agente_topicos (session_id, id);
 *
 * (No PostgreSQL, troque BIGINT AUTO_INCREMENT por BIGSERIAL e DATETIME por TIMESTAMP.)
 */
class PdoTopicsBackend implements TopicsBackend
{
    public function __construct(private \PDO $pdo, private string $tabela = 'agente_topicos') {}

    public function todos(string $sessionId): array
    {
        $st = $this->pdo->prepare("SELECT id, descricao, mensagens, anotacoes FROM {$this->tabela} WHERE session_id = ? ORDER BY id ASC");
        $st->execute([$sessionId]);
        return array_map(fn (array $row) => [
            'id' => (int) $row['id'],
            'descricao' => (string) $row['descricao'],
            'mensagens' => json_decode((string) $row['mensagens'], true) ?: [],
            'anotacoes' => json_decode((string) $row['anotacoes'], true) ?: [],
        ], $st->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function criar(string $sessionId, string $descricao, array $mensagens, array $anotacoes): void
    {
        $now = date('Y-m-d H:i:s');
        $st = $this->pdo->prepare(
            "INSERT INTO {$this->tabela} (session_id, descricao, mensagens, anotacoes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)",
        );
        $st->execute([
            $sessionId,
            $descricao,
            json_encode($mensagens, JSON_UNESCAPED_UNICODE),
            json_encode($anotacoes, JSON_UNESCAPED_UNICODE),
            $now,
            $now,
        ]);
    }

    public function atualizar(int $id, array $mensagens, array $anotacoes): void
    {
        $st = $this->pdo->prepare("UPDATE {$this->tabela} SET mensagens = ?, anotacoes = ?, updated_at = ? WHERE id = ?");
        $st->execute([
            json_encode($mensagens, JSON_UNESCAPED_UNICODE),
            json_encode($anotacoes, JSON_UNESCAPED_UNICODE),
            date('Y-m-d H:i:s'),
            $id,
        ]);
    }
}
