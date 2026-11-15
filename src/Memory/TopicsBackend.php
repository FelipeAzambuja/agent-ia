<?php

namespace AgentIa\Memory;

/**
 * Long-term topic memory backend per session: subjects kept with
 * messages and notes, created and updated by the Agent's post-turn
 * memory management.
 */
interface TopicsBackend
{
    /** @return array<int, array{id:int,description:string,messages:string[],notes:string[]}> */
    public function all(string $sessionId): array;

    /** @param string[] $messages @param string[] $notes */
    public function create(string $sessionId, string $description, array $messages, array $notes): void;

    /** @param string[] $messages @param string[] $notes */
    public function update(int $id, array $messages, array $notes): void;
}
