# Agente IA

Agente de IA genérico em PHP puro: histórico por sessão, memória plugável, tools (function calling), arquivos multimodais e **subagentes com handoff estruturado**. Sem dependência de framework — funciona com CodeIgniter, Laravel, script puro, qualquer coisa que rode PHP 8.2+.

## Instalação

```bash
composer require felipeazambuja/agente-ia
```

O cliente de chat é o [cheinisch/openrouter-php-client](https://packagist.org/packages/cheinisch/openrouter-php-client) (OpenRouter: centenas de modelos, uma chave). Qualquer cliente que implemente `AgenteIa\ChatClient` serve — inclusive um fake em testes.

## Uso básico

```php
use AgenteIa\Agente;
use cheinisch\OpenRouterClient;

$cliente = new OpenRouterClient(getenv('OPENROUTER_KEY'));

$agente = new Agente($cliente);
$agente->setInstrucao('Você é um assistente de atendimento. Responda em português do Brasil.');
$resposta = $agente->responder('Olá, preciso de ajuda.');
```

Com arquivos (imagem, PDF, texto — caminho local ou URL):

```php
$resposta = $agente->responder('Analise este gráfico', ['/tmp/grafico.png']);
```

## Tools (function calling)

```php
$agente->setTools([
    'hora_atual' => [
        'descricao' => 'Retorna a data e hora atuais do servidor',
        'parametros' => ['type' => 'object', 'properties' => []],
        'executar' => fn () => date('d/m/Y H:i'),
    ],
]);
```

O agente executa o loop de tools sozinho (limite de 10 rodadas, com verificação leve de continuidade antes de desistir). Erro em uma tool **volta ao modelo como resultado** — ele tenta outra abordagem no mesmo turno em vez de derrubar a conversa.

## Subagentes e orquestração (agent as tool)

Um `Agente` pode ter subagentes, registrados como tools. O orquestrador roteia o assunto pela descrição e delega:

```php
$orquestrador = new Agente($cliente);
$orquestrador->setInstrucao('Você é o atendente principal...');

$visitas = (new Agente($cliente))->setName('Clovis')->setInstrucao('Você atende só agendamento de visitas...');
$orquestrador->addSubAgente($visitas, 'Atende chamadas de assunto de agendamento de visitas');
$orquestrador->addSubAgente(
    (new Agente($cliente))->setInstrucao('Você atende só financiamento...'),
    'Atende chamadas de assunto de financiamento',
);

$resposta = $orquestrador->responder('Quero agendar uma visita.');
```

Protocolo de delegação:

1. **Handoff obrigatório** — ao acionar um subagente, o modelo informa `resumo`, `conseguiu_atender`, `nao_conseguiu_atender` e `espera_que_atenda` (campos exigidos pelo schema da tool). O subagente recebe a transcrição completa da conversa + o handoff.
2. **Status avaliado** — um modelo leve avalia se o subagente atendeu ao esperado; o status volta no resultado da tool.
3. **Fallback em cadeia** — no fracasso, o orquestrador tenta o próximo subagente possível; se nenhum conseguir, responde por conta própria.

O nome da tool é o nome definido via `setName()` (normalizado para `[a-z0-9_]`) ou `subagente_N` automático. Prefira subagentes **sem backend de memória** (stateless): o contexto chega completo a cada acionamento.

## Memória

Sem backend, o histórico vive só no processo. Para persistir entre requisições, injete um backend:

```php
$agente->session_id = 'atendimento-123';

// Cache PSR-16 (histórico expira pelo TTL)
$agente->memoria = new AgenteIa\Memory\Psr16MemoryBackend($psr16Cache);

// Banco via PDO (MySQL, PostgreSQL, SQLite...)
$agente->memoria = new AgenteIa\Memory\PdoMemoryBackend($pdo);

// Memória de longo prazo por tópicos: um modelo leve decide, pós-turno,
// criar/atualizar tópicos por assunto; outro escolhe os relevantes por turno
$agente->memoriaTopicos = new AgenteIa\Memory\PdoTopicsBackend($pdo);
```

`session_id` é obrigatório quando há backend. Para implementar um backend próprio, implemente `AgenteIa\Memory\MemoryBackend` e/ou `AgenteIa\Memory\TopicsBackend`.

Schemas das tabelas (documentados também nos docblocks das classes PDO):

```sql
CREATE TABLE agente_memorias (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(191) NOT NULL,
    papel VARCHAR(16) NOT NULL,
    conteudo TEXT NOT NULL,
    created_at DATETIME NOT NULL
);
CREATE INDEX idx_agente_memorias_sessao ON agente_memorias (session_id, id);

CREATE TABLE agente_topicos (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(191) NOT NULL,
    descricao TEXT NOT NULL,
    mensagens TEXT NOT NULL,  -- JSON array de strings
    anotacoes TEXT NOT NULL,  -- JSON array de strings
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);
CREATE INDEX idx_agente_topicos_sessao ON agente_topicos (session_id, id);
```

## Logging

Opcional, PSR-3:

```php
$agente = new Agente($cliente, $psr3Logger);
```

Falhas internas (avaliação de status, seleção de tópicos, gerência de memória, erro de tool) são logadas e nunca derrubam o turno.

## Testes

```bash
composer install
composer test
```

Todos os testes são offline: o `FakeChatClient` scripta as respostas do LLM, então nada chama a rede nem consome créditos.

## Licença

MIT
