<?php

declare(strict_types=1);

use Codeception\Lib\ModuleContainer;
use Codeception\Module\Filesystem;
use Codeception\PHPUnit\TestCase;
use Codeception\Stub;
use PHPUnit\Framework\AssertionFailedError;

if (!function_exists('make_container')) {
    function make_container(): ModuleContainer
    {
        return Stub::make(ModuleContainer::class);
    }
}

final class FilesystemTest extends TestCase
{
    protected ?Filesystem $module = null;

    private string $originalCwd = '';

    private string $tempRoot = '';

    public function _setUp(): void
    {
        $this->originalCwd = (string)getcwd();
        $this->tempRoot = sys_get_temp_dir() . '/module-filesystem-' . bin2hex(random_bytes(8));
        mkdir($this->tempRoot, 0777, true);

        $this->module = new Filesystem(make_container());
        $this->module->_before(Stub::makeEmpty(\Codeception\Test\Test::class));
    }

    public function _tearDown(): void
    {
        if ($this->originalCwd !== '') {
            chdir($this->originalCwd);
        }
        if ($this->tempRoot !== '' && is_dir($this->tempRoot)) {
            $this->removeDirectory($this->tempRoot);
        }
        $this->module?->_after(Stub::makeEmpty(\Codeception\Test\Test::class));
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($full) && !is_link($full)) {
                $this->removeDirectory($full);
            } else {
                unlink($full);
            }
        }
        rmdir($path);
    }

    private function writeFile(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($path, $contents);
    }

    public function testAmInPathAbsoluteDirectoryChangesCwd(): void
    {
        $this->module->amInPath($this->tempRoot);
        $cwd = getcwd();
        $this->assertIsString($cwd);
        $this->assertSame(realpath($this->tempRoot), realpath($cwd));
    }

    public function testAmInPathRelativeUsesModulePath(): void
    {
        $sub = $this->tempRoot . '/sub';
        mkdir($sub, 0777, true);
        $this->module->amInPath($this->tempRoot);
        $this->module->amInPath('sub');
        $cwd = getcwd();
        $this->assertIsString($cwd);
        $this->assertSame(realpath($sub), realpath($cwd));
    }

    public function testAmInPathMissingDirectoryFailsAndLeavesCwd(): void
    {
        $cwdBefore = getcwd();
        $this->assertIsString($cwdBefore);
        try {
            $this->module->amInPath($this->tempRoot . '/missing');
            $this->fail('Expected amInPath to fail for missing directory');
        } catch (AssertionFailedError $e) {
            $this->assertSame('directory not found', $e->getMessage());
        }
        $cwdAfter = getcwd();
        $this->assertIsString($cwdAfter);
        $this->assertSame($cwdBefore, $cwdAfter);
    }

    public function testAmInPathOnAFileFails(): void
    {
        $file = $this->tempRoot . '/not-a-dir.txt';
        $this->writeFile($file, 'x');
        $cwdBefore = getcwd();
        $this->assertIsString($cwdBefore);
        try {
            $this->module->amInPath($file);
            $this->fail('Expected amInPath to fail for a file path');
        } catch (AssertionFailedError $e) {
            $this->assertSame('directory not found', $e->getMessage());
        }
        $cwdAfter = getcwd();
        $this->assertIsString($cwdAfter);
        $this->assertSame($cwdBefore, $cwdAfter);
    }

    public function testOpenFileRelativeAfterAmInPath(): void
    {
        $this->writeFile($this->tempRoot . '/note.txt', 'hello-root');
        $this->writeFile($this->tempRoot . '/sub/note.txt', 'hello-sub');
        $this->module->amInPath($this->tempRoot);
        $this->module->amInPath('sub');
        $this->module->openFile('note.txt');
        $this->module->seeFileContentsEqual('hello-sub');
    }

    public function testOpenFileAbsoluteIgnoresModulePath(): void
    {
        $dirA = $this->tempRoot . '/a';
        $dirB = $this->tempRoot . '/b';
        mkdir($dirA, 0777, true);
        mkdir($dirB, 0777, true);
        $fileB = $dirB . '/only.txt';
        $this->writeFile($fileB, 'from-b');
        $this->module->amInPath($dirA);
        $this->module->openFile($fileB);
        $this->module->seeFileContentsEqual('from-b');
    }

    public function testOpenFileMissingFails(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('file not found');
        $this->module->openFile($this->tempRoot . '/missing.txt');
    }

    public function testOpenFileOnDirectoryFails(): void
    {
        $dir = $this->tempRoot . '/adir';
        mkdir($dir, 0777, true);
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('file not found');
        $this->module->openFile($dir);
    }

    public function testSeeInThisFileAndDontSeeInThisFile(): void
    {
        $file = $this->tempRoot . '/buf.txt';
        $this->writeFile($file, 'alpha beta');
        $this->module->openFile($file);
        $this->module->seeInThisFile('alpha');
        try {
            $this->module->seeInThisFile('ALPHA');
            $this->fail('Expected seeInThisFile to fail for wrong case');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString("No text 'ALPHA' in currently opened file", $e->getMessage());
        }
        $this->module->dontSeeInThisFile('gamma');
        try {
            $this->module->dontSeeInThisFile('beta');
            $this->fail('Expected dontSeeInThisFile to fail');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString("Found text 'beta' in currently opened file", $e->getMessage());
        }
    }

    public function testSeeFileContentsEqualIsExactAndStripsCrFromFileOnly(): void
    {
        $file = $this->tempRoot . '/crlf.txt';
        $this->writeFile($file, "a\r\nb");
        $this->module->openFile($file);
        $this->module->seeFileContentsEqual("a\nb");
        foreach (["a\r\nb", "a\nb extra", "a\n"] as $wrong) {
            try {
                $this->module->seeFileContentsEqual($wrong);
                $this->fail('Expected seeFileContentsEqual to reject: ' . $wrong);
            } catch (AssertionFailedError) {
            }
        }
    }

    public function testSeeThisFileMatches(): void
    {
        $file = $this->tempRoot . '/match.txt';
        $this->writeFile($file, 'zzz-cat-zzz');
        $this->module->openFile($file);
        $this->module->seeThisFileMatches('/cat/');
        try {
            $this->module->seeThisFileMatches('/dog/');
            $this->fail('Expected seeThisFileMatches to fail');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString("Contents of currently opened file does not match '/dog/'", $e->getMessage());
        }
    }

    public function testWriteToFileAbsolutePersistsBytes(): void
    {
        $path = $this->tempRoot . '/abs-write.txt';
        $bytes = "line1\nline2";
        $this->module->writeToFile($path, $bytes);
        $this->assertTrue(is_file($path));
        $this->assertSame($bytes, file_get_contents($path));
        $this->module->openFile($path);
        $this->module->seeFileContentsEqual($bytes);
    }

    public function testWriteToFileRelativeAfterAmInPath(): void
    {
        $this->module->amInPath($this->tempRoot);
        $this->module->writeToFile('out.txt', 'via-cwd');
        $path = $this->tempRoot . '/out.txt';
        $this->assertTrue(is_file($path));
        $this->assertSame('via-cwd', file_get_contents($path));
    }

    public function testDeleteFileRemovesOnlyThatFile(): void
    {
        $target = $this->tempRoot . '/target.txt';
        $sibling = $this->tempRoot . '/sibling.txt';
        $this->writeFile($target, 'gone');
        $this->writeFile($sibling, 'stay');
        $this->assertTrue(is_file($target));
        $this->assertTrue(is_file($sibling));
        $this->module->deleteFile($target);
        $this->assertFalse(file_exists($target));
        $this->assertSame('stay', file_get_contents($sibling));
    }

    public function testDeleteFileMissingFails(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('file not found');
        $this->module->deleteFile($this->tempRoot . '/nope.txt');
    }

    public function testDeleteFileOnDirectoryFailsAndKeepsTree(): void
    {
        $dir = $this->tempRoot . '/deldir';
        $child = $dir . '/child.txt';
        mkdir($dir, 0777, true);
        $this->writeFile($child, 'child-data');
        try {
            $this->module->deleteFile($dir);
            $this->fail('Expected deleteFile to fail for a directory');
        } catch (AssertionFailedError $e) {
            $this->assertSame('file not found', $e->getMessage());
        }
        $this->assertTrue(is_file($child));
        $this->assertSame('child-data', file_get_contents($child));
    }

    public function testDeleteFileSymlinkRemovesLinkNotTarget(): void
    {
        $target = $this->tempRoot . '/target.txt';
        $link = $this->tempRoot . '/link.txt';
        $this->writeFile($target, 'target');
        symlink($target, $link);
        $this->assertTrue(is_link($link));
        $this->module->deleteFile($link);
        $this->assertFalse(is_link($link));
        $this->assertTrue(is_file($target));
        $this->assertSame('target', file_get_contents($target));
    }

    public function testDeleteThisFileRemovesOpenedAbsoluteFile(): void
    {
        $file = $this->tempRoot . '/deleteme.txt';
        $this->writeFile($file, 'x');
        $this->module->openFile($file);
        $this->module->deleteThisFile();
        $this->assertFalse(file_exists($file));
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('file not found');
        $this->module->deleteThisFile();
    }

    public function testSeeFileFoundAbsoluteFileOpensIt(): void
    {
        $file = $this->tempRoot . '/found.txt';
        $this->writeFile($file, 'payload');
        $this->module->seeFileFound($file);
        $this->module->seeFileContentsEqual('payload');
    }

    public function testSeeFileFoundRecursesUnderGivenDirectory(): void
    {
        $dir = $this->tempRoot . '/search';
        $nested = $dir . '/sub/nested.txt';
        $this->writeFile($nested, 'deep');
        $this->module->seeFileFound('nested.txt', $dir);
        $this->module->seeFileContentsEqual('deep');
    }

    public function testSeeFileFoundMissingFile(): void
    {
        $dir = $this->tempRoot . '/empty-search';
        mkdir($dir, 0777, true);
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage(sprintf('File "missing.txt" not found at "%s"', $dir));
        $this->module->seeFileFound('missing.txt', $dir);
    }

    public function testSeeFileFoundMissingDirectory(): void
    {
        $missing = $this->tempRoot . '/no-such-dir';
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Directory does not exist: ' . $missing);
        $this->module->seeFileFound('x.txt', $missing);
    }

    public function testDontSeeFileFoundWhenNameIsAbsent(): void
    {
        $dir = $this->tempRoot . '/absent';
        mkdir($dir, 0777, true);
        $this->module->dontSeeFileFound('missing.txt', $dir);
        $this->addToAssertionCount(1);
    }

    public function testDontSeeFileFoundWhenAbsoluteFileExists(): void
    {
        $file = $this->tempRoot . '/present-on-disk.txt';
        $this->writeFile($file, 'x');
        try {
            $this->module->dontSeeFileFound($file);
            $this->fail('Expected dontSeeFileFound to fail');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString($file, $e->getMessage());
        }
    }

    public function testDontSeeFileFoundWhenNestedFileExists(): void
    {
        $dir = $this->tempRoot . '/nested-search';
        $nested = $dir . '/sub/nested.txt';
        $this->writeFile($nested, 'n');
        try {
            $this->module->dontSeeFileFound('nested.txt', $dir);
            $this->fail('Expected dontSeeFileFound to fail for nested file');
        } catch (AssertionFailedError $e) {
            $real = realpath($nested);
            $this->assertIsString($real);
            $this->assertStringContainsString($real, $e->getMessage());
        }
    }

    public function testDontSeeFileFoundMissingDirectory(): void
    {
        $missing = $this->tempRoot . '/missing-root';
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Directory does not exist: ' . $missing);
        $this->module->dontSeeFileFound('x.txt', $missing);
    }

    public function testDeleteDirRelativeAfterAmInPathRemovesTreeOnly(): void
    {
        $this->writeFile($this->tempRoot . '/tree/a.txt', 'a');
        $this->writeFile($this->tempRoot . '/tree/sub/b.txt', 'b');
        $this->writeFile($this->tempRoot . '/keep.txt', 'keep');
        $this->assertTrue(is_dir($this->tempRoot . '/tree'));
        $this->module->amInPath($this->tempRoot);
        $this->module->deleteDir('tree');
        $this->assertFalse(file_exists($this->tempRoot . '/tree'));
        $this->assertSame('keep', file_get_contents($this->tempRoot . '/keep.txt'));
    }

    public function testDeleteDirAbsolutePathIsNotPrefixed(): void
    {
        $dirA = $this->tempRoot . '/a';
        $dirB = $this->tempRoot . '/b';
        mkdir($dirA, 0777, true);
        mkdir($dirB, 0777, true);
        $this->writeFile($dirB . '/leaf.txt', 'leaf');
        $this->module->amInPath($dirA);
        $this->module->deleteDir($dirB);
        $this->assertFalse(file_exists($dirB));
        $this->assertTrue(is_dir($dirA));
    }

    public function testDeleteDirMissingPathLeavesNeighbors(): void
    {
        $missing = $this->tempRoot . '/not-there';
        $neighbor = $this->tempRoot . '/neighbor.txt';
        $this->writeFile($neighbor, 'neighbor');
        $this->module->deleteDir($missing);
        $this->assertSame('neighbor', file_get_contents($neighbor));
    }

    public function testCopyDirRelativeAfterAmInPathCopiesNestedBytes(): void
    {
        $this->writeFile($this->tempRoot . '/src/sub/a.txt', 'copied');
        $this->module->amInPath($this->tempRoot);
        $this->module->copyDir('src', 'dst');
        $dstFile = $this->tempRoot . '/dst/sub/a.txt';
        $this->assertTrue(is_file($dstFile));
        $this->assertSame('copied', file_get_contents($dstFile));
        file_put_contents($dstFile, 'changed');
        $this->assertSame('copied', file_get_contents($this->tempRoot . '/src/sub/a.txt'));
    }

    public function testCleanDirRelativeAfterAmInPath(): void
    {
        $logs = $this->tempRoot . '/logs';
        $this->writeFile($logs . '/keep.txt', 'remove-me');
        $this->writeFile($logs . '/sub/nested.txt', 'remove-me-too');
        $this->writeFile($logs . '/.gitignore', "ignored\n");
        $this->writeFile($logs . '/.gitkeep', '');
        $this->writeFile($this->tempRoot . '/outside.txt', 'outside');
        $this->module->amInPath($this->tempRoot);
        $this->module->cleanDir('logs');
        $this->assertTrue(is_dir($logs));
        $this->assertFalse(file_exists($logs . '/keep.txt'));
        $this->assertFalse(file_exists($logs . '/sub'));
        $this->assertSame("ignored\n", file_get_contents($logs . '/.gitignore'));
        $this->assertTrue(file_exists($logs . '/.gitkeep'));
        $this->assertSame('outside', file_get_contents($this->tempRoot . '/outside.txt'));
    }
}
