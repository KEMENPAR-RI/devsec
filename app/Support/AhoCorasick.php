<?php

namespace App\Support;

/**
 * Byte-level Aho-Corasick automaton: finds every occurrence of every pattern
 * (overlapping included) in one pass over the text.
 *
 * Byte-level is safe for UTF-8 because UTF-8 is self-synchronizing - a pattern
 * can only match at a character boundary of a valid text.
 */
class AhoCorasick
{
    /** @var array<int, array<string, int>> state => byte => next state */
    protected array $goto = [[]];

    /** @var array<int, int> */
    protected array $fail = [0];

    /** @var array<int, int> pattern index ending at this state, -1 when none */
    protected array $output = [-1];

    /** @var array<int, int> nearest state on the fail chain that has an output, -1 when none */
    protected array $dictLink = [-1];

    /** @var array<int, array{0: mixed, 1: int}> pattern index => [payload, byte length] */
    protected array $patterns = [];

    /**
     * @param  array<string, mixed>  $patterns  pattern => payload
     */
    public function __construct(array $patterns)
    {
        foreach ($patterns as $pattern => $payload) {
            $this->add((string) $pattern, $payload);
        }

        $this->build();
    }

    /**
     * Every match as [end offset (exclusive), payload, byte length].
     *
     * @return array<int, array{0: int, 1: mixed, 2: int}>
     */
    public function search(string $text): array
    {
        $matches = [];

        if ($this->patterns === []) {
            return $matches;
        }

        $state = 0;
        $length = strlen($text);
        $goto = $this->goto;
        $fail = $this->fail;

        for ($i = 0; $i < $length; $i++) {
            $byte = $text[$i];

            while ($state > 0 && ! isset($goto[$state][$byte])) {
                $state = $fail[$state];
            }

            $state = $goto[$state][$byte] ?? 0;

            for ($s = $this->output[$state] >= 0 ? $state : $this->dictLink[$state]; $s >= 0; $s = $this->dictLink[$s]) {
                [$payload, $patternLength] = $this->patterns[$this->output[$s]];
                $matches[] = [$i + 1, $payload, $patternLength];
            }
        }

        return $matches;
    }

    protected function add(string $pattern, mixed $payload): void
    {
        if ($pattern === '') {
            return;
        }

        $state = 0;

        for ($i = 0, $n = strlen($pattern); $i < $n; $i++) {
            $byte = $pattern[$i];

            if (! isset($this->goto[$state][$byte])) {
                $next = count($this->goto);
                $this->goto[$next] = [];
                $this->fail[$next] = 0;
                $this->output[$next] = -1;
                $this->dictLink[$next] = -1;
                $this->goto[$state][$byte] = $next;
            }

            $state = $this->goto[$state][$byte];
        }

        $this->output[$state] = count($this->patterns);
        $this->patterns[] = [$payload, strlen($pattern)];
    }

    /** Breadth-first fail links + dictionary-suffix links. */
    protected function build(): void
    {
        $queue = [];

        foreach ($this->goto[0] as $child) {
            $queue[] = $child;
        }

        for ($head = 0; $head < count($queue); $head++) {
            $state = $queue[$head];

            foreach ($this->goto[$state] as $byte => $child) {
                $byte = (string) $byte;
                $f = $this->fail[$state];

                while ($f > 0 && ! isset($this->goto[$f][$byte])) {
                    $f = $this->fail[$f];
                }

                $this->fail[$child] = $this->goto[$f][$byte] ?? 0;
                $target = $this->fail[$child];
                $this->dictLink[$child] = $this->output[$target] >= 0 ? $target : $this->dictLink[$target];
                $queue[] = $child;
            }
        }
    }
}
