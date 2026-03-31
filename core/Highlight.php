<?php

/**
 * Syntax highlighter for SparkPHP code examples.
 *
 * Tokenizes SparkPHP/PHP-like code and wraps tokens in <span> tags
 * matching the CSS classes used across the docs and landing page.
 */
class Highlight
{
    /**
     * Highlight a SparkPHP code snippet.
     *
     * Returns HTML with <span class="line-*"> tags ready to be placed
     * inside a <pre> element. The output is HTML-safe.
     */
    public static function spark(string $code): string
    {
        $lines = explode("\n", $code);
        $out   = [];

        foreach ($lines as $line) {
            $out[] = self::line($line);
        }

        return implode("\n", $out);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internal
    // ─────────────────────────────────────────────────────────────────────────

    private static function line(string $line): string
    {
        // Full-line comment
        if (preg_match('/^\s*\/\//', $line)) {
            return '<span class="line-comment">' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</span>';
        }

        return self::tokenize($line);
    }

    private static function tokenize(string $line): string
    {
        $out = '';
        $i   = 0;
        $len = strlen($line);

        while ($i < $len) {
            $ch = $line[$i];

            // ── String literal (' or ") ──────────────────────────────────────
            if ($ch === "'" || $ch === '"') {
                $quote = $ch;
                $str   = $ch;
                $i++;
                while ($i < $len) {
                    if ($line[$i] === '\\' && $i + 1 < $len) {
                        $str .= $line[$i] . $line[$i + 1];
                        $i  += 2;
                        continue;
                    }
                    $str .= $line[$i];
                    if ($line[$i] === $quote) {
                        $i++;
                        break;
                    }
                    $i++;
                }
                $out .= '<span class="line-string">' . htmlspecialchars($str, ENT_QUOTES, 'UTF-8') . '</span>';
                continue;
            }

            // ── Variable ($name) ─────────────────────────────────────────────
            if ($ch === '$') {
                $var = '$';
                $i++;
                while ($i < $len && (ctype_alnum($line[$i]) || $line[$i] === '_')) {
                    $var .= $line[$i++];
                }
                $out .= '<span class="line-var">' . htmlspecialchars($var, ENT_QUOTES, 'UTF-8') . '</span>';
                continue;
            }

            // ── Arrow operator (->) ──────────────────────────────────────────
            if ($ch === '-' && $i + 1 < $len && $line[$i + 1] === '>') {
                $out .= '-&gt;';
                $i  += 2;
                // Method name immediately after ->
                if ($i < $len && (ctype_alpha($line[$i]) || $line[$i] === '_')) {
                    $method = '';
                    while ($i < $len && (ctype_alnum($line[$i]) || $line[$i] === '_')) {
                        $method .= $line[$i++];
                    }
                    $nextCh = $i < $len ? $line[$i] : '';
                    if ($nextCh === '(') {
                        $out .= '<span class="line-fn">' . $method . '</span>';
                    } else {
                        $out .= htmlspecialchars($method, ENT_QUOTES, 'UTF-8');
                    }
                }
                continue;
            }

            // ── Word (identifier / keyword / function call) ──────────────────
            if (ctype_alpha($ch) || $ch === '_') {
                $word = '';
                while ($i < $len && (ctype_alnum($line[$i]) || $line[$i] === '_')) {
                    $word .= $line[$i++];
                }
                $nextCh = $i < $len ? $line[$i] : '';

                if ($word === 'fn') {
                    $out .= '<span class="line-fn">' . $word . '</span>';
                } elseif (in_array($word, ['get', 'post', 'put', 'patch', 'delete', 'any'], true) && $nextCh === '(') {
                    $out .= '<span class="line-keyword">' . $word . '</span>';
                } elseif ($nextCh === '(') {
                    $out .= '<span class="line-fn">' . $word . '</span>';
                } else {
                    $out .= htmlspecialchars($word, ENT_QUOTES, 'UTF-8');
                }
                continue;
            }

            // ── Any other character ──────────────────────────────────────────
            $out .= htmlspecialchars($ch, ENT_QUOTES, 'UTF-8');
            $i++;
        }

        return $out;
    }
}
