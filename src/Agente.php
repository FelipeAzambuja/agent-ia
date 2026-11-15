<?php

namespace AgenteIa;

use AgenteIa\Memory\MemoryBackend;
use AgenteIa\Memory\TopicsBackend;
use Psr\Log\LoggerInterface;

/**
 * Agente de IA genérico e simples: histórico por sessão, memória
 * configurável (memória do processo, cache PSR-16, PDO ou tópicos) e
 * tools opcionais (function calling). Não conhece domínio nem canal:
 * quem usa define a instrução, as tools e chama responder() por turno
 * de conversa.
 *
 * Uso:
 *   $agente = new Agente($cliente); // $cliente implementa AgenteIa\ChatClient
 *   $agente->session_id = 'atendimento-123'; // obrigatório com backend de memória
 *   $agente->memoria = new Psr16MemoryBackend($cache); // ou PdoMemoryBackend($pdo)
 *   $agente->memoriaTopicos = new PdoTopicsBackend($pdo); // memória de longo prazo
 *   $agente->setInstrucao('Você é ...');
 *   $agente->setTools(['nome' => ['descricao' => ..., 'parametros' => schema, 'executar' => fn]]);
 *   $resposta = $agente->responder('mensagem do usuário');
 *   $resposta = $agente->responder('analise', ['/tmp/grafico.png', 'https://.../doc.pdf']);
 *
 * Orquestração: um Agente pode ter subagentes, registrados como tools
 * (padrão "agent as tool"). O modelo orquestrador roteia o assunto pela
 * descrição e a tool delega ao responder() do subagente, que recebe a
 * transcrição completa da conversa mais o handoff: resumo, o que o
 * orquestrador já conseguiu atender, o que não conseguiu e o que espera
 * que o subagente atenda (campos obrigatórios no schema da tool). Um
 * modelo leve avalia se o subagente conseguiu; o status volta no
 * resultado da tool e, no fracasso, o orquestrador tenta o próximo
 * subagente possível — se nenhum conseguir, responde por conta própria.
 * Prefira subagentes sem backend de memória (stateless): o contexto
 * chega completo a cada acionamento e memória persistente duplicaria a
 * transcrição. Chame addSubAgente() depois de setTools(), que substitui
 * o array de tools.
 *   $agente->addSubAgente((new Agente($cliente))->setName('Clovis')->setInstrucao('...'), 'Atende chamadas de assunto X');
 */
class Agente
{
    /** Identificador da sessão; obrigatório quando há backend de memória. */
    public string $session_id = '';
    /** Nome da instância; usado como nome da tool quando o agente vira subagente. */
    public string $nome = '';
    /** Modelo principal das respostas. */
    public string $modelo = 'openai/gpt-4o-mini';
    /** Modelo leve das decisões internas (continuidade, tópicos, status de delegação). */
    public string $modeloLeve = 'google/gemini-2.5-flash';
    /** Backend de memória conversacional; null = histórico só no processo. */
    public ?MemoryBackend $memoria = null;
    /** Backend de memória de longo prazo por tópicos; null = desligado. */
    public ?TopicsBackend $memoriaTopicos = null;
    /** Opções extras repassadas ao chatEx (temperature, max_completion_tokens...). */
    public array $opcoes = [];

    private string $instrucao = '';
    /** @var array<string, array{descricao:string,parametros:array,executar:callable}> */
    private array $tools = [];
    /** @var array<int, array{role:string,content:string}> */
    private array $historico = [];
    /** @var array<int, array{id:int,descricao:string,mensagens:string[],anotacoes:string[]}> */
    private array $topicos = [];
    private bool $carregado = false;

    private const MAX_TOOL_RODADAS = 10;
    private const MAX_HISTORICO = 50;

    public function __construct(
        private ChatClient $cliente,
        private ?LoggerInterface $log = null,
    ) {}

    public function setInstrucao(string $instrucao): static
    {
        $this->instrucao = $instrucao;
        return $this;
    }

    /**
     * @param array $tools nome => ['descricao' => string, 'parametros' => schema JSON,
     *                            'executar' => callable(array $args): mixed]
     */
    public function setTools(array $tools): static
    {
        $this->tools = $tools;
        return $this;
    }

    public function setName(string $nome): static
    {
        $this->nome = $nome;
        return $this;
    }

    /**
     * Registra um subagente como tool de function calling (agent as tool).
     * A chamada exige o handoff (resumo, o que o orquestrador conseguiu e
     * não conseguiu atender e o que espera do subagente); o resultado volta
     * com o status avaliado da delegação e a orientação de fallback.
     */
    public function addSubAgente(Agente $agente, string $descricao): static
    {
        $nome = $this->nomeTool($agente->nome) ?: 'subagente_' . (count($this->tools) + 1);
        $this->tools[$nome] = [
            'descricao' => $descricao . ' Ao delegar, preencha o handoff com conteúdo real da conversa: resumo do que o cliente quer, o que você já conseguiu atender, o que não conseguiu e o que espera que o subagente atenda.',
            'parametros' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'resumo' => ['type' => 'string'],
                    'conseguiu_atender' => ['type' => 'string'],
                    'nao_conseguiu_atender' => ['type' => 'string'],
                    'espera_que_atenda' => ['type' => 'string'],
                ],
                'required' => ['resumo', 'conseguiu_atender', 'nao_conseguiu_atender', 'espera_que_atenda'],
            ],
            'executar' => function (array $args) use ($agente): string {
                $resposta = $agente->responder($this->transcrito() . "\n\n" . $this->handoff($args));
                $status = $this->statusDelegacao((string) ($args['espera_que_atenda'] ?? ''), $resposta);
                return $resposta . "\n\n[Status da delegação: o subagente "
                    . ($status ? 'conseguiu atender o que era esperado.' : 'NÃO conseguiu atender o que era esperado. '
                        . 'Se houver outro subagente possível, delegue a ele; se nenhum puder, responda ao usuário por conta própria.') . ']';
            },
        ];
        return $this;
    }

    /** Nome de tool válido ([a-zA-Z0-9_-]): minúsculas, sem acentos, resto vira _. */
    private function nomeTool(string $nome): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower(trim($nome))) ?: '';
        $nome = trim((string) preg_replace('/[^a-z0-9]+/', '_', $ascii), '_');
        return mb_substr($nome, 0, 64);
    }

    /** Histórico da conversa como transcrição textual, para repassar a subagentes. */
    private function transcrito(): string
    {
        $linhas = array_map(fn ($m) => ucfirst($m['role']) . ': ' . $m['content'], $this->historico);
        return "Conversa até agora:\n" . implode("\n", $linhas);
    }

    /** Bloco de handoff enviado ao subagente junto com a transcrição. */
    private function handoff(array $args): string
    {
        return "Handoff do agente orquestrador:\n"
            . 'Resumo: ' . (string) ($args['resumo'] ?? '') . "\n"
            . 'O que conseguiu atender: ' . (string) ($args['conseguiu_atender'] ?? '') . "\n"
            . 'O que não conseguiu atender: ' . (string) ($args['nao_conseguiu_atender'] ?? '') . "\n"
            . 'O que espera que o subagente atenda: ' . (string) ($args['espera_que_atenda'] ?? '');
    }

    /**
     * Modelo leve avalia se a resposta do subagente atendeu ao esperado.
     * Falha da avaliação não trava o fluxo: assume que conseguiu.
     */
    protected function statusDelegacao(string $esperado, string $resposta): bool
    {
        try {
            $decisao = $this->cliente->structuredChat(
                [
                    ['role' => 'system', 'content' => 'Você avalia se a resposta de um subagente atendeu ao que o agente orquestrador esperava ao delegar. Considere atendido quando a resposta resolver ou adiantar concretamente o esperado; marque não apenas quando o subagente claramente não resolveu o pedido.'],
                    ['role' => 'user', 'content' => "Esperado pelo orquestrador:\n{$esperado}\n\nResposta do subagente:\n{$resposta}\n\nO subagente conseguiu atender o que era esperado?"],
                ],
                [
                    'name' => 'status_delegacao',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => ['conseguiu' => ['type' => 'boolean']],
                        'required' => ['conseguiu'],
                    ],
                ],
                $this->modeloLeve,
            );
        } catch (\Throwable $e) {
            $this->log?->error('Agente: falha na avaliação da delegação: {erro}', ['erro' => $e->getMessage()]);
            return true;
        }
        return (bool) ($decisao['conseguiu'] ?? true);
    }

    /** Envia a mensagem do usuário (com arquivos opcionais) e devolve o texto da resposta. */
    public function responder(string $mensagem, array $arquivos = []): string
    {
        $this->carregar();
        if (trim($mensagem) !== '' || $arquivos !== []) {
            $this->historico[] = ['role' => 'user', 'content' => trim($mensagem) !== '' ? $mensagem : '[arquivos anexados]'];
        }

        $mensagens = $this->instrucao !== '' ? [['role' => 'system', 'content' => $this->instrucao]] : [];
        if ($this->memoriaTopicos) {
            $bloco = $this->blocoTopicos($mensagem);
            if ($bloco !== '') $mensagens[] = ['role' => 'system', 'content' => $bloco];
        }
        $mensagens = array_merge($mensagens, array_slice($this->historico, -self::MAX_HISTORICO));
        // Arquivos valem só para o turno atual: a última mensagem do usuário
        // (texto puro no histórico) vira conteúdo multimodal com as partes.
        if ($arquivos !== []) {
            $mensagens[array_key_last($mensagens)]['content'] = $this->partesUsuario($mensagem, $arquivos);
        }

        $rodadas = 0;
        while (true) {
            $resposta = $this->cliente->chatEx($mensagens, $this->modelo, $this->opcoes + $this->opcoesTools());
            $toolCalls = $resposta['tool_calls'] ?? [];
            if ($toolCalls === []) break;
            // Passou do limite: um modelo leve decide se mais uma rodada de
            // tools ainda é necessária; sem aprovação, o loop termina.
            if (++$rodadas > self::MAX_TOOL_RODADAS && !$this->podeContinuar($mensagens, $toolCalls)) break;
            $mensagens[] = ['role' => 'assistant', 'content' => $resposta['content'] ?: null, 'tool_calls' => $toolCalls];
            foreach ($toolCalls as $call) {
                $nome = (string) ($call['function']['name'] ?? '');
                $args = json_decode((string) ($call['function']['arguments'] ?? ''), true) ?: [];
                // Erro de tool volta ao modelo como resultado, para ele tentar
                // outra abordagem no mesmo turno em vez de derrubar a conversa.
                try {
                    $saida = ($this->tools[$nome]['executar'] ?? static fn () => '')($args);
                } catch (\Throwable $e) {
                    $this->log?->error('Agente: tool {nome} falhou: {erro}', ['nome' => $nome, 'erro' => $e->getMessage()]);
                    $saida = 'Erro ao executar a ferramenta ' . $nome . ': ' . $e->getMessage();
                }
                $mensagens[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string) ($call['id'] ?? ''),
                    'name' => $nome,
                    'content' => is_string($saida) ? $saida : json_encode($saida, JSON_UNESCAPED_UNICODE),
                ];
            }
        }

        $texto = trim((string) ($resposta['content'] ?? ''));
        if ($texto === '' && $toolCalls !== []) {
            $this->log?->warning('Agente: loop de tools terminou sem resposta textual (sessão {sessao}).', ['sessao' => $this->session_id]);
        }
        $this->historico[] = ['role' => 'assistant', 'content' => $texto];
        $this->persistir($mensagem, $texto);
        return $texto;
    }

    /** Verificador leve: mais uma rodada de tools ainda é necessária? Falha fecha o loop. */
    private function podeContinuar(array $mensagens, array $toolCalls): bool
    {
        $pedidos = array_map(
            fn ($call) => ($call['function']['name'] ?? '') . '(' . (string) ($call['function']['arguments'] ?? '') . ')',
            $toolCalls,
        );
        try {
            $decisao = $this->cliente->structuredChat(
                [
                    ['role' => 'system', 'content' => 'Você verifica se um agente de conversa precisa executar mais uma rodada de ferramentas antes de responder o usuário. Considere o histórico recente e as ferramentas pedidas na última rodada. Não invente necessidade: se o histórico já basta para responder, continue é falso.'],
                    ['role' => 'user', 'content' => "Histórico recente:\n" . json_encode(array_slice($mensagens, -6), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\nFerramentas pedidas agora:\n" . implode("\n", $pedidos) . "\n\nExecutar essas ferramentas ainda é necessário para responder o usuário?"],
                ],
                [
                    'name' => 'decisao_continuidade',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => ['continuar' => ['type' => 'boolean']],
                        'required' => ['continuar'],
                    ],
                ],
                $this->modeloLeve,
            );
        } catch (\Throwable $e) {
            $this->log?->error('Agente: falha na verificação de continuidade: {erro}', ['erro' => $e->getMessage()]);
            return false;
        }
        return (bool) ($decisao['continuar'] ?? false);
    }

    /**
     * Partes multimodais da mensagem: texto + um arquivo por item de
     * $arquivos (caminho local ou URL http(s)). Imagens viram image_url,
     * PDFs viram file (base64), outros tipos são lidos como texto.
     */
    private function partesUsuario(string $mensagem, array $arquivos): array
    {
        $partes = [];
        if (trim($mensagem) !== '') $partes[] = ['type' => 'text', 'text' => $mensagem];
        foreach ($arquivos as $arquivo) $partes = array_merge($partes, $this->parteArquivo((string) $arquivo));
        return $partes;
    }

    private function parteArquivo(string $arquivo): array
    {
        $ext = strtolower(pathinfo(parse_url($arquivo, PHP_URL_PATH) ?? $arquivo, PATHINFO_EXTENSION));
        $remoto = (bool) preg_match('#^https?://#i', $arquivo);
        $conteudo = file_get_contents($arquivo);
        if ($conteudo === false) throw new \InvalidArgumentException("Arquivo não pôde ser lido: {$arquivo}");
        if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
            $mimes = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];
            $url = $remoto ? $arquivo : 'data:' . $mimes[$ext] . ';base64,' . base64_encode($conteudo);
            return [['type' => 'image_url', 'image_url' => ['url' => $url]]];
        }
        if ($ext === 'pdf') {
            return [['type' => 'file', 'file' => [
                'filename' => basename($arquivo),
                'file_data' => 'data:application/pdf;base64,' . base64_encode($conteudo),
            ]]];
        }
        return [['type' => 'text', 'text' => "Arquivo " . basename($arquivo) . ":\n{$conteudo}"]];
    }

    private function opcoesTools(): array
    {
        if ($this->tools === []) return [];
        $defs = [];
        foreach ($this->tools as $nome => $tool) {
            $defs[] = ['type' => 'function', 'function' => [
                'name' => $nome,
                'description' => (string) ($tool['descricao'] ?? ''),
                'parameters' => $tool['parametros'] ?? ['type' => 'object', 'properties' => []],
            ]];
        }
        return ['tools' => $defs, 'tool_choice' => 'auto'];
    }

    private function carregar(): void
    {
        if ($this->carregado || (!$this->memoria && !$this->memoriaTopicos)) {
            $this->carregado = true;
            return;
        }
        if ($this->session_id === '') throw new \LogicException('session_id é obrigatório com backend de memória.');
        if ($this->memoria) $this->historico = $this->memoria->carregar($this->session_id);
        if ($this->memoriaTopicos) $this->carregarTopicos();
        $this->carregado = true;
    }

    /** Grava o turno (usuário + resposta final) no backend de memória. */
    private function persistir(string $mensagem, string $texto): void
    {
        if (!$this->memoria && !$this->memoriaTopicos) return;
        if ($this->memoria) {
            foreach ([['user', $mensagem], ['assistant', $texto]] as [$papel, $conteudo]) {
                if (trim($conteudo) === '') continue;
                $this->memoria->gravar($this->session_id, $papel, $conteudo);
            }
        }
        if ($this->memoriaTopicos) $this->gerenciarMemoria($mensagem, $texto);
    }

    private function carregarTopicos(): void
    {
        $this->topicos = $this->memoriaTopicos->todos($this->session_id);
    }

    /**
     * Modelo leve escolhe os tópicos relevantes para a mensagem atual.
     * Devolve ids; null em caso de falha (o chamador usa todos).
     */
    private function selecionarTopicos(string $mensagem): ?array
    {
        $lista = array_map(fn ($t) => [
            'id' => $t['id'],
            'descricao' => $t['descricao'],
            'anotacoes' => $t['anotacoes'],
        ], $this->topicos);
        try {
            $decisao = $this->cliente->structuredChat(
                [
                    ['role' => 'system', 'content' => 'Você escolhe quais tópicos da memória de uma sessão são relevantes para responder a mensagem atual do usuário. Devolva apenas os ids necessários; lista vazia se nenhum tópico ajuda.'],
                    ['role' => 'user', 'content' => "Mensagem atual:\n{$mensagem}\n\nTópicos:\n" . json_encode($lista, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
                ],
                [
                    'name' => 'selecao_topicos',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => ['ids' => ['type' => 'array', 'items' => ['type' => 'integer']]],
                        'required' => ['ids'],
                    ],
                ],
                $this->modeloLeve,
            );
        } catch (\Throwable $e) {
            $this->log?->error('Agente: falha na seleção de tópicos: {erro}', ['erro' => $e->getMessage()]);
            return null;
        }
        $validos = array_column($this->topicos, 'id');
        return array_values(array_intersect($decisao['ids'] ?? [], $validos));
    }

    /** Bloco de contexto com os tópicos selecionados (todos, se a seleção falhar). */
    private function blocoTopicos(string $mensagem): string
    {
        if ($this->topicos === []) return '';
        $ids = $this->selecionarTopicos($mensagem);
        $parte = $ids === null
            ? $this->topicos
            : array_values(array_filter($this->topicos, fn ($t) => in_array($t['id'], $ids, true)));
        if ($parte === []) return '';
        $blocos = array_map(fn ($t) => "## {$t['descricao']}\n"
            . 'Anotações: ' . (implode(' | ', $t['anotacoes']) ?: '(nenhuma)') . "\n"
            . "Mensagens:\n" . implode("\n", $t['mensagens']), $parte);
        return "Memória de assuntos anteriores desta sessão:\n\n" . implode("\n\n", $blocos);
    }

    /**
     * Modelo leve decide, pós-turno, o que a memória por tópicos deve
     * guardar: criar tópico novo, atualizar um existente ou nada. Falha
     * não afeta a resposta — apenas não grava.
     */
    private function gerenciarMemoria(string $mensagem, string $texto): void
    {
        $lista = array_map(fn ($t) => [
            'id' => $t['id'],
            'descricao' => $t['descricao'],
            'anotacoes' => $t['anotacoes'],
        ], $this->topicos);
        try {
            $decisao = $this->cliente->structuredChat(
                [
                    ['role' => 'system', 'content' => 'Você gerencia a memória de longo prazo de um agente de conversa, organizada em tópicos por assunto. Para cada turno, decida as operações: "criar" um tópico novo quando surgir assunto novo que valha ser lembrado depois; "atualizar" um tópico existente (informe o id) acrescentando mensagens e/ou anotações; "nada" quando não houver nada digno de memória. Prefira atualizar a criar tópicos duplicados. Anotações são frases curtas e factuais; mensagens são cópias literais do turno pertinentes ao assunto. Não memorize trivialidades (saudações, respostas genéricas).'],
                    ['role' => 'user', 'content' => "Mensagem do usuário:\n{$mensagem}\n\nResposta do agente:\n{$texto}\n\nTópicos atuais:\n" . json_encode($lista, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
                ],
                [
                    'name' => 'gerencia_memoria',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => ['operacoes' => ['type' => 'array', 'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'acao' => ['type' => 'string', 'enum' => ['criar', 'atualizar', 'nada']],
                                'id' => ['type' => 'integer'],
                                'descricao' => ['type' => 'string'],
                                'mensagens' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'anotacoes' => ['type' => 'array', 'items' => ['type' => 'string']],
                            ],
                            'required' => ['acao', 'id', 'descricao', 'mensagens', 'anotacoes'],
                        ]]],
                        'required' => ['operacoes'],
                    ],
                ],
                $this->modeloLeve,
            );
        } catch (\Throwable $e) {
            $this->log?->error('Agente: falha na gerência de memória: {erro}', ['erro' => $e->getMessage()]);
            return;
        }
        foreach ($decisao['operacoes'] ?? [] as $op) {
            $mensagens = array_values(array_filter((array) ($op['mensagens'] ?? [])));
            $anotacoes = array_values(array_filter((array) ($op['anotacoes'] ?? [])));
            if (($op['acao'] ?? '') === 'criar') {
                if (trim((string) ($op['descricao'] ?? '')) === '') continue;
                $this->memoriaTopicos->criar($this->session_id, (string) $op['descricao'], $mensagens, $anotacoes);
                $this->carregarTopicos();
            } elseif (($op['acao'] ?? '') === 'atualizar') {
                $indice = array_search((int) ($op['id'] ?? 0), array_column($this->topicos, 'id'), true);
                if ($indice === false) continue;
                $topico = $this->topicos[$indice];
                $topico['mensagens'] = array_merge($topico['mensagens'], $mensagens);
                $topico['anotacoes'] = array_merge($topico['anotacoes'], $anotacoes);
                $this->memoriaTopicos->atualizar($topico['id'], $topico['mensagens'], $topico['anotacoes']);
                $this->topicos[$indice] = $topico;
            }
        }
    }
}
