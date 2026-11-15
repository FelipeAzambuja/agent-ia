<?php

use AgentIa\Rag\PdoVectorStore;
use AgentIa\Rag\Rag;

/**
 * Shared setup for the RAG examples with a database (rag-index.php and
 * rag-support.php): one PDO connection, the manual texts and one Rag
 * factory. The factory matters — the indexing script and the chat
 * script must agree on the name, the description and the namespace,
 * because that is what ties the stored chunks to the queries.
 */

function supportPdo(): PDO
{
    return new PDO(
        (string) (getenv('RAG_DB_DSN') ?: 'mysql:host=db;dbname=db;charset=utf8mb4'),
        (string) (getenv('RAG_DB_USER') ?: 'db'),
        (string) (getenv('RAG_DB_PASSWORD') ?: 'db'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

/** The manuals, as doc key => full text (in production: files, URLs or a CMS). */
function supportManuals(): array
{
    return [
        'x1000' => <<<'TXT'
            Solaris X1000 Cordless Vacuum — Product Manual

            The X1000 was launched in 2024. It has a three-year warranty
            covering motor and battery defects, and a one-year warranty on
            accessories. The dust container holds 0.7 liters and the battery
            runs for 55 minutes per charge. To activate the extended
            warranty, register the product on the Solaris website within 90
            days of purchase. Common issue: loss of suction is usually a
            full dust container or a clogged filter; wash the filter with
            water and let it dry for 24 hours before reassembling.
            TXT,
        'x800' => <<<'TXT'
            Solaris X800 Corded Vacuum — Product Manual

            The X800 is the previous corded model, still supported with
            spare parts until 2030. Its warranty is one year only. The bag
            capacity is 3.5 liters. It does not have a battery: the power
            cable is 6 meters long. Common issue: if the motor does not
            start, check the thermal fuse — unplug for 30 minutes and try
            again; if it still does not start, request a fuse replacement
            through support.
            TXT,
    ];
}

/**
 * The knowledge base over the database. The same name/description/namespace
 * must be used by whoever indexes and whoever queries.
 */
function supportRag(): Rag
{
    return new Rag(
        'manuals',
        'Solaris appliance manuals: warranties, specs, usage and troubleshooting of the X1000 and X800 vacuums',
        (string) getenv('OPENROUTER_KEY'),
        new PdoVectorStore(supportPdo()),
    );
}
