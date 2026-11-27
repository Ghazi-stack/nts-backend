<?php

namespace App\Services;

/**
 * Safe, Handlebars-style renderer for store-editable transactional emails
 * (same syntax family BigCommerce uses for its email templates).
 *
 * Store owners edit these templates from the dashboard, so the engine never
 * evaluates PHP/Blade: it parses the template into a tree and only resolves
 * values from the data array it is given.
 *
 * Supported syntax:
 *   {{path.to.value}}            HTML-escaped value
 *   {{{path.to.value}}}          raw (unescaped) value
 *   {{lang 'phrase_name'}}       store phrase (phrases may contain {{...}} and HTML)
 *   {{#each list}}..{{else}}..{{/each}}   loop; inside: {{this}}, {{@index}},
 *                                         {{@number}}, {{@first}}, {{@last}},
 *                                         item fields by name, outer values by name
 *   {{#if path}}..{{else}}..{{/if}}
 *   {{#unless path}}..{{else}}..{{/unless}}
 *   {{!-- comment --}} / {{! comment }}
 *
 * Unknown values render as an empty string. Legacy templates that use
 * phrase names directly ({{title}}) keep working because phrases are also
 * exposed as top-level values by the caller.
 */
class EmailTemplateEngine
{
    private const TAG = '/\{\{\{\s*(.*?)\s*\}\}\}|\{\{(!--.*?--|![^}]*)\}\}|\{\{\s*(.*?)\s*\}\}/s';

    private const MAX_DEPTH = 32;

    /** @var array<string, string> */
    private array $phrases = [];

    private bool $escape = true;

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $phrases phrase_name => phrase_value
     * @param bool                  $escapeHtml false for plain-text output (subjects)
     */
    public function render(string $template, array $data, array $phrases = [], bool $escapeHtml = true): string
    {
        $this->phrases = $phrases;
        $this->escape = $escapeHtml;

        return $this->renderNodes($this->parse($template), [$data]);
    }

    // ---------------------------------------------------------------- parse

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parse(string $template): array
    {
        $tokens = $this->tokenize($template);
        $pos = 0;

        $nodes = $this->parseUntil($tokens, $pos, null);

        return $nodes;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function tokenize(string $template): array
    {
        $tokens = [];
        $offset = 0;

        preg_match_all(self::TAG, $template, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $m) {
            [$whole, $start] = $m[0];

            if ($start > $offset) {
                $tokens[] = ['type' => 'text', 'value' => substr($template, $offset, $start - $offset)];
            }

            $offset = $start + strlen($whole);

            if (isset($m[1]) && $m[1][1] !== -1 && $m[1][0] !== '') {
                $tokens[] = ['type' => 'raw', 'expr' => trim($m[1][0])];
                continue;
            }

            if (isset($m[2]) && $m[2][1] !== -1 && $m[2][0] !== '') {
                continue; // comment
            }

            $expr = trim($m[3][0] ?? '');

            if ($expr === '') {
                $tokens[] = ['type' => 'text', 'value' => $whole];
                continue;
            }

            if ($expr[0] === '#') {
                $parts = preg_split('/\s+/', trim(substr($expr, 1)), 2);
                $tokens[] = ['type' => 'open', 'helper' => $parts[0], 'arg' => trim($parts[1] ?? '')];
            } elseif ($expr[0] === '/') {
                $tokens[] = ['type' => 'close', 'helper' => trim(substr($expr, 1))];
            } elseif ($expr === 'else') {
                $tokens[] = ['type' => 'else'];
            } else {
                $tokens[] = ['type' => 'var', 'expr' => $expr];
            }
        }

        if ($offset < strlen($template)) {
            $tokens[] = ['type' => 'text', 'value' => substr($template, $offset)];
        }

        return $tokens;
    }

    /**
     * Parses tokens until the matching close tag (or end of input).
     * Unbalanced tags are rendered literally instead of throwing, so a
     * store owner's typo never stops an email.
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseUntil(array $tokens, int &$pos, ?string $closing, bool &$sawElse = false): array
    {
        $nodes = [];

        while ($pos < count($tokens)) {
            $t = $tokens[$pos++];

            switch ($t['type']) {
                case 'open':
                    if (!in_array($t['helper'], ['each', 'if', 'unless'], true)) {
                        $nodes[] = ['type' => 'text', 'value' => '{{#' . $t['helper'] . ' ' . $t['arg'] . '}}'];
                        break;
                    }

                    $else = false;
                    $body = $this->parseUntil($tokens, $pos, $t['helper'], $else);
                    $inverse = [];

                    if ($else) {
                        $dummy = false;
                        $inverse = $this->parseUntil($tokens, $pos, $t['helper'], $dummy);
                    }

                    $nodes[] = [
                        'type'    => 'block',
                        'helper'  => $t['helper'],
                        'arg'     => $t['arg'],
                        'body'    => $body,
                        'inverse' => $inverse,
                    ];
                    break;

                case 'else':
                    if ($closing !== null) {
                        $sawElse = true;
                        return $nodes;
                    }
                    $nodes[] = ['type' => 'text', 'value' => '{{else}}'];
                    break;

                case 'close':
                    if ($closing !== null && $t['helper'] === $closing) {
                        return $nodes;
                    }
                    $nodes[] = ['type' => 'text', 'value' => '{{/' . $t['helper'] . '}}'];
                    break;

                default:
                    $nodes[] = $t;
            }
        }

        return $nodes;
    }

    // --------------------------------------------------------------- render

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @param array<int, mixed>                $scopes innermost last
     */
    private function renderNodes(array $nodes, array $scopes, int $depth = 0): string
    {
        if ($depth > self::MAX_DEPTH) {
            return '';
        }

        $out = '';

        foreach ($nodes as $node) {
            switch ($node['type']) {
                case 'text':
                    $out .= $node['value'];
                    break;

                case 'var':
                    $out .= $this->renderVar($node['expr'], $scopes, true, $depth);
                    break;

                case 'raw':
                    $out .= $this->renderVar($node['expr'], $scopes, false, $depth);
                    break;

                case 'block':
                    $out .= $this->renderBlock($node, $scopes, $depth);
                    break;
            }
        }

        return $out;
    }

    private function renderVar(string $expr, array $scopes, bool $escape, int $depth): string
    {
        // {{lang 'name'}} — phrases are store-authored and may contain HTML
        // and their own {{placeholders}}.
        if (preg_match('/^lang\s+["\']([^"\']+)["\']$/', $expr, $m)) {
            return $this->renderPhrase($m[1], $scopes, $depth);
        }

        $value = $this->lookup($expr, $scopes);

        // Legacy templates print phrases as plain {{phrase_name}}.
        if ($value === null && array_key_exists($expr, $this->phrases)) {
            return $this->renderPhrase($expr, $scopes, $depth);
        }

        // An object-like value can say how it prints on its own, e.g.
        // {{store}} -> store name, while {{store.url}} still works.
        if (is_array($value) && array_key_exists('__toString', $value)) {
            $value = $value['__toString'];
        }

        if (is_bool($value)) {
            $value = $value ? 'true' : '';
        }

        if (!is_scalar($value) && !(is_object($value) && method_exists($value, '__toString'))) {
            return '';
        }

        $value = (string) $value;

        return ($escape && $this->escape) ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : $value;
    }

    private function renderPhrase(string $name, array $scopes, int $depth): string
    {
        $phrase = $this->phrases[$name] ?? '';

        if ($phrase === '' || $depth > 4) {
            return $phrase;
        }

        return $this->renderNodes($this->parse($phrase), $scopes, $depth + 1);
    }

    private function renderBlock(array $node, array $scopes, int $depth): string
    {
        $value = $this->lookup($node['arg'], $scopes);

        if ($node['helper'] === 'each') {
            $items = is_array($value) ? $value : [];

            if ($items === []) {
                return $this->renderNodes($node['inverse'], $scopes, $depth + 1);
            }

            $out = '';
            $count = count($items);
            $i = 0;

            foreach ($items as $item) {
                $meta = [
                    '@index'  => $i,
                    '@number' => $i + 1,
                    '@first'  => $i === 0,
                    '@last'   => $i === $count - 1,
                    'this'    => $item,
                ];

                $itemScope = is_array($item) ? array_merge($item, $meta) : $meta;

                $out .= $this->renderNodes($node['body'], array_merge($scopes, [$itemScope]), $depth + 1);
                $i++;
            }

            return $out;
        }

        $truthy = $this->truthy($value);

        if ($node['helper'] === 'unless') {
            $truthy = !$truthy;
        }

        return $this->renderNodes($truthy ? $node['body'] : $node['inverse'], $scopes, $depth + 1);
    }

    private function truthy($value): bool
    {
        if (is_array($value)) {
            return $value !== [];
        }

        if (is_string($value)) {
            return trim($value) !== '' && $value !== '0';
        }

        return (bool) $value;
    }

    /**
     * Resolves "a.b.c" from the innermost scope outwards. "this" refers to
     * the current loop item; "../x" looks only in outer scopes.
     */
    private function lookup(string $path, array $scopes)
    {
        $path = trim($path);

        if ($path === '') {
            return null;
        }

        $skip = 0;
        while (str_starts_with($path, '../')) {
            $skip++;
            $path = substr($path, 3);
        }

        $candidates = array_reverse(array_slice($scopes, 0, max(1, count($scopes) - $skip)));

        if ($path === 'this' || $path === '.') {
            $top = $candidates[0] ?? null;
            return is_array($top) && array_key_exists('this', $top) ? $top['this'] : $top;
        }

        if (str_starts_with($path, 'this.')) {
            $path = substr($path, 5);
            $candidates = array_slice($candidates, 0, 1);
        }

        $segments = explode('.', $path);

        foreach ($candidates as $scope) {
            if (!is_array($scope) || !array_key_exists($segments[0], $scope)) {
                continue;
            }

            $value = $scope;

            foreach ($segments as $segment) {
                if (is_array($value) && array_key_exists($segment, $value)) {
                    $value = $value[$segment];
                } else {
                    return null;
                }
            }

            return $value;
        }

        return null;
    }
}
