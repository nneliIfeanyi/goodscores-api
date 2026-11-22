<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\Shared\Html;
use PhpOffice\PhpWord\Settings;
use RuntimeException;
use Throwable;
use ZipArchive;

class WordService
{
    public static function renderDocx(array $paper, array $user): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP ZIP extension is required for Word export');
        }

        $html = PdfService::buildHtml($paper, $user);
        $filename = 'paper_' . ($paper['id'] ?? 'draft') . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(16)) . '.docx';
        $directory = __DIR__ . '/../../storage/exports';
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create Word export directory');
        }

        $path = $directory . '/' . $filename;
        try {
            self::writeDocxFromHtml($html, $path);
        } catch (Throwable $error) {
            if (is_file($path) && !unlink($path)) {
                error_log('Could not remove incomplete Word export: ' . $path);
            }
            throw $error;
        }

        return [
            'path' => $path,
            'filename' => $filename,
            'relative' => 'exports/' . $filename,
        ];
    }

    public static function writeDocxFromHtml(string $html, string $path): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP ZIP extension is required for Word export');
        }

        [$markup, $pageStyle, $documentStyle] = self::prepareHtml($html);
        $word = new PhpWord();
        $word->setDefaultFontName($documentStyle['fontName']);
        $word->setDefaultFontSize($documentStyle['fontSize']);
        $section = $word->addSection($pageStyle);
        Html::addHtml($section, $markup, false, false);
        $outputEscapingEnabled = Settings::isOutputEscapingEnabled();
        Settings::setOutputEscapingEnabled(true);
        try {
            IOFactory::createWriter($word, 'Word2007')->save($path);
        } finally {
            Settings::setOutputEscapingEnabled($outputEscapingEnabled);
        }

        if (!is_file($path) || filesize($path) === 0) {
            throw new RuntimeException('Word export did not produce a document');
        }
    }

    private static function prepareHtml(string $html): array
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previousErrorMode = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML($html, LIBXML_NONET | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorMode);
        if (!$loaded) {
            throw new RuntimeException('Could not parse paper HTML for Word export');
        }

        $xpath = new DOMXPath($document);
        $body = $document->getElementsByTagName('body')->item(0);
        if (!$body) {
            throw new RuntimeException('Paper HTML has no document body');
        }

        $styles = [];
        foreach ($document->getElementsByTagName('style') as $style) {
            $styles[] = $style->textContent;
        }
        $pageStyle = self::getPageStyle(implode("\n", $styles));
        $documentStyle = self::getDocumentStyle(implode("\n", $styles));

        foreach (iterator_to_array($xpath->query('//script | //head/title | //head/meta') ?: []) as $node) {
            $node->parentNode?->removeChild($node);
        }
        foreach (iterator_to_array($xpath->query('//span[contains(concat(" ", normalize-space(@class), " "), " question-marks ")]') ?: []) as $marks) {
            $marks->parentNode?->removeChild($marks);
        }
        foreach (iterator_to_array($xpath->query('//ol[contains(concat(" ", normalize-space(@class), " "), " word-plain-instructions ")]') ?: []) as $list) {
            $parent = $list->parentNode;
            if (!$parent) {
                continue;
            }
            foreach ($xpath->query('./li', $list) ?: [] as $item) {
                $paragraph = $document->createElement('p');
                while ($item->firstChild) {
                    $paragraph->appendChild($item->firstChild);
                }
                $parent->insertBefore($paragraph, $list);
            }
            $parent->removeChild($list);
        }
        foreach (iterator_to_array($xpath->query('//h2[@data-word-section-index="1"]') ?: []) as $heading) {
            $title = trim($heading->textContent);
            if (!preg_match('/^Section\s+B\b/i', $title)) {
                if (preg_match('/^Section\s+[A-Z]\s*:?\s*(.*)$/i', $title, $matches)) {
                    $title = 'Section B' . ($matches[1] !== '' ? ': ' . trim($matches[1]) : '');
                } else {
                    $title = 'Section B' . ($title !== '' ? ': ' . $title : '');
                }
                while ($heading->firstChild) {
                    $heading->removeChild($heading->firstChild);
                }
                $heading->appendChild($document->createTextNode($title));
            }
        }
        foreach (iterator_to_array($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " word-inline-options ")]') ?: []) as $options) {
            $questionText = $xpath->query('../div[contains(concat(" ", normalize-space(@class), " "), " word-question-text ")]', $options)?->item(0);
            if (!$questionText) {
                continue;
            }

            $optionItems = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " inline-option ")]', $options);
            if (!$optionItems || $optionItems->length === 0) {
                continue;
            }

            $paragraphs = $questionText->getElementsByTagName('p');
            $questionParagraph = $paragraphs->length > 0
                ? $paragraphs->item($paragraphs->length - 1)
                : $document->createElement('p');
            if ($paragraphs->length === 0) {
                while ($questionText->firstChild) {
                    $questionParagraph->appendChild($questionText->firstChild);
                }
                $questionText->appendChild($questionParagraph);
            }

            foreach ($optionItems as $optionItem) {
                $marks = $xpath->query('.//span[contains(concat(" ", normalize-space(@class), " "), " question-marks ")]', $questionParagraph)?->item(0);
                $questionParagraph->insertBefore($document->createTextNode(' '), $marks);
                $questionParagraph->insertBefore($optionItem, $marks);
            }
            $options->parentNode?->removeChild($options);
        }
        foreach (iterator_to_array($xpath->query('//table[contains(concat(" ", normalize-space(@class), " "), " section-question-columns ")]') ?: []) as $table) {
            $parent = $table->parentNode;
            if (!$parent) {
                continue;
            }
            foreach ($xpath->query('.//td', $table) ?: [] as $cell) {
                while ($cell->firstChild) {
                    $parent->insertBefore($cell->firstChild, $table);
                }
            }
            $parent->removeChild($table);
        }
        foreach (iterator_to_array($xpath->query('//svg') ?: []) as $svg) {
            $placeholder = $document->createElement('p');
            $placeholder->appendChild($document->createTextNode('[Diagram not embedded in Word export]'));
            $svg->parentNode?->replaceChild($placeholder, $svg);
        }
        foreach (iterator_to_array($xpath->query('//img') ?: []) as $image) {
            if ($image instanceof DOMElement && self::useLocalImage($image)) {
                continue;
            }
            $image->parentNode?->removeChild($image);
        }

        $bodyMarkup = '';
        foreach ($body->childNodes as $child) {
            if ($child->nodeName === 'style') {
                continue;
            }
            $bodyMarkup .= $document->saveXML($child);
        }
        $stylesheet = implode("\n", $styles);
        $markup = $stylesheet !== ''
            ? '<style>' . htmlspecialchars($stylesheet, ENT_NOQUOTES | ENT_XML1, 'UTF-8') . '</style>' . $bodyMarkup
            : $bodyMarkup;

        return [$markup, $pageStyle, $documentStyle];
    }

    private static function useLocalImage(DOMElement $image): bool
    {
        $source = $image->getAttribute('data-local-src');
        $storage = realpath(__DIR__ . '/../../storage');
        $realSource = $source !== '' ? realpath($source) : false;
        if (!$storage || !$realSource || !is_file($realSource)) {
            return false;
        }

        $storagePrefix = rtrim($storage, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $allowed = DIRECTORY_SEPARATOR === '\\'
            ? str_starts_with(strtolower($realSource), strtolower($storagePrefix))
            : str_starts_with($realSource, $storagePrefix);
        if (!$allowed) {
            return false;
        }

        $image->setAttribute('src', $realSource);
        $image->removeAttribute('data-local-src');
        return true;
    }

    private static function getPageStyle(string $stylesheet): array
    {
        $matches = [];
        $pageSize = 'A4';
        $orientation = 'portrait';
        $margins = [10.0, 8.0, 10.0, 8.0];

        if (preg_match('/@page\s*\{\s*size:\s*(A4|LETTER|LEGAL)\s+(portrait|landscape);\s*margin:\s*([\d.]+)mm\s+([\d.]+)mm\s+([\d.]+)mm\s+([\d.]+)mm/i', $stylesheet, $matches)) {
            $pageSize = [
                'A4' => 'A4',
                'LETTER' => 'Letter',
                'LEGAL' => 'Legal',
            ][strtoupper($matches[1])];
            $orientation = strtolower($matches[2]);
            $margins = [(float)$matches[3], (float)$matches[4], (float)$matches[5], (float)$matches[6]];
        }

        return [
            'paperSize' => $pageSize,
            'orientation' => $orientation,
            'marginTop' => Converter::cmToTwip($margins[0] / 10),
            'marginRight' => Converter::cmToTwip($margins[1] / 10),
            'marginBottom' => Converter::cmToTwip($margins[2] / 10),
            'marginLeft' => Converter::cmToTwip($margins[3] / 10),
        ];
    }

    private static function getDocumentStyle(string $stylesheet): array
    {
        $fontName = 'Arial';
        $fontSize = 11.0;
        if (preg_match('/(?:^|})\s*body\s*\{([^}]*)\}/i', $stylesheet, $bodyStyle)) {
            if (preg_match('/(?:^|;)\s*font-family\s*:\s*([^;]+)/i', $bodyStyle[1], $fontFamily)) {
                $fontName = trim(explode(',', trim($fontFamily[1]))[0], " \t\n\r\0\x0B\"'");
            }
            if (preg_match('/(?:^|;)\s*font-size\s*:\s*([^;]+)/i', $bodyStyle[1], $fontSizeMatch)) {
                $fontSize = (float)Converter::cssToPoint(trim($fontSizeMatch[1]));
            }
        }

        return ['fontName' => $fontName, 'fontSize' => $fontSize];
    }
}
