<?php

/**
 * Native Markdown-to-HTML parser for SparkPHP.
 *
 * Supports: headings, paragraphs, bold, italic, inline code, code blocks (fenced),
 * blockquotes, ordered/unordered lists, horizontal rules, links, images, tables,
 * and strikethrough. Zero external dependencies.
 */
class Markdown
{
    private string $html = '';
    private array $copyLangs = [];

    /**
     * @param string   $markdown  Raw markdown text
     * @param string[] $copyLangs Languages that get a copy button (e.g. ['php','bash','js'])
     *                            Empty array = no copy button on any block
     */
    public static function parse(string $markdown, array $copyLangs = []): string
    {
        $instance = new self();
        $instance->copyLangs = array_map('strtolower', $copyLangs);
        return $instance->convert($markdown);
    }

    public function convert(string $markdown): string
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        $lines = explode("\n", $markdown);

        $this->html = '';
        $i = 0;
        $total = count($lines);

        while ($i < $total) {
            $line = $lines[$i];

            // Fenced code block (``` or ~~~)
            if (preg_match('/^(`{3,}|~{3,})(\w*)/', $line, $m)) {
                $fence = $m[1][0];
                $lang  = $m[2];
                $code  = [];
                $i++;
                while ($i < $total && !preg_match('/^' . preg_quote($fence, '/') . '{3,}\s*$/', $lines[$i])) {
                    $code[] = $lines[$i];
                    $i++;
                }
                $i++; // skip closing fence
                $escaped = htmlspecialchars(implode("\n", $code), ENT_QUOTES, 'UTF-8');
                $langSafe = $lang ? htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') : '';
                $langAttr = $langSafe ? " class=\"language-{$langSafe}\"" : '';
                $allLangs = in_array('*', $this->copyLangs, true);
                $showCopy = !empty($this->copyLangs) && ($allLangs || ($langSafe && in_array(strtolower($langSafe), $this->copyLangs, true)));

                if ($showCopy) {
                    $langLabel = "<span class=\"code-block__lang\">{$langSafe}</span>";
                    $copyBtn = "<button type=\"button\" class=\"code-block__copy\" title=\"Copiar\">"
                        . "<svg width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\">"
                        . "<rect x=\"9\" y=\"9\" width=\"13\" height=\"13\" rx=\"2\" ry=\"2\"/>"
                        . "<path d=\"M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1\"/>"
                        . "</svg><span class=\"code-block__copy-label\">Copiar</span></button>";
                    $this->html .= "<div class=\"code-block\">"
                        . "<div class=\"code-block__header\">{$langLabel}{$copyBtn}</div>"
                        . "<pre><code{$langAttr}>{$escaped}</code></pre></div>\n";
                } else {
                    $this->html .= "<pre><code{$langAttr}>{$escaped}</code></pre>\n";
                }
                continue;
            }

            // Horizontal rule
            if (preg_match('/^(\*{3,}|-{3,}|_{3,})\s*$/', $line)) {
                $this->html .= "<hr>\n";
                $i++;
                continue;
            }

            // Heading (ATX style)
            if (preg_match('/^(#{1,6})\s+(.+?)(?:\s+#+)?\s*$/', $line, $m)) {
                $level = strlen($m[1]);
                $text  = $this->inline($m[2]);
                $id    = $this->slugify(strip_tags($m[2]));
                $this->html .= "<h{$level} id=\"{$id}\">{$text}</h{$level}>\n";
                $i++;
                continue;
            }

            // Table
            if ($i + 1 < $total && str_contains($line, '|') && preg_match('/^\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)*\|?\s*$/', $lines[$i + 1])) {
                $this->html .= $this->parseTable($lines, $i, $total);
                continue;
            }

            // Blockquote
            if (str_starts_with($line, '>')) {
                $quoteLines = [];
                while ($i < $total && (str_starts_with($lines[$i], '>') || (trim($lines[$i]) !== '' && !empty($quoteLines)))) {
                    $quoteLines[] = preg_replace('/^>\s?/', '', $lines[$i]);
                    $i++;
                    if ($i < $total && trim($lines[$i]) === '' && ($i + 1 >= $total || !str_starts_with($lines[$i + 1], '>'))) {
                        break;
                    }
                }
                $inner = (new self())->convert(implode("\n", $quoteLines));
                $this->html .= "<blockquote>\n{$inner}</blockquote>\n";
                continue;
            }

            // Unordered list
            if (preg_match('/^(\s*)[*\-+]\s+/', $line)) {
                $this->html .= $this->parseList($lines, $i, $total, 'ul');
                continue;
            }

            // Ordered list
            if (preg_match('/^(\s*)\d+[.)]\s+/', $line)) {
                $this->html .= $this->parseList($lines, $i, $total, 'ol');
                continue;
            }

            // Empty line
            if (trim($line) === '') {
                $i++;
                continue;
            }

            // Paragraph
            $para = [];
            while ($i < $total && trim($lines[$i]) !== '' && !preg_match('/^(#{1,6}\s|```|~~~|>|\*{3,}|-{3,}|_{3,}|(\s*)[*\-+]\s|(\s*)\d+[.)]\s|\|)/', $lines[$i])) {
                $para[] = $lines[$i];
                $i++;
            }
            if ($para) {
                $text = $this->inline(implode("\n", $para));
                $text = nl2br($text);
                $this->html .= "<p>{$text}</p>\n";
            }
        }

        return $this->html;
    }

    /**
     * Parse inline formatting: bold, italic, code, links, images, strikethrough.
     */
    private function inline(string $text): string
    {
        // Inline code (must come first to protect content inside backticks)
        $text = preg_replace_callback('/`([^`]+)`/', function ($m) {
            return '<code>' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '</code>';
        }, $text);

        // Images ![alt](src "title")
        $text = preg_replace('/!\[([^\]]*)\]\(([^)\s]+)(?:\s+"([^"]*)")?\)/', '<img src="$2" alt="$1" title="$3">', $text);

        // Links [text](url "title")
        $text = preg_replace('/\[([^\]]+)\]\(([^)\s]+)(?:\s+"([^"]*)")?\)/', '<a href="$2" title="$3">$1</a>', $text);

        // Bold + Italic ***text*** or ___text___
        $text = preg_replace('/\*{3}(.+?)\*{3}|_{3}(.+?)_{3}/', '<strong><em>$1$2</em></strong>', $text);

        // Bold **text** or __text__
        $text = preg_replace('/\*{2}(.+?)\*{2}|_{2}(.+?)_{2}/', '<strong>$1$2</strong>', $text);

        // Italic *text* or _text_
        $text = preg_replace('/\*(.+?)\*|_(.+?)_/', '<em>$1$2</em>', $text);

        // Strikethrough ~~text~~
        $text = preg_replace('/~~(.+?)~~/', '<del>$1</del>', $text);

        return $text;
    }

    /**
     * Parse a list block (ul or ol).
     */
    private function parseList(array &$lines, int &$i, int $total, string $tag): string
    {
        $pattern = $tag === 'ul' ? '/^(\s*)[*\-+]\s+(.*)/' : '/^(\s*)\d+[.)]\s+(.*)/';
        $html = "<{$tag}>\n";

        while ($i < $total && preg_match($pattern, $lines[$i], $m)) {
            $content = $this->inline($m[2]);
            $html .= "<li>{$content}</li>\n";
            $i++;
        }

        $html .= "</{$tag}>\n";
        return $html;
    }

    /**
     * Parse a table block.
     */
    private function parseTable(array &$lines, int &$i, int $total): string
    {
        // Header row
        $headers = $this->parseTableRow($lines[$i]);
        $i++;

        // Alignment row
        $aligns = [];
        $alignCells = $this->parseTableRow($lines[$i]);
        foreach ($alignCells as $cell) {
            $cell = trim($cell);
            if (str_starts_with($cell, ':') && str_ends_with($cell, ':')) {
                $aligns[] = 'center';
            } elseif (str_ends_with($cell, ':')) {
                $aligns[] = 'right';
            } elseif (str_starts_with($cell, ':')) {
                $aligns[] = 'left';
            } else {
                $aligns[] = '';
            }
        }
        $i++;

        $html = "<table>\n<thead>\n<tr>\n";
        foreach ($headers as $idx => $header) {
            $align = isset($aligns[$idx]) && $aligns[$idx] ? " style=\"text-align:{$aligns[$idx]}\"" : '';
            $html .= "<th{$align}>" . $this->inline(trim($header)) . "</th>\n";
        }
        $html .= "</tr>\n</thead>\n<tbody>\n";

        while ($i < $total && str_contains($lines[$i], '|') && trim($lines[$i]) !== '') {
            $cells = $this->parseTableRow($lines[$i]);
            $html .= "<tr>\n";
            foreach ($cells as $idx => $cell) {
                $align = isset($aligns[$idx]) && $aligns[$idx] ? " style=\"text-align:{$aligns[$idx]}\"" : '';
                $html .= "<td{$align}>" . $this->inline(trim($cell)) . "</td>\n";
            }
            $html .= "</tr>\n";
            $i++;
        }

        $html .= "</tbody>\n</table>\n";
        return $html;
    }

    private function parseTableRow(string $line): array
    {
        $line = trim($line);
        $line = preg_replace('/^\||\|$/', '', $line);
        return explode('|', $line);
    }

    private function slugify(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\w\s-]/u', '', $text);
        $text = preg_replace('/[\s_]+/', '-', $text);
        return trim($text, '-');
    }
}
