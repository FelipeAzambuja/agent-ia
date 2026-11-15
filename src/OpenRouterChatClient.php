<?php

namespace AgentIa;

use cheinisch\OpenRouterClient as VendorOpenRouterClient;

/**
 * ChatClient adapter over the reference cheinisch/openrouter-php-client.
 *
 * The vendor client already exposes chatEx()/structuredChat() with the exact
 * signatures (and return shapes) the interface expects, so extending it is
 * all the adaptation this needs — everything else (streaming, plugins,
 * provider routing, generation stats) stays available too.
 *
 * Usage:
 *   $agent = new Agent(getenv('OPENROUTER_KEY')); // wraps this client for you
 */
class OpenRouterChatClient extends VendorOpenRouterClient implements ChatClient
{
}
