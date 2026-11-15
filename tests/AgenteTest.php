<?php

use AgenteIa\Agente;

/**
 * Testes offline do Agente: todo o comportamento é exercitado com o
 * FakeChatClient (respostas scriptadas), sem rede nem chave de API.
 */

test('responde o turno simples e guarda no histórico', function () {
    $cliente = new FakeChatClient([['content' => 'Olá! Em que posso ajudar?']]);
    $agente = new Agente($cliente);

    $resposta = $agente->responder('Oi!');

    expect($resposta)->toBe('Olá! Em que posso ajudar?');
    expect($cliente->chatCalls)->toHaveCount(1);
    expect($cliente->chatCalls[0]['messages'])->toBe([
        ['role' => 'user', 'content' => 'Oi!'],
    ]);
    $historico = (new ReflectionProperty(Agente::class, 'historico'));
    $historico->setAccessible(true);
    expect($historico->getValue($agente))->toBe([
        ['role' => 'user', 'content' => 'Oi!'],
        ['role' => 'assistant', 'content' => 'Olá! Em que posso ajudar?'],
    ]);
});

test('executa a tool e devolve o erro ao modelo quando ela falha', function () {
    $cliente = new FakeChatClient([
        ['content' => null, 'tool_calls' => [['id' => 'c1', 'function' => ['name' => 'hora_atual', 'arguments' => '{}']]]],
        ['content' => null, 'tool_calls' => [['id' => 'c2', 'function' => ['name' => 'quebrada', 'arguments' => '{"x":1}']]]],
        ['content' => 'Pronto, resolvi sem a ferramenta.'],
    ]);
    $agente = new Agente($cliente);
    $agente->setTools([
        'hora_atual' => [
            'descricao' => 'Data e hora atuais',
            'parametros' => ['type' => 'object', 'properties' => []],
            'executar' => fn () => '14:00',
        ],
        'quebrada' => [
            'descricao' => 'Sempre falha',
            'parametros' => ['type' => 'object', 'properties' => ['x' => ['type' => 'integer']]],
            'executar' => fn () => throw new RuntimeException('banco fora do ar'),
        ],
    ]);

    $resposta = $agente->responder('Que horas são?');

    expect($resposta)->toBe('Pronto, resolvi sem a ferramenta.');
    // O resultado da tool (sucesso e erro) chega ao modelo como mensagem 'tool'.
    $mensagens = $cliente->chatCalls[2]['messages'];
    $tools = array_values(array_filter($mensagens, fn ($m) => $m['role'] === 'tool'));
    expect($tools)->toHaveCount(2);
    expect($tools[0]['content'])->toBe('14:00');
    expect($tools[1]['content'])->toContain('Erro ao executar a ferramenta quebrada');
    expect($tools[1]['content'])->toContain('banco fora do ar');
});

test('memória sobrevive entre instâncias com backend injetado', function () {
    $backend = new FakeMemoriaBackend();
    $a = new Agente(new FakeChatClient([['content' => 'Prazer, Felipe!']]));
    $a->session_id = 'sess-1';
    $a->memoria = $backend;
    $a->responder('Meu nome é Felipe.');

    $b = new Agente(new FakeChatClient([['content' => 'Seu nome é Felipe.']]));
    $b->session_id = 'sess-1';
    $b->memoria = $backend;
    $b->responder('Qual é o meu nome?');

    // A segunda instância carrega o histórico do backend antes de responder.
    expect($backend->sessoes['sess-1'])->toHaveCount(4);
    expect($backend->sessoes['sess-1'][0])->toBe(['role' => 'user', 'content' => 'Meu nome é Felipe.']);
});

test('session_id é obrigatório quando há backend de memória', function () {
    $agente = new Agente(new FakeChatClient());
    $agente->memoria = new FakeMemoriaBackend();
    $agente->responder('Oi');
})->throws(LogicException::class, 'session_id é obrigatório');

test('addSubAgente registra a tool com nome normalizado e delega com handoff', function () {
    $sub = new FakeSubAgente('resposta do subagente');
    $sub->setName('Clovis Atende Visitas');

    $cliente = new FakeChatClient([], [['conseguiu' => true]]);
    $agente = new Agente($cliente);
    $agente->addSubAgente($sub, 'Atende chamadas de assunto de visitas');
    $agente->addSubAgente(new Agente(new FakeChatClient()), 'Atende chamadas de assunto de financiamento');

    $tools = (new ReflectionProperty(Agente::class, 'tools'));
    $tools->setAccessible(true);
    $tools = $tools->getValue($agente);

    // Nome definido vira tool normalizada; sem nome, vira subagente_N.
    expect(array_keys($tools))->toBe(['clovis_atende_visitas', 'subagente_2']);
    expect($tools['clovis_atende_visitas']['parametros'])->toBe([
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'resumo' => ['type' => 'string'],
            'conseguiu_atender' => ['type' => 'string'],
            'nao_conseguiu_atender' => ['type' => 'string'],
            'espera_que_atenda' => ['type' => 'string'],
        ],
        'required' => ['resumo', 'conseguiu_atender', 'nao_conseguiu_atender', 'espera_que_atenda'],
    ]);

    // A tool delega ao subagente com transcrição + handoff, e o status volta no resultado.
    $saida = ($tools['clovis_atende_visitas']['executar'])([
        'resumo' => 'Cliente quer visitar um apartamento',
        'conseguiu_atender' => 'Cumprimentei e confirmei o interesse',
        'nao_conseguiu_atender' => 'Não tenho acesso à agenda de visitas',
        'espera_que_atenda' => 'Agendar a visita e confirmar data',
    ]);
    expect($saida)->toContain('resposta do subagente');
    expect($saida)->toContain('[Status da delegação: o subagente conseguiu atender o que era esperado.]');
    expect($sub->recebido)->toHaveCount(1);
    expect($sub->recebido[0])->toContain('Conversa até agora:');
    expect($sub->recebido[0])->toContain("Handoff do agente orquestrador:\nResumo: Cliente quer visitar um apartamento\n"
        . "O que conseguiu atender: Cumprimentei e confirmei o interesse\n"
        . "O que não conseguiu atender: Não tenho acesso à agenda de visitas\n"
        . 'O que espera que o subagente atenda: Agendar a visita e confirmar data');
});

test('status de fracasso orienta o orquestrador a tentar o próximo subagente', function () {
    $sub = new FakeSubAgente('Não consegui atender este pedido.');
    $cliente = new FakeChatClient([], [['conseguiu' => false]]);
    $agente = new Agente($cliente);
    $agente->addSubAgente($sub, 'Atende visitas');

    $tools = (new ReflectionProperty(Agente::class, 'tools'));
    $tools->setAccessible(true);
    $tools = $tools->getValue($agente);
    $saida = ($tools['subagente_1']['executar'])([
        'resumo' => 'x', 'conseguiu_atender' => '', 'nao_conseguiu_atender' => '', 'espera_que_atenda' => 'Agendar visita',
    ]);

    expect($saida)->toContain('[Status da delegação: o subagente NÃO conseguiu atender o que era esperado.');
    expect($saida)->toContain('Se houver outro subagente possível, delegue a ele; se nenhum puder, responda ao usuário por conta própria.');
});

test('falha na avaliação de status assume que o subagente conseguiu', function () {
    $sub = new FakeSubAgente('resposta');
    // structuredChat sem resposta na fila devolve [] → avaliador trata como conseguiu.
    $cliente = new FakeChatClient([], []);
    $agente = new Agente($cliente);
    $agente->addSubAgente($sub, 'Atende visitas');

    $tools = (new ReflectionProperty(Agente::class, 'tools'));
    $tools->setAccessible(true);
    $tools = $tools->getValue($agente);
    $saida = ($tools['subagente_1']['executar'])([
        'resumo' => 'x', 'conseguiu_atender' => '', 'nao_conseguiu_atender' => '', 'espera_que_atenda' => 'y',
    ]);

    expect($saida)->toContain('o subagente conseguiu atender');
});

test('loop de tools roteia para o próximo subagente e usa a resposta dele', function () {
    $recusante = new FakeSubAgente('Infelizmente não consegui atender este pedido.');
    $recusante->setName('primeiro');
    $resolvente = new FakeSubAgente('VISITA AGENDADA COM SUCESSO. Código secreto: ABACAXI-42.');
    $resolvente->setName('segundo');

    $cliente = new FakeChatClient(
        [
            // Rodada 1: orquestrador delega ao primeiro subagente.
            ['content' => null, 'tool_calls' => [['id' => 'c1', 'function' => ['name' => 'primeiro', 'arguments' => json_encode([
                'resumo' => 'Cliente quer agendar visita',
                'conseguiu_atender' => 'Nada ainda',
                'nao_conseguiu_atender' => 'Não tenho agenda',
                'espera_que_atenda' => 'Agendar a visita',
            ])]]]],
            // Rodada 2: status de fracasso, orquestrador delega ao segundo.
            ['content' => null, 'tool_calls' => [['id' => 'c2', 'function' => ['name' => 'segundo', 'arguments' => json_encode([
                'resumo' => 'Cliente quer agendar visita; primeiro subagente falhou',
                'conseguiu_atender' => 'Nada',
                'nao_conseguiu_atender' => 'Primeiro subagente não conseguiu',
                'espera_que_atenda' => 'Agendar a visita',
            ])]]]],
            // Rodada 3: resposta final repassando o sucesso.
            ['content' => 'VISITA AGENDADA COM SUCESSO. Código secreto: ABACAXI-42.'],
        ],
        // Avaliações de status: primeiro fracasso, depois sucesso.
        [['conseguiu' => false], ['conseguiu' => true]],
    );
    $agente = new Agente($cliente);
    $agente->addSubAgente($recusante, 'Atende visitas (primeira tentativa)');
    $agente->addSubAgente($resolvente, 'Atende visitas (quando o primeiro não conseguir)');

    $resposta = $agente->responder('Quero agendar uma visita ao apartamento.');

    expect($resposta)->toContain('ABACAXI-42');
    expect($recusante->recebido)->toHaveCount(1);
    expect($recusante->recebido[0])->toContain('Quero agendar uma visita ao apartamento.');
    expect($recusante->recebido[0])->toContain('O que espera que o subagente atenda: Agendar a visita');
    expect($resolvente->recebido)->toHaveCount(1);
    expect($resolvente->recebido[0])->toContain('primeiro subagente falhou');
});

test('nome de tool com acentos e símbolos é normalizado', function () {
    $cliente = new FakeChatClient();
    $agente = new Agente($cliente);
    $sub = new FakeSubAgente();
    $sub->setName('  Atenção!! Café & Visita  ');
    $agente->addSubAgente($sub, 'x');

    $tools = (new ReflectionProperty(Agente::class, 'tools'));
    $tools->setAccessible(true);
    expect(array_keys($tools->getValue($agente)))->toBe(['atencao_cafe_visita']);
});
