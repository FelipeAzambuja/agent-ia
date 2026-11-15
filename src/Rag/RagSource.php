<?php

namespace AgentIa\Rag;

/**
 * A searchable knowledge base exposed to the Agent. Each source
 * becomes a tool (so the main model can also search mid-turn) and a
 * router option (the light model decides, before the turn, whether
 * the message needs retrieval and from which sources).
 */
interface RagSource
{
    /** Source name, used in the tool name (search_<name>) and in the router. */
    public function name(): string;

    /** What the source contains, shown to both models to decide when to use it. */
    public function description(): string;

    /**
     * Searches the source and returns the most relevant chunks, best first.
     *
     * @return array<int, array{text:string, score:float, metadata:array<string, mixed>}>
     */
    public function search(string $query, int $limit): array;
}
