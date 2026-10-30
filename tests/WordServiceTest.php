<?php

namespace Tests;

use App\Services\WordService;
use PHPUnit\Framework\TestCase;
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
                '<html><head><style>h2 { text-align: center; }</style></head><body><h2>Sample Paper</h2><p>Question one: x<sup>2</sup></p></body></html>',
                $path
            );

            $archive = new ZipArchive();
            self::assertTrue($archive->open($path));
            $document = $archive->getFromName('word/document.xml');
            $archive->close();

            self::assertIsString($document);
            self::assertStringContainsString('Sample Paper', $document);
            self::assertStringContainsString('Question one:', $document);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
