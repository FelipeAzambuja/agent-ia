# Agent IA

Português (pt-BR) | [🇺🇸 English](README.md)

Agente de IA genérico em PHP puro: histórico por sessão, memória plugável, ferramentas (function calling), arquivos multimodais, **subagentes com handoff estruturado** e **RAG autônomo**. Agnóstico de framework — funciona com CodeIgniter, Laravel, scripts puros, qualquer coisa rodando PHP 8.2+.

**O que torna o RAG autônomo:** você registra bases de conhecimento; o próprio agente decide *quando* consultá-las, *qual* fonte usar e *com qual consulta* — tanto um modelo leve (antes do turno) quanto o modelo principal (no meio do turno, via ferramentas) podem disparar a recuperação. Você nunca escreve no código "se o usuário perguntar sobre X, busque em Y".

```
Mensagem do usuário ──► Roteador (modelo leve) ──► "precisa? quais fontes? qual consulta?"
                        │                        │
                        ▼                        ▼
              (não precisa: pula)      Busca no VectorStore (embeddings reais)
                                                 │
                                                 ▼
                              Chunks injetados como bloco de sistema
                                                 │
                                                 ▼
                    Modelo principal ──► também pode chamar ferramentas search_<fonte>
                                   no meio do turno (refinar consulta, outras fontes)
                                                 │
                                                 ▼
                                          Resposta final
```

## Instalação

```bash
composer require felipeazambuja/agent-ia
```

Requisitos: PHP 8.2+. O cliente de chat é o [cheinisch/openrouter-php-client](https://packagist.org/packages/cheinisch/openrouter-php-client) (OpenRouter: centenas de modelos, uma chave) por trás do adaptador embutido `AgentIa\OpenRouterChatClient`. Qualquer cliente que implemente `AgentIa\ChatClient` funciona — incluindo um fake nos testes.

## Uso básico

```php
use AgentIa\Agent;

$agent = new Agent(getenv('OPENROUTER_KEY')); // string da chave, ou uma instância de ChatClient
$agent->setInstruction('Você é um assistente de suporte. Responda em português.');
$response = $agent->respond('Olá, preciso de ajuda.');
```

Com arquivos (imagem, PDF, texto — caminho local ou URL; os arquivos valem apenas para o turno atual):

```php
$response = $agent->respond('Analise este gráfico', ['/tmp/grafico.png']);
$response = $agent->respond('Resuma este contrato', ['https://example.com/contrato.pdf']);
```

Os modelos são configuráveis (padrões exibidos):

```php
$agent->model = 'openai/gpt-6-luna';            // modelo principal: respostas + tool calling
$agent->lightModel = 'google/gemini-2.5-flash'; // modelo leve: decisões internas
$agent->options = ['temperature' => 0.4];       // passado a toda chamada de chat
```

O modelo leve cuida de todas as decisões internas (roteamento RAG, seleção de tópicos, status de delegação, checagem de continuidade) — mantenha-o barato e rápido; ele só precisa de suporte a saída estruturada.

## Ferramentas (function calling)

```php
$agent->setTools([
    'current_time' => [
        'description' => 'Retorna a data e hora atuais do servidor',
        'parameters' => ['type' => 'object', 'properties' => []],
        'execute' => fn () => date('d/m/Y H:i'),
    ],
    'order_status' => [
        'description' => 'Retorna o status de um pedido pelo número',
        'parameters' => [
            'type' => 'object',
            'properties' => ['number' => ['type' => 'string']],
            'required' => ['number'],
        ],
        'execute' => fn (array $args) => buscarStatus($args['number']),
    ],
]);
```

O agente executa o loop de ferramentas sozinho (limite de 10 rodadas, com uma checagem de continuidade leve antes de desistir). Um erro de ferramenta **volta ao modelo como resultado da ferramenta** — ele tenta outra abordagem no mesmo turno em vez de quebrar a conversa.

## Subagentes e orquestração (agente como ferramenta)

Um `Agent` pode ter subagentes, registrados como ferramentas. O orquestrador roteia o assunto pela descrição e delega:

```php
$key = getenv('OPENROUTER_KEY');

$orchestrator = new Agent($key);
$orchestrator->setInstruction('Você é o atendente principal...');

$visitas = (new Agent($key))->setName('Clovis')->setInstruction('Você só atende agendamento de visitas...');
$orchestrator->addSubAgent($visitas, 'Atende ligações sobre agendamento de visitas');
$orchestrator->addSubAgent(
    (new Agent($key))->setInstruction('Você só atende financiamento...'),
    'Atende ligações sobre financiamento',
);

$response = $orchestrator->respond('Quero agendar uma visita.');
```

Protocolo de delegação:

1. **Handoff obrigatório** — ao invocar um subagente, o modelo fornece `summary`, `handled`, `not_handled` e `expected` (campos exigidos pelo schema da ferramenta). O subagente recebe a transcrição completa da conversa + o handoff.
2. **Status avaliado** — um modelo leve avalia se o subagente atendeu à expectativa; o status volta no resultado da ferramenta.
3. **Fallback encadeado** — em caso de falha, o orquestrador tenta o próximo subagente possível; se nenhum funcionar, ele responde por conta própria.

O nome da ferramenta é o nome definido via `setName()` (normalizado para `[a-z0-9_]`) ou um `subagent_N` automático. Prefira subagentes **sem backend de memória** (sem estado): o contexto chega completo a cada invocação.

## Memória

Sem um backend, o histórico vive apenas no processo. Para persisti-lo entre requisições, injete um backend:

```php
$agent->sessionId = 'suporte-123';

// Cache PSR-16 (o histórico expira com o TTL)
$agent->memory = new AgentIa\Memory\Psr16MemoryBackend($cachePsr16);

// Banco de dados via PDO (MySQL, PostgreSQL, SQLite...)
$agent->memory = new AgentIa\Memory\PdoMemoryBackend($pdo);

// Memória de tópicos de longo prazo: um modelo leve decide, pós-turno, se
// cria/atualiza tópicos por assunto; outro escolhe os relevantes por turno
$agent->topicMemory = new AgentIa\Memory\PdoTopicsBackend($pdo);
```

`sessionId` é obrigatório quando um backend é definido. Para implementar seu próprio backend, implemente `AgentIa\Memory\MemoryBackend` e/ou `AgentIa\Memory\TopicsBackend`.

Schemas das tabelas (também documentados nos docblocks das classes PDO):

```sql
CREATE TABLE agent_memories (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(191) NOT NULL,
    role VARCHAR(16) NOT NULL,
    content TEXT NOT NULL,
    created_at DATETIME NOT NULL
);
CREATE INDEX idx_agent_memories_session ON agent_memories (session_id, id);

CREATE TABLE agent_topics (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(191) NOT NULL,
    description TEXT NOT NULL,
    messages TEXT NOT NULL,  -- array JSON de strings
    notes TEXT NOT NULL,     -- array JSON de strings
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);
CREATE INDEX idx_agent_topics_session ON agent_topics (session_id, id);
```

## RAG (recuperação autônoma)

Registre bases de conhecimento com `addRagSource()`. Elas são usadas de duas formas complementares:

1. **Roteamento pré-turno** — um modelo leve decide se a mensagem precisa de recuperação, de quais fontes e com qual consulta (reescrita); os chunks recuperados são injetados como um bloco de sistema antes da resposta principal.
2. **Ferramentas de busca** — toda fonte também é uma ferramenta (`search_<nome>`), então o modelo principal pode buscar de novo no meio do turno: refinar a consulta ou consultar outra fonte. Ele preenche `query`, `limit` opcional (1–20, padrão 5) e `reason` (auditoria da decisão).

Falha de roteamento nunca bloqueia a resposta — o turno simplesmente segue sem RAG (as fontes continuam disponíveis como ferramentas).

### Sem banco de dados (o caminho mais simples)

A fachada `Rag` junta chunker, embeddings e store num objeto só, com dois verbos: `add()` para indexar e `search()` para buscar. Por padrão o store é em memória (`ArrayVectorStore`) — zero configuração, só a chave do OpenRouter:

```php
use AgentIa\Rag\Rag;

$rag = new Rag(
    'manuals',                                        // nome → ferramenta search_manuals
    'Manuais de produtos: garantias, especificações...', // exibida aos dois modelos
    getenv('OPENROUTER_KEY'),                         // chave ou um EmbeddingsClient
);

$rag->add($textoDoManual, ['doc' => 'x1000']);        // chunk + embed + store, na hora
$rag->addFile(__DIR__ . '/docs/manual-x800.txt');     // ou indexe um arquivo direto

$agent->addRag($rag);                                 // pronto: RAG autônomo no agente
```

Não existe etapa de `save()`: cada `add()` já indexa imediatamente os chunks. Ideal para demos, testes, scripts CLI e corpora pequenos dentro de um mesmo processo. Exemplo executável completo: `examples/rag-simple.php` (`ddev exec php examples/rag-simple.php`).

### Migrando para o banco de dados

Como a fachada aceita qualquer `VectorStore`, migrar é passar um store persistente na construção — o resto do código fica igual:

```php
// antes (em memória, o padrão)
$rag = new Rag('manuals', 'Manuais de produtos...', $key);

// depois (persistido: MySQL, PostgreSQL, SQLite...)
$rag = new Rag('manuals', 'Manuais de produtos...', $key, new PdoVectorStore($pdo));
```

Com o banco, a indexação passa a rodar uma vez por documento (ou num cron/webhook quando os docs mudam), em vez de a cada execução.

<details>
<summary>Componentes de baixo nível (controle fino)</summary>

A fachada é açúcar sobre peças independentes, úteis quando você quer controlar cada etapa — chunking customizado, embeddings de outro provedor, store próprio:

```php
use AgentIa\Rag\Chunker;
use AgentIa\Rag\OpenRouterEmbeddings;
use AgentIa\Rag\PdoVectorStore;
use AgentIa\Rag\VectorRagSource;

$embeddings = new OpenRouterEmbeddings(getenv('OPENROUTER_KEY')); // padrão: openai/text-embedding-3-small, 1536 dims
$store = new PdoVectorStore($pdo); // vetores como JSON, cosseno em PHP (corpus pequeno/médio)
$store->migrate();                 // cria a tabela agent_rag_chunks se não existir

$chunks = (new Chunker())->chunk($texto);   // ~800 caracteres, ~100 de sobreposição
$vectors = $embeddings->embed($chunks);     // em lote numa única chamada de API
$store->upsert('manuals', array_map(fn ($i) => [
    'text' => $chunks[$i],
    'vector' => $vectors[$i],
    'metadata' => ['doc' => 'x1000'],
], array_keys($chunks)));

$agent->addRagSource(new VectorRagSource('manuals', 'Manuais de produtos...', $store, $embeddings, 'manuals'));
```

</details>

### Exemplo completo: agente de suporte sobre manuais de produtos

Cenário real (o mesmo exercitado pelos testes de integração): um agente de suporte que responde a partir de manuais de eletrodomésticos indexados no MariaDB, com embeddings reais do OpenRouter.

```php
use AgentIa\Agent;
use AgentIa\Rag\Chunker;
use AgentIa\Rag\OpenRouterEmbeddings;
use AgentIa\Rag\PdoVectorStore;
use AgentIa\Rag\VectorRagSource;

$pdo = new PDO('mysql:host=db;dbname=db;charset=utf8mb4', 'db', 'db', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

// 1. Infraestrutura: embeddings + vector store (um por aplicação)
$embeddings = new OpenRouterEmbeddings(getenv('OPENROUTER_KEY')); // padrão: openai/text-embedding-3-small, 1536 dims
$store = new PdoVectorStore($pdo); // vetores como JSON, cosseno em PHP (corpus pequeno/médio)
$store->migrate();                 // cria a tabela agent_rag_chunks se não existir

// 2. Indexação (rodar uma vez por documento, ou num cron/webhook quando os docs mudam)
function indexDocument(PdoVectorStore $store, OpenRouterEmbeddings $embeddings,
                       string $namespace, string $text, array $metadata = []): void
{
    $chunks = (new Chunker())->chunk($text);         // ~800 caracteres, ~100 de sobreposição
    $vectors = $embeddings->embed($chunks);          // em lote numa única chamada de API
    $store->upsert($namespace, array_map(fn ($i) => [
        'text' => $chunks[$i],
        'vector' => $vectors[$i],
        'metadata' => $metadata,
    ], array_keys($chunks)));
}

indexDocument($store, $embeddings, 'manuals',
    file_get_contents(__DIR__ . '/docs/manual-x1000.txt'),
    ['doc' => 'manual-x1000', 'version' => '2024-11']);
indexDocument($store, $embeddings, 'manuals',
    file_get_contents(__DIR__ . '/docs/manual-x800.txt'),
    ['doc' => 'manual-x800']);

// 3. O agente: registra a fonte e conversa
$agent = new Agent(getenv('OPENROUTER_KEY'));
$agent->setInstruction('Você é um assistente de suporte da Solaris Eletrodomésticos. Responda de forma breve, em português.');
$agent->addRagSource(new VectorRagSource(
    'manuals',                                        // nome → ferramenta search_manuals
    'Manuais de produtos: garantias, especificações, uso e solução de problemas', // exibida aos dois modelos
    $store,
    $embeddings,
    'manuals',                                        // namespace no store
));

echo $agent->respond('Qual é o período de garantia do aspirador X1000?');
// → "O aspirador sem fio X1000 tem três anos de garantia no motor e na bateria."
```

O que acontece nesse turno, por baixo dos panos:

1. O modelo leve roteia: `{needed: true, sources: ["manuals"], query: "período de garantia do aspirador X1000", reason: "pergunta sobre garantia de um produto"}`.
2. O Agent gera o embedding da consulta reescrita (tipo `search_query`), o store ranqueia os chunks por similaridade de cosseno e os melhores são injetados como bloco de sistema (`[score 0.93] ...` por chunk).
3. O modelo principal responde a partir dos chunks injetados. Se os trechos não forem suficientes, ele pode chamar `search_manuals` por conta própria (com sua própria consulta e `reason`) e tentar de novo — tudo no mesmo turno.

### Múltiplas fontes

Registre quantas quiser; o roteador escolhe quais consultar (e pode escolher várias):

```php
$agent->addRagSource(new VectorRagSource('manuals', 'Manuais de produtos...', $store, $embeddings, 'manuals'));
$agent->addRagSource(new VectorRagSource('policies', 'Políticas de reembolso, troca e garantia...', $store, $embeddings, 'policies'));
$agent->addRagSource(new VectorRagSource('faq', 'Perguntas frequentes...', $store, $embeddings, 'faq'));
```

### Backends personalizados

Para corpora maiores, implemente `AgentIa\Rag\VectorStore` sobre pgvector, Qdrant, Meilisearch... e qualquer `AgentIa\Rag\EmbeddingsClient`. Ou dispense vetores completamente com um `AgentIa\Rag\RagSource` personalizado que busque como você quiser — SQL `LIKE`, uma API externa, um buscador:

```php
class ApiRagSource implements AgentIa\Rag\RagSource
{
    public function name(): string { return 'tickets'; }
    public function description(): string { return 'Tickets históricos de suporte deste cliente'; }
    public function search(string $query, int $limit): array
    {
        // Retorne chunks no mesmo formato: text, score, metadata
        return $this->api->searchTickets($query, $limit);
    }
}
$agent->addRagSource(new ApiRagSource());
```

### Schema da tabela

Também no docblock do `PdoVectorStore`; o `migrate()` cria a tabela (ciente do driver: MySQL, PostgreSQL, SQLite).

```sql
CREATE TABLE agent_rag_chunks (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    namespace VARCHAR(191) NOT NULL,
    content TEXT NOT NULL,
    embedding TEXT NOT NULL, -- array JSON de floats
    metadata TEXT NOT NULL,  -- objeto JSON
    created_at DATETIME NOT NULL
);
CREATE INDEX idx_agent_rag_namespace ON agent_rag_chunks (namespace, id);
```

> A coluna é `embedding`, não `vector`: `VECTOR` é palavra reservada no MariaDB 11.7+.

O `PdoVectorStore` carrega o namespace e calcula a similaridade de cosseno em PHP — simples e portável, adequado até alguns milhares de chunks por namespace. Para mais, implemente `VectorStore` sobre pgvector ou um banco vetorial dedicado.

## Logging

Opcional, PSR-3:

```php
$agent = new Agent(getenv('OPENROUTER_KEY'), $loggerPsr3);
```

Falhas internas (roteamento RAG, busca RAG, avaliação de status, seleção de tópicos, gerenciamento de memória, erro de ferramenta) são registradas no log e nunca quebram o turno.

## Testes

```bash
composer install
composer test
```

Os testes unitários são offline: o `FakeChatClient` roteia as respostas do LLM e os componentes de RAG rodam sobre fakes/SQLite em memória, então nada toca na rede nem consome créditos.

### Testes de integração (API real, banco real)

Duas suítes rodam contra infraestrutura real. Elas são puladas quando os pré-requisitos estão ausentes, então a suíte padrão continua segura para rodar offline (útil em CI):

```bash
cp .env.example .env   # depois defina sua chave (https://openrouter.ai/settings/keys)
composer test-integration
```

| Suíte | O que é real | Pré-requisitos |
|---|---|---|
| `tests/OpenRouterTest.php` | Turnos de agente, tool calling e delegação a subagentes contra a API real do OpenRouter | `OPENROUTER_KEY` |
| `tests/RagIntegrationTest.php` | Embeddings reais (`/api/v1/embeddings`), MariaDB real (DDEV), chunking, indexação, recuperação e o loop completo do agente de ponta a ponta | `OPENROUTER_KEY` + banco acessível |

Variáveis de ambiente (carregadas do `.env` por um pequeno parser em `tests/Pest.php`; o ambiente real tem precedência):

| Variável | Finalidade | Padrão |
|---|---|---|
| `OPENROUTER_KEY` | Chave de API para chat e embeddings | — (obrigatória para integração) |
| `OPENROUTER_EMBEDDING_MODEL` | Modelo de embeddings para os testes de RAG | `openai/text-embedding-3-small` |
| `RAG_DB_DSN` | DSN PDO para os testes de RAG | `mysql:host=db;dbname=db;charset=utf8mb4` (DDEV) |
| `RAG_DB_USER` / `RAG_DB_PASSWORD` | Credenciais do banco | `db` / `db` (DDEV) |

Observações:

- Os testes de integração usam a matriz de provedores em `providers()` (`tests/OpenRouterTest.php`), então custam apenas o que esses modelos cobram — atenção aos limites do tier gratuito do OpenRouter (50 requisições/dia sem saldo de crédito, 1000/dia com um). Embeddings custam ~US$ 0,02 por 1M de tokens; uma rodada completa da suíte de RAG é uma fração de centavo.
- Com DDEV: `ddev composer test-integration`. O `.env` faz parte do projeto, montado no container — nenhuma configuração extra de DDEV é necessária. `ddev dotenv set .env --openrouter-key=...` grava a chave sem abrir um editor.
- O `.env` está no gitignore; o `.env.example` é o template versionado.

## Exemplos

Todos os exemplos são scripts CLI executáveis em `examples/`, compartilhando um bootstrap mínimo (`_bootstrap.php`: carrega o `.env`, valida a chave). Rode com DDEV, da raiz do projeto:

```bash
ddev exec php examples/basic.php "Como redefino minha senha?"
```

| Exemplo | O que mostra | Requer |
|---|---|---|
| `basic.php` | O menor agente possível: uma instrução, um turno (mensagem via argv ou chat interativo) | `OPENROUTER_KEY` |
| `tools.php` | Function calling: o modelo chama `current_time` e `order_status` sozinho | `OPENROUTER_KEY` |
| `subagents.php` | Orquestrador delegando a especialistas (visitas, financiamento) com handoff estruturado | `OPENROUTER_KEY` |
| `files.php` | Turno multimodal: analisa imagem, PDF ou texto (caminho ou URL via argv) | `OPENROUTER_KEY` |
| `memory.php` | Memória persistente + tópicos de longo prazo sobre SQLite — rode duas vezes e o agente lembra | `OPENROUTER_KEY` |
| `rag-simple.php` | RAG sem banco: a fachada `Rag` sobre um store em memória | `OPENROUTER_KEY` |
| `rag-index.php` | Lado de indexação do RAG: manuais → chunks + embeddings no MariaDB (roda uma vez, ou num cron) | `OPENROUTER_KEY` + banco |
| `rag-support.php` | Lado de atendimento do RAG: o agente responde a partir do que o `rag-index.php` guardou | `OPENROUTER_KEY` + base indexada |

`rag-index.php` e `rag-support.php` compartilham o `_manuals.php` (uma conexão PDO, os textos dos manuais e uma factory de `Rag`) — o padrão que mantém o script de indexação e o de chat sincronizados em nome, descrição e namespace.

## Referência da API

| Classe / interface | Papel |
|---|---|
| `AgentIa\Agent` | O agente: histórico, ferramentas, subagentes, RAG, memória |
| `AgentIa\ChatClient` | Contrato de chat (`chatEx`, `structuredChat`) |
| `AgentIa\OpenRouterChatClient` | Implementação de referência (cheinisch/openrouter-php-client) |
| `AgentIa\Rag\RagSource` | Uma base de conhecimento pesquisável (nome, descrição, busca) |
| `AgentIa\Rag\Rag` | Fachada de alto nível: `add()` indexa, `search()` busca (chunker + embeddings + store em um) |
| `AgentIa\Rag\VectorRagSource` | RagSource sobre um VectorStore + EmbeddingsClient |
| `AgentIa\Rag\VectorStore` | Armazenamento de chunks + busca por similaridade (upsert, similar, count) |
| `AgentIa\Rag\PdoVectorStore` | VectorStore sobre PDO (vetores JSON, cosseno em PHP) |
| `AgentIa\Rag\ArrayVectorStore` | VectorStore em memória (array PHP, sem banco) |
| `AgentIa\Rag\EmbeddingsClient` | Texto → vetores (`search_document` / `search_query`) |
| `AgentIa\Rag\OpenRouterEmbeddings` | EmbeddingsClient sobre `/api/v1/embeddings` do OpenRouter |
| `AgentIa\Rag\Chunker` | Documento → chunks (tamanho/sobreposição, consciente de frases) |
| `AgentIa\Memory\MemoryBackend` | Histórico conversacional por sessão |
| `AgentIa\Memory\TopicsBackend` | Memória de tópicos de longo prazo |
| `AgentIa\Memory\Psr16MemoryBackend` / `PdoMemoryBackend` / `PdoTopicsBackend` | Backends embutidos |

## Licença

MIT
