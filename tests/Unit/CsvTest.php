<?php

declare(strict_types=1);

namespace Orchestra\Projects\Tests\Unit;

use Orchestra\Projects\Export\Csv;
use PHPUnit\Framework\TestCase;

final class CsvTest extends TestCase
{
    public function testStartsWithAByteOrderMarkSoExcelReadsUtf8(): void
    {
        $this->assertStringStartsWith("\u{FEFF}\"Müller\"", Csv::document([['Müller']], ','));
    }

    public function testQuotesEveryCellAndEndsLinesForWindows(): void
    {
        $this->assertSame(
            "\u{FEFF}\"Last name\";\"Notes\"\r\n\"Muster\";\"Says \"\"hi\"\"; twice\nand more\"\r\n",
            Csv::document([['Last name', 'Notes'], ['Muster', "Says \"hi\"; twice\r\nand more"]], ';')
        );
    }

    public function testKeepsExcelFromRunningValuesAsFormulas(): void
    {
        $document = Csv::document([['=HYPERLINK("x")', '+1', '-', '@SUM(A1)', 'a=b']], ',');

        $this->assertSame(
            "\u{FEFF}\"'=HYPERLINK(\"\"x\"\")\",\"'+1\",\"'-\",\"'@SUM(A1)\",\"a=b\"\r\n",
            $document
        );
    }

    public function testUsesTheTranslatedSeparator(): void
    {
        $this->assertSame(',', Csv::separator());
    }
}
