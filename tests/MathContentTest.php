<?php

declare(strict_types=1);

namespace Tests;

use App\Services\MathContent;
use PHPUnit\Framework\TestCase;

final class MathContentTest extends TestCase
{
    public function testSupportedExpressionsBecomeSafeReadableHtml(): void
    {
        $html = MathContent::html('2^3 = 8; x^{2}; \\log_2 8 = 3; \\frac{1}{2}; \\sqrt{25} = 5');

        self::assertStringContainsString('2<sup>3</sup>', $html);
        self::assertStringContainsString('x<sup>2</sup>', $html);
        self::assertStringContainsString('<span class="math-op">log</span><sub>2</sub>', $html);
        self::assertStringContainsString('class="math-frac"', $html);
        self::assertStringContainsString('class="math-root"', $html);
    }

    public function testOrdinaryTextAndMalformedMathRemainSafe(): void
    {
        $html = MathContent::html('user_name costs $10.00; broken \\frac{1 and <script>alert(1)</script>');
        self::assertStringContainsString('user_name costs $10.00', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('alert(1)', $html);
    }

    public function testScientificNotationAcrossSubjectsIsRenderedWithoutGuessingOrdinaryText(): void
    {
        $source = 'Physics: \\vec{F}=m\\alpha, \\Delta v=\\sqrt{2gh}, 3.0\\times10^{8} m/s; '
            . 'Chemistry: H_2O + CO_2 \\rightleftharpoons H_2CO_3 (aq), SO_4^{2-}; '
            . 'Biology: BRCA1, DNA, Homo sapiens, 5\\prime-ATGC-3\\prime; user_name and H2O.';
        $html = MathContent::html($source);

        self::assertStringContainsString('F', $html);
        self::assertStringContainsString('F⃗', $html);
        self::assertStringContainsString('α', $html);
        self::assertStringContainsString('Δ', $html);
        self::assertStringContainsString('3.0×10<sup>8</sup> m/s', $html);
        self::assertStringContainsString('H<sub>2</sub>O', $html);
        self::assertStringContainsString('SO<sub>4</sub><sup>2-</sup>', $html);
        self::assertStringContainsString('⇌', $html);
        self::assertStringContainsString('5′-ATGC-3′', $html);
        self::assertStringContainsString('BRCA1', $html);
        self::assertStringContainsString('user_name', $html);
        self::assertStringContainsString('H2O', $html);
    }
}