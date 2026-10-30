<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\Shared\Html;
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

        [$markup, $pageStyle] = self::prepareHtml($html);
        $word = new PhpWord();
        $word->setDefaultFontName('Arial');
        $word->setDefaultFontSize(11);
        $section = $word->addSection($pageStyle);
        Html::addHtml($section, $markup, false, false);
        IOFactory::createWriter($word, 'Word2007')->save($path);

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

        foreach (iterator_to_array($xpath->query('//script | //head/title | //head/meta') ?: []) as $node) {
            $node->parentNode?->removeChild($node);
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

        return [$markup, $pageStyle];
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
}
