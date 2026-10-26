<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Service;

use OCA\OcrFlow\Service\Candidate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The name test that decides what a folder scan even looks at. It runs on every
 * upload and on every file of a scan, so it must be cheap and it must not open
 * anything.
 */
class CandidateTest extends TestCase
{
    #[DataProvider('processable')]
    public function testProcessableNames(string $name): void
    {
        $this->assertTrue((new Candidate())->isProcessable($name), $name . ' should be a candidate');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function processable(): array
    {
        return [
            'pdf' => ['scan.pdf'],
            'jpg' => ['photo.jpg'],
            'jpeg' => ['photo.jpeg'],
            'png' => ['shot.png'],
            'tif' => ['archive.tif'],
            'tiff' => ['archive.tiff'],
            'webp' => ['page.webp'],
            'uppercase extension' => ['SCAN.PDF'],
            'mixed case extension' => ['Scan.JpG'],
            'spaces in the name' => ['rent agreement 2026.pdf'],
            'cyrillic name' => ['договор аренды.pdf'],
            'already looks like ours' => ['photo(1).pdf'],
        ];
    }

    #[DataProvider('rejected')]
    public function testRejectedNames(string $name): void
    {
        $this->assertFalse((new Candidate())->isProcessable($name), $name . ' must not be a candidate');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rejected(): array
    {
        return [
            'empty name' => [''],
            'no extension' => ['Makefile'],
            'text file' => ['notes.txt'],
            'spreadsheet' => ['rents.xlsx'],
            'dotfile' => ['.hidden.pdf'],
            'dotfile without extension' => ['.gitignore'],
            'extension only' => ['.pdf'],
            'pdf inside the name but not the extension' => ['pdf-manual.docx'],
        ];
    }
}
