<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\Settings;

use OCA\OcrFlow\Settings\PersonalSection;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The section id is not cosmetic: `/settings/user/<id>` is the URL the
 * integration smoke loads, and `<id>` must match the id declared in info.xml or
 * the section appears under a name nothing can link to.
 */
class PersonalSectionTest extends TestCase
{
    private function section(): PersonalSection
    {
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(static fn (string $text): string => $text);

        $url = $this->createMock(IURLGenerator::class);
        $url->method('imagePath')->willReturn('/apps/ocrflow/img/app-dark.svg');

        return new PersonalSection($l, $url);
    }

    public function testSectionIdIsTheAppId(): void
    {
        $this->assertSame('ocrflow', $this->section()->getID());
    }

    public function testNameIsTranslated(): void
    {
        $this->assertSame('OCR Flow', $this->section()->getName());
    }

    public function testIconIsTheDarkAppIcon(): void
    {
        // Settings sections render on both themes; the dark icon is the one
        // Nextcloud inverts, so pointing at app.svg here shows a black square.
        $this->assertSame('/apps/ocrflow/img/app-dark.svg', $this->section()->getIcon());
    }

    public function testPrioritySitsAfterFilesAndBeforeSecurity(): void
    {
        $priority = $this->section()->getPriority();
        $this->assertGreaterThan(0, $priority);
        $this->assertLessThan(100, $priority);
    }
}
