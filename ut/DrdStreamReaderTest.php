<?php
use Oasis\Mlib\AwsWrappers\DrdStreamReader;
use Oasis\Mlib\AwsWrappers\RedshiftHelper;

/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2016-01-13
 * Time: 15:58
 */
class DrdStreamReaderTest extends PHPUnit_Framework_TestCase
{
    public static $file;
    public static $objects = [
        [
            "name" => "Josh",
            "age"  => 13,
            "job"  => "The o'looka police",
        ],
        [
            "name" => "Martin",
            "age"  => 3,
        ],
        [
            "name" => "O'riel",
            "age"  => 21,
            "job"  => "pub | priv",
        ],
        [
            "name" => "Nanting",
            "job"  => "glad to\nwin",
        ],
        [
            "age" => 0,
            "job" => "not yet born",
        ],
        [],
    ];
    public static $fields  = [
        "name",
        "age",
        "job",
    ];

    public static function setUpBeforeClass()
    {
        self::$file = sys_get_temp_dir() . sprintf("/drdtest.%s.%s.drd", time(), getmypid());
    }

    public static function tearDownAfterClass()
    {
        unlink(self::$file);
    }

    public function testOutput()
    {

        $fh = fopen(self::$file, 'w');
        foreach (self::$objects as $obj) {
            $line = RedshiftHelper::formatToRedshiftLine($obj, self::$fields);
            fwrite($fh, $line . PHP_EOL);
        }
        fclose($fh);

        $content = <<<FILE
Josh|13|The o'looka police
Martin|3|
O'riel|21|pub \\| priv
Nanting||glad to\\
win
|0|not yet born
||

FILE;
        $this->assertStringEqualsFile(self::$file, $content);

        return $content;
    }

    /**
     * @depends testOutput
     *
     * @param $content
     */
    public function testReader($content)
    {
        file_put_contents(self::$file, $content);
        $fh     = fopen(self::$file, 'r');
        $reader = new DrdStreamReader($fh, self::$fields);

        $rec = $reader->readRecord();
        $this->assertNotFalse($rec);
        $this->assertEquals('Josh', $rec['name']);
        $this->assertEquals(13, $rec['age']);
        $this->assertEquals('The o\'looka police', $rec['job']);

        $rec = $reader->readRecord();
        $this->assertNotFalse($rec);
        $this->assertEquals('Martin', $rec['name']);
        $this->assertEquals(3, $rec['age']);
        $this->assertEquals('', $rec['job']);

        $rec = $reader->readRecord();
        $this->assertNotFalse($rec);
        $this->assertEquals('O\'riel', $rec['name']);
        $this->assertEquals(21, $rec['age']);
        $this->assertEquals('pub | priv', $rec['job']);

        $rec = $reader->readRecord();
        $this->assertNotFalse($rec);
        $this->assertEquals('Nanting', $rec['name']);
        $this->assertEquals('', $rec['age']);
        $this->assertEquals("glad to\nwin", $rec['job']);

        $rec = $reader->readRecord();
        $this->assertNotFalse($rec);
        $this->assertEquals('', $rec['name']);
        $this->assertEquals(0, $rec['age']);
        $this->assertEquals("not yet born", $rec['job']);

        $rec = $reader->readRecord();
        $this->assertNotFalse($rec);
        $this->assertEquals('', $rec['name']);
        $this->assertEquals('', $rec['age']);
        $this->assertEquals("", $rec['job']);

        $rec = $reader->readRecord();
        $this->assertFalse($rec);
    }
}
