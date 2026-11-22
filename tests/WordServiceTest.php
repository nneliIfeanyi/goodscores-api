<?php

namespace Tests;

use App\Services\WordService;
use PHPUnit\Framework\TestCase;
use PhpOffice\PhpWord\Shared\Converter;
use ZipArchive;

class WordServiceTest extends TestCase
{
    public function testCreatesAnEditableDocxFromPaperHtml(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('The PHP ZIP extension is required for DOCX export.');
        }

        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'goodscores-word-' . bin2hex(random_bytes(6)) . '.docx';
        try {
            WordService::writeDocxFromHtml(
                '<html><head><style>h2 { text-align: center; }</style></head><body><h2>Sample &amp; Paper</h2><p>Question one: x<sup>2</sup> &amp; y</p></body></html>',
                $path
            );

            $archive = new ZipArchive();
            self::assertTrue($archive->open($path));
            $document = $archive->getFromName('word/document.xml');
            $archive->close();

            self::assertIsString($document);
            self::assertStringContainsString('Sample &amp; Paper', $document);
            self::assertStringContainsString('Question one:', $document);
            $documentXml = new \DOMDocument();
            self::assertTrue($documentXml->loadXML($document));
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testAppliesPageAndFontSettingsAndUsesFullWidthForWordQuestions(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('The PHP ZIP extension is required for DOCX export.');
        }

        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'goodscores-word-settings-' . bin2hex(random_bytes(6)) . '.docx';
        try {
            WordService::writeDocxFromHtml(
                '<html><head><style>@page { size: LETTER landscape; margin: 15mm 20mm 25mm 30mm; } body { font-family: FreeSerif, serif; font-size: 14pt; }</style></head><body>'
                . '<table class="section-question-columns" width="100%"><tr><td class="question-column"><div class="word-question-text">First question <span class="question-marks">[2 marks]</span></div><div class="word-inline-options"><div><span class="inline-option"><strong>A.</strong> One</span></div><div><span class="inline-option"><strong>B.</strong> Two</span></div><div><span class="inline-option"><strong>C.</strong> Three</span></div><div><span class="inline-option"><strong>D.</strong> Four</span></div><div><span class="inline-option"><strong>E.</strong> Five</span></div></div></td><td class="question-column"><div class="word-question-text">Second question</div></td></tr></table>'
                . '</body></html>',
                $path
            );

            $archive = new ZipArchive();
            self::assertTrue($archive->open($path));
            $document = $archive->getFromName('word/document.xml');
            $styles = $archive->getFromName('word/styles.xml');
            $archive->close();

            self::assertIsString($document);
            self::assertIsString($styles);
            $documentXml = new \DOMDocument();
            self::assertTrue($documentXml->loadXML($document));
            $xpath = new \DOMXPath($documentXml);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

            self::assertLessThan(
                0.01,
                abs(Converter::cmToTwip(1.5) - (float)$xpath->evaluate('string(//w:sectPr/w:pgMar/@w:top)'))
            );
            self::assertLessThan(
                0.01,
                abs(Converter::cmToTwip(3) - (float)$xpath->evaluate('string(//w:sectPr/w:pgMar/@w:left)'))
            );
            self::assertSame(0.0, $xpath->evaluate('count(//w:tbl[.//w:t="First question" or .//w:t="Second question"])'));
            self::assertSame(
                1.0,
                $xpath->evaluate('count(//w:p[contains(., "First question") and contains(., "A.") and contains(., "One") and contains(., "B.") and contains(., "Two") and contains(., "C.") and contains(., "Three") and contains(., "D.") and contains(., "Four") and contains(., "E.") and contains(., "Five")])')
            );

            self::assertStringContainsString('w:ascii="FreeSerif"', $styles);
            self::assertStringContainsString('w:sz w:val="28"', $styles);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testFormatsWordInstructionsAndSectionBWithoutMarksOrPageBreaks(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('The PHP ZIP extension is required for DOCX export.');
        }

        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'goodscores-word-sections-' . bin2hex(random_bytes(6)) . '.docx';
        try {
            WordService::writeDocxFromHtml(
                '<html><body>'
                . '<ol class="word-plain-instructions"><li>Answer all questions.</li><li>Choose the best answer.</li></ol>'
                . '<h2 class="word-section-title" data-word-section-index="0">Section A: Objective</h2>'
                . '<div>Section A question</div>'
                . '<h2 class="word-section-title" data-word-section-index="1">Theory Questions</h2>'
                . '<div>Answer any three questions.</div>'
                . '<div class="word-question-text">Section B question one <span class="question-marks">[2 marks]</span></div>'
                . '<div class="word-question-text">Section B question two <span class="question-marks">[3 marks]</span></div>'
                . '</body></html>',
                $path
            );

            $archive = new ZipArchive();
            self::assertTrue($archive->open($path));
            $document = $archive->getFromName('word/document.xml');
            $archive->close();

            self::assertIsString($document);
            $documentXml = new \DOMDocument();
            self::assertTrue($documentXml->loadXML($document));
            $xpath = new \DOMXPath($documentXml);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

            self::assertStringNotContainsString('[2 marks]', $document);
            self::assertStringNotContainsString('[3 marks]', $document);
            self::assertSame(
                0.0,
                $xpath->evaluate('count(//w:p[contains(., "Answer all questions.") or contains(., "Choose the best answer.")]/w:pPr/w:numPr)')
            );
            self::assertLessThan(
                strpos($document, 'Answer any three questions.'),
                strpos($document, 'Section B: Theory Questions')
            );
            self::assertGreaterThan(
                strpos($document, 'Section A question'),
                strpos($document, 'Section B: Theory Questions')
            );
            self::assertSame(2.0, $xpath->evaluate('count(//w:p[contains(., "Section B question")])'));
            self::assertSame(0.0, $xpath->evaluate('count(//w:br[@w:type="page"] | //w:pageBreakBefore)'));
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
