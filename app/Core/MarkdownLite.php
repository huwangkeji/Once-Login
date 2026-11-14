<?php
/**
 * 轻量 Markdown -> HTML 渲染器（仅覆盖本项目文档实际用到的语法子集）
 * 设计目标：安全（先转义 HTML 再注入标签）、零依赖、可预测。
 * 支持：标题(#~######)、围栏代码块、表格、引用、有序/无序列表、
 *       分隔线、粗体/斜体/行内代码、链接、段落。
 */
if (!function_exists('uic_md_to_html')) {
    function uic_md_escape($s)
    {
        return htmlspecialchars($s, ENT_HTML5 | ENT_QUOTES, 'UTF-8');
    }

    function uic_md_inline($s)
    {
        $s = uic_md_escape($s);
        // 行内代码 `code`
        $s = preg_replace_callback('/`([^`]+)`/', function ($m) {
            return '<code>' . $m[1] . '</code>';
        }, $s);
        // 粗体 **text**
        $s = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s);
        // 斜体 *text* 或 _text_
        $s = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $s);
        $s = preg_replace('/_([^_]+)_/', '<em>$1</em>', $s);
        // 链接 [text](url)
        $s = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/', function ($m) {
            $url = $m[2];
            // 仅允许 http/https/相对/锚点，防 javascript: 等
            if (!preg_match('~^(https?://|/|#|\./|\.\./)~i', $url)) {
                $url = '#';
            }
            return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">' . $m[1] . '</a>';
        }, $s);
        return $s;
    }

    function uic_md_table($rows)
    {
        $html = '<table class="md-table">';
        $header = null;
        foreach ($rows as $i => $r) {
            $cells = array_map('trim', preg_split('/\|(?<!\\\)/', $r));
            // 去掉首尾空元素（由前后 | 产生）
            if (count($cells) >= 2 && $cells[0] === '' && $cells[count($cells) - 1] === '') {
                array_shift($cells);
                array_pop($cells);
            }
            $cells = array_map(function ($c) {
                return preg_replace('/\\\\\|/', '|', trim($c));
            }, $cells);
            if ($i === 0) {
                $header = $cells;
                continue;
            }
            // 分隔行（---|---）跳过
            if (preg_match('/^[:\- ]+$/', implode('', $cells)) || preg_match('/^-+$/', str_replace('|', '', $r))) {
                continue;
            }
            $html .= '<tr>';
            foreach ($cells as $c) {
                if ($header === null) {
                    $html .= '<td>' . uic_md_inline($c) . '</td>';
                } else {
                    $html .= '<td>' . uic_md_inline($c) . '</td>';
                }
            }
            $html .= '</tr>';
        }
        $html .= '</table>';
        return $html;
    }

    function uic_md_to_html($md)
    {
        if (!is_string($md)) {
            return '';
        }
        $lines = preg_split('/\r\n|\n|\r/', $md);
        $out = '';
        $i = 0;
        $n = count($lines);
        $inCode = false;
        $codeBuf = [];
        $codeLang = '';

        while ($i < $n) {
            $line = $lines[$i];

            // 代码块围栏
            if (preg_match('/^```(\w*)\s*$/', $line, $m)) {
                if (!$inCode) {
                    $inCode = true;
                    $codeLang = $m[1];
                    $codeBuf = [];
                } else {
                    $inCode = false;
                    $out .= '<pre class="md-pre"><code' . ($codeLang ? ' data-lang="' . $codeLang . '"' : '') . '>'
                        . uic_md_escape(implode("\n", $codeBuf)) . '</code></pre>';
                    $codeBuf = [];
                }
                $i++;
                continue;
            }
            if ($inCode) {
                $codeBuf[] = $line;
                $i++;
                continue;
            }

            $trim = trim($line);

            // 空行
            if ($trim === '') {
                $i++;
                continue;
            }

            // 分隔线
            if (preg_match('/^(\-\-\-|\*\*\*|___)\s*$/', $trim)) {
                $out .= '<hr>';
                $i++;
                continue;
            }

            // 标题
            if (preg_match('/^(#{1,6})\s+(.*)$/', $trim, $m)) {
                $lv = strlen($m[1]);
                $out .= '<h' . $lv . '>' . uic_md_inline($m[2]) . '</h' . $lv . '>';
                $i++;
                continue;
            }

            // 引用
            if (preg_match('/^>\s?(.*)$/', $trim, $m)) {
                $buf = [$m[1]];
                $i++;
                while ($i < $n && preg_match('/^>\s?(.*)$/', trim($lines[$i]), $mm)) {
                    $buf[] = $mm[1];
                    $i++;
                }
                $out .= '<blockquote>' . uic_md_inline(implode(' ', $buf)) . '</blockquote>';
                continue;
            }

            // 表格（当前行与下一行构成表头+分隔）
            if (strpos($trim, '|') !== false && $i + 1 < $n && strpos(trim($lines[$i + 1]), '|') !== false
                && preg_match('/^[\|\-: ]+$/', str_replace('|', '', trim($lines[$i + 1])))) {
                $trows = [$lines[$i]];
                $i++;
                while ($i < $n && trim($lines[$i]) !== '' && strpos(trim($lines[$i]), '|') !== false) {
                    $trows[] = $lines[$i];
                    $i++;
                }
                $out .= uic_md_table($trows);
                continue;
            }

            // 无序列表
            if (preg_match('/^(\s*)([-*+])\s+(.*)$/', $line, $m)) {
                $buf = [];
                while ($i < $n && preg_match('/^(\s*)([-*+])\s+(.*)$/', $lines[$i], $mm)) {
                    $buf[] = $mm[3];
                    $i++;
                }
                $out .= '<ul class="md-ul">' . implode('', array_map(fn($x) => '<li>' . uic_md_inline($x) . '</li>', $buf)) . '</ul>';
                continue;
            }

            // 有序列表
            if (preg_match('/^(\s*)(\d+)\.\s+(.*)$/', $line, $m)) {
                $buf = [];
                while ($i < $n && preg_match('/^(\s*)(\d+)\.\s+(.*)$/', $lines[$i], $mm)) {
                    $buf[] = $mm[3];
                    $i++;
                }
                $out .= '<ol class="md-ol">' . implode('', array_map(fn($x) => '<li>' . uic_md_inline($x) . '</li>', $buf)) . '</ol>';
                continue;
            }

            // 段落（合并连续非空非特殊行）
            $buf = [$trim];
            $i++;
            while ($i < $n && trim($lines[$i]) !== '' && !preg_match('/^(#{1,6}\s|>\s|```|(\s*[-*+]\s)|(\s*\d+\.\s)|(\-\-\-|\*\*\*|___)\s*$)/', trim($lines[$i]))) {
                $buf[] = trim($lines[$i]);
                $i++;
            }
            $out .= '<p>' . uic_md_inline(implode(' ', $buf)) . '</p>';
        }

        if ($inCode) {
            $out .= '<pre class="md-pre"><code>' . uic_md_escape(implode("\n", $codeBuf)) . '</code></pre>';
        }
        return $out;
    }
}
