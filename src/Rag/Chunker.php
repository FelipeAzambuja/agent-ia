<?php

namespace AgentIa\Rag;

/**
 * Splits a document into chunks for indexing: paragraphs are kept
 * whole when they fit; long paragraphs are broken by sentence (and
 * by hard cut as a last resort); each chunk starts with a tail of the
 * previous one (overlap) so context is not lost at the boundary.
 * Every chunk is at most $size characters.
 */
class Chunker
{
    public function __construct(
        private int $size = 800,
        private int $overlap = 100,
    ) {}

    /** @return array<int, string> */
    public function chunk(string $text): array
    {
        $text = trim(preg_replace('/\R{3,}/', "\n\n", $text) ?? $text);
        if ($text === '') return [];
        if (mb_strlen($text) <= $this->size) return [$text];

        $chunks = [];
        $current = '';
        foreach ($this->pieces($text) as $piece) {
            // Piece alone exceeds the limit: hard-cut it by sentence (or characters).
            while (mb_strlen($piece) > $this->size) {
                if (trim($current) !== '') { $chunks[] = trim($current); $current = ''; }
                $cut = $this->cut($piece, $this->size);
                $chunks[] = trim($cut);
                $piece = mb_substr($piece, mb_strlen($cut));
            }
            $candidate = trim($current === '' ? $piece : "{$current}\n{$piece}");
            if (mb_strlen($candidate) > $this->size && trim($current) !== '') {
                $chunks[] = trim($current);
                $current = trim($this->tail($current) . "\n" . $piece);
                // Tail + piece may still exceed the limit: drop the tail.
                if (mb_strlen($current) > $this->size) $current = $piece;
            } else {
                $current = $candidate;
            }
        }
        if (trim($current) !== '') $chunks[] = trim($current);
        return array_values(array_filter($chunks, 'trim'));
    }

    /** Paragraphs, or sentences when a paragraph alone exceeds the limit. */
    private function pieces(string $text): array
    {
        $out = [];
        foreach (preg_split('/\n{2,}/', $text) ?: [$text] as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') continue;
            if (mb_strlen($paragraph) <= $this->size) { $out[] = $paragraph; continue; }
            $sentence = '';
            foreach (preg_split('/(?<=[.!?])\s+/', $paragraph) ?: [$paragraph] as $part) {
                if (trim($sentence) !== '' && mb_strlen($sentence) + mb_strlen($part) + 1 > $this->size) {
                    $out[] = trim($sentence);
                    $sentence = '';
                }
                $sentence = trim($sentence === '' ? $part : "{$sentence} {$part}");
            }
            if (trim($sentence) !== '') $out[] = $sentence;
        }
        return $out;
    }

    /** Overlap tail: last sentences of the previous chunk, up to the overlap. */
    private function tail(string $text): string
    {
        if ($this->overlap <= 0) return '';
        $sentences = preg_split('/(?<=[.!?])\s+/', $text) ?: [$text];
        $tail = '';
        for ($i = count($sentences) - 1; $i >= 0; $i--) {
            $candidate = $tail === '' ? $sentences[$i] : "{$sentences[$i]} {$tail}";
            if (mb_strlen($candidate) > $this->overlap && $tail !== '') break;
            $tail = $candidate;
        }
        return $tail;
    }

    /** Cuts a piece at the last sentence boundary before $size. */
    private function cut(string $piece, int $size): string
    {
        $head = mb_substr($piece, 0, $size);
        $pos = mb_strrpos($head, '. ');
        return $pos !== false ? mb_substr($head, 0, $pos + 1) : $head;
    }
}
