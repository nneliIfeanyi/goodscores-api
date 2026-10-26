<?php

namespace App\Services;

final class MathContent
{
    private const TAGS = '<p><br><strong><b><em><i><u><ol><ul><li><span><sup><sub>';

    public static function html(mixed $value): string
    {
        $source = (string)$value;
        if ($source === '') return '';
        $parts = preg_split('/(<\/?[a-z][^>]*>)/i', $source, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$source];
        $html = '';
        foreach ($parts as $part) {
            if (preg_match('/^<\/?([a-z0-9]+)/i', $part, $match) && in_array(strtolower($match[1]), ['p','br','strong','b','em','i','u','ol','ul','li'], true)) {
                $html .= preg_replace('/\s+[^>]*>/','>', $part) ?: $part;
            } else {
                $html .= self::text($part);
            }
        }
        return strip_tags($html, self::TAGS);
    }

    private static function text(string $value): string
    {
        $text = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $symbols = [
            '\\alpha' => 'α', '\\beta' => 'β', '\\gamma' => 'γ', '\\delta' => 'δ',
            '\\theta' => 'θ', '\\lambda' => 'λ', '\\mu' => 'μ', '\\pi' => 'π',
            '\\rho' => 'ρ', '\\sigma' => 'σ', '\\phi' => 'φ', '\\omega' => 'ω',
            '\\Delta' => 'Δ', '\\Lambda' => 'Λ', '\\Omega' => 'Ω',
            '\\times' => '×', '\\cdot' => '·', '\\pm' => '±', '\\div' => '÷',
            '\\leq' => '≤', '\\le' => '≤', '\\geq' => '≥', '\\ge' => '≥',
            '\\neq' => '≠', '\\infty' => '∞', '\\rightarrow' => '→', '\\to' => '→',
            '\\leftarrow' => '←', '\\leftrightarrow' => '↔',
            '\\rightleftharpoons' => '⇌', '\\rightleftarrows' => '⇄',
            '\\prime' => '′',
        ];
        $text = strtr($text, $symbols);
        $text = preg_replace('/\\\\vec\{([^{}]+)\}/', '$1⃗', $text) ?? $text;
        $text = preg_replace('/\\\\frac\s*\{([^{}]*)\}\s*\{([^{}]*)\}/', '<span class="math-frac"><span>$1</span><span>$2</span></span>', $text) ?? $text;
        $text = preg_replace('/\\\\sqrt\s*\[([^\]]+)\]\s*\{([^{}]*)\}/', '<span class="math-root"><sup>$1</sup>√<span>$2</span></span>', $text) ?? $text;
        $text = preg_replace('/\\\\sqrt\s*\{([^{}]*)\}/', '<span class="math-root">√<span>$1</span></span>', $text) ?? $text;
        $text = preg_replace('/\\\\(log|ln|sin|cos|tan|lim)_\{([^{}]+)\}/', '<span class="math-op">$1</span><sub>$2</sub>', $text) ?? $text;
        $text = preg_replace('/\\\\(log|ln|sin|cos|tan|lim)_([A-Za-z0-9]+)/', '<span class="math-op">$1</span><sub>$2</sub>', $text) ?? $text;
        $text = preg_replace('/\\\\(log|ln|sin|cos|tan|lim)\b/', '<span class="math-op">$1</span>', $text) ?? $text;
        $text = preg_replace('/([A-Za-z]+)_\{([^{}]+)\}\^\{([^{}]+)\}/', '$1<sub>$2</sub><sup>$3</sup>', $text) ?? $text;
        $text = preg_replace('/([A-Za-z]+)_([0-9]+)\^\{([^{}]+)\}/', '$1<sub>$2</sub><sup>$3</sup>', $text) ?? $text;
        $text = preg_replace('/([A-Za-z0-9)]+)_\{([^{}]+)\}/', '$1<sub>$2</sub>', $text) ?? $text;
        $text = preg_replace('/([A-Za-z0-9)]+)_([0-9]+)/', '$1<sub>$2</sub>', $text) ?? $text;
        $text = preg_replace('/([A-Za-z0-9)]+)\^\{([^{}]+)\}/', '$1<sup>$2</sup>', $text) ?? $text;
        return preg_replace('/([A-Za-z0-9)]+)\^([A-Za-z0-9+−-]+)/', '$1<sup>$2</sup>', $text) ?? $text;
    }
}