<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Tests\Unit;

use Axitdev\PhpUnitAiEvals\Dataset;
use Axitdev\PhpUnitAiEvals\Tests\Support\TempDirTrait;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DatasetTest extends TestCase
{
    use TempDirTrait;

    private function file(string $name, string $contents): string
    {
        file_put_contents($this->tmp . '/' . $name, $contents);

        return $this->tmp . '/' . $name;
    }

    public function testJsonListIsKeyedByIdOrPosition(): void
    {
        $path = $this->file('cases.json', '[{"id":"refund-404","input":"a"},{"input":"b"}]');

        self::assertSame(['refund-404' => ['id' => 'refund-404', 'input' => 'a'], 1 => ['input' => 'b']], Dataset::load($path));
        self::assertSame(['refund-404' => [['id' => 'refund-404', 'input' => 'a']], 1 => [['input' => 'b']]], Dataset::provide($path));
    }

    public function testJsonLinesSkipsBlankLines(): void
    {
        $path = $this->file('cases.jsonl', "{\"input\":\"a\",\"expected\":{\"x\":1}}\n\n{\"input\":\"b\"}\n");

        self::assertSame([['input' => 'a', 'expected' => ['x' => 1]], ['input' => 'b']], Dataset::load($path));
    }

    public function testCsvUsesHeaderRowAndHandlesQuotesAndShortRows(): void
    {
        $path = $this->file('cases.csv', "id,input,expected\nc1,\"Hello, world\",positive\n\nc2,short\n");

        self::assertSame([
            'c1' => ['id' => 'c1', 'input' => 'Hello, world', 'expected' => 'positive'],
            'c2' => ['id' => 'c2', 'input' => 'short', 'expected' => ''],
        ], Dataset::load($path));
    }

    public function testCsvKeepsMultiLineFieldsAndTrailingBackslashes(): void
    {
        $path = $this->file('multi.csv', "id,text\n1,\"line one\nline two\"\n2,\"C:\\dir\\\"\n");

        self::assertSame(['1' => ['id' => '1', 'text' => "line one\nline two"], '2' => ['id' => '2', 'text' => 'C:\\dir\\']], Dataset::load($path));
    }

    public function testJsonObjectsAreKeyedByTheirNamesUnlessACaseHasAnId(): void
    {
        $path = $this->file('named.json', '{"refund":{"input":"a"},"greeting":{"id":"hello","input":"b"}}');

        self::assertSame(['refund' => ['input' => 'a'], 'hello' => ['id' => 'hello', 'input' => 'b']], Dataset::load($path));
    }

    public function testByteOrderMarksAreIgnored(): void
    {
        $bom = "\xEF\xBB\xBF";

        self::assertSame(['c1' => ['id' => 'c1', 'input' => 'hello']], Dataset::load($this->file('excel.csv', $bom . "id,input\r\nc1,hello\r\n")));
        self::assertSame(['c1' => ['id' => 'c1']], Dataset::load($this->file('bom.json', $bom . '[{"id":"c1"}]')));
        self::assertSame(['c1' => ['id' => 'c1']], Dataset::load($this->file('bom.jsonl', $bom . "{\"id\":\"c1\"}\r\n")));
    }

    public function testRejectsDuplicateIds(): void
    {
        $this->expectExceptionMessage('more than one case with id "a"');
        Dataset::load($this->file('dup.jsonl', "{\"id\":\"a\",\"x\":1}\n{\"id\":\"a\",\"x\":2}\n"));
    }

    public function testRejectsMissingFilesAndUnknownFormats(): void
    {
        try {
            Dataset::load($this->tmp . '/missing.json');
            self::fail('expected exception');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('does not exist', $e->getMessage());
        }

        $this->expectExceptionMessage('Unsupported dataset format');
        Dataset::load($this->file('cases.yaml', 'x: 1'));
    }

    public function testFormatIsCaseInsensitiveAndBlankLinesAreSkipped(): void
    {
        self::assertSame(['a' => ['x' => 1]], Dataset::load($this->file('upper.JSON', '{"a":{"x":1}}')));
        self::assertSame([['x' => 1]], Dataset::load($this->file('spaces.jsonl', "{\"x\":1}\n   \n")));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function malformed(): iterable
    {
        yield 'json scalar case' => ['bad.json', '{"a":{"x":1},"b":5}', 'every case must be a JSON object, got 5'];
        yield 'json list case' => ['bad.json', '[[1,2]]', 'got [1,2]'];
        yield 'json scalar file' => ['bad.json', '"cases"', 'must hold a list or an object of cases'];
        yield 'jsonl scalar line' => ['bad.jsonl', "{\"x\":1}\n\n7\n", 'bad.jsonl line 3: every case must be a JSON object, got 7'];
        yield 'csv extra fields' => ['wide.csv', "id,v\nc0,a\nc1,a,extra\n", 'row 3 has 3 fields but the header has 2'];
        yield 'csv duplicate header' => ['dup.csv', "id,v,v\nc1,a,b\n", 'names a column more than once'];
    }

    #[DataProvider('malformed')]
    public function testMalformedCasesAreRejectedInsteadOfDropped(string $name, string $content, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        Dataset::load($this->file($name, $content));
    }
}
