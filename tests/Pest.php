<?php

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function fakePng(string $filename = 'avatar.png', int $width = 512, int $height = 512): UploadedFile
{
    $ihdr = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);
    $ihdrChunk = pack('N', strlen($ihdr)).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr));
    $rawRow = "\x00".str_repeat("\x80\x80\x80", $width);
    $rawImage = str_repeat($rawRow, $height);
    $compressed = (string) gzcompress($rawImage);
    $idatChunk = pack('N', strlen($compressed)).'IDAT'.$compressed.pack('N', crc32('IDAT'.$compressed));
    $iendChunk = pack('N', 0).'IEND'.pack('N', crc32('IEND'));
    $png = "\x89PNG\r\n\x1a\n".$ihdrChunk.$idatChunk.$iendChunk;

    return UploadedFile::fake()->createWithContent($filename, $png);
}
