<?php

declare (strict_types=1);
namespace Codeception\Module;

use Codeception\Configuration;
use Codeception\Module;
use Codeception\Php_Unit\Test_Case;
use Codeception\Test_Interface;
use Codeception\Util\File_System as Util;
use Php_Unit\Framework\Assertion_Failed_Error;
use Symfony\Component\Finder\Finder;
/**
 * Module for testing local filesystem.
 * Fork it to extend the module for FTP, Amazon S3, others.
 *
 * ## Status
 *
 * * Maintainer: **davert**
 * * Stability: **stable**
 * * Contact: codecept@davert.mail.ua
 *
 * Module was developed to test Codeception itself.
 */
class Filesystem extends Module
{
    protected string $file = '';
    protected string $file_path = '';
    protected string $path = '';
    public function _before(Test_Interface $test): void
    {
        $this->path = Configuration::project_dir();
    }
    /**
     * Enters a directory In local filesystem.
     * Project root directory is used by default
     */
    public function am_in_path(string $path): void
    {
        $this->path = $this->absolutize_path($path) . DIRECTORY_SEPARATOR;
        if (!file_exists($this->path)) {
            Test_Case::fail('directory not found');
        }
        chdir($this->path);
        $this->debug('Moved to ' . getcwd());
    }
    protected function absolutize_path(string $path): string
    {
        // *nix way
        if (str_starts_with($path, '/')) {
            return $path;
        }
        // windows
        if (strpos($path, ':\\') === 1) {
            return $path;
        }
        return $this->path . $path;
    }
    /**
     * Opens a file and stores it's content.
     *
     * Usage:
     *
     * ``` php
     * <?php
     * $I->openFile('composer.json');
     * $I->seeInThisFile('codeception/codeception');
     * ```
     */
    public function open_file(string $filename): void
    {
        $absolute_path = $this->absolutize_path($filename);
        if (!file_exists($absolute_path)) {
            Test_Case::fail('file not found');
        }
        $this->file = file_get_contents($absolute_path);
        $this->file_path = $filename;
    }
    /**
     * Deletes a file
     *
     * ``` php
     * <?php
     * $I->deleteFile('composer.lock');
     * ```
     */
    public function delete_file(string $filename): void
    {
        $absolute_path = $this->absolutize_path($filename);
        if (!file_exists($absolute_path)) {
            Test_Case::fail('file not found');
        }
        unlink($absolute_path);
    }
    /**
     * Deletes directory with all subdirectories
     *
     * ``` php
     * <?php
     * $I->deleteDir('vendor');
     * ```
     */
    public function delete_dir(string $dirname): void
    {
        $dir = $this->absolutize_path($dirname);
        Util::delete_dir($dir);
    }
    /**
     * Copies directory with all contents
     *
     * ``` php
     * <?php
     * $I->copyDir('vendor','old_vendor');
     * ```
     */
    public function copy_dir(string $src, string $dst): void
    {
        Util::copy_dir($src, $dst);
    }
    /**
     * Checks If opened file has `text` in it.
     *
     * Usage:
     *
     * ``` php
     * <?php
     * $I->openFile('composer.json');
     * $I->seeInThisFile('codeception/codeception');
     * ```
     */
    public function see_in_this_file(string $text): void
    {
        $this->assert_string_contains_string($text, $this->file, "No text '{$text}' in currently opened file");
    }
    /**
     * Checks If opened file has the `number` of new lines.
     *
     * Usage:
     *
     * ``` php
     * <?php
     * $I->openFile('composer.json');
     * $I->seeNumberNewLines(5);
     * ```
     *
     * @param int $number New lines
     */
    public function see_number_new_lines(int $number): void
    {
        $lines = preg_split('#[\n\r]#', $this->file);
        $this->assert_true($number === count($lines), "The number of new lines does not match with {$number}");
    }
    /**
     * Checks that contents of currently opened file matches $regex
     */
    public function see_this_file_matches(string $regex): void
    {
        $this->assert_reg_exp($regex, $this->file, "Contents of currently opened file does not match '{$regex}'");
    }
    /**
     * Checks the strict matching of file contents.
     * Unlike `seeInThisFile` will fail if file has something more than expected lines.
     * Better to use with HEREDOC strings.
     * Matching is done after removing "\r" chars from file content.
     *
     * ``` php
     * <?php
     * $I->openFile('process.pid');
     * $I->seeFileContentsEqual('3192');
     * ```
     */
    public function see_file_contents_equal(string $text): void
    {
        $file = str_replace("\r", '', $this->file);
        Test_Case::assert_equals($text, $file);
    }
    /**
     * Checks If opened file doesn't contain `text` in it
     *
     * ``` php
     * <?php
     * $I->openFile('composer.json');
     * $I->dontSeeInThisFile('codeception/codeception');
     * ```
     */
    public function dont_see_in_this_file(string $text): void
    {
        $this->assert_string_not_contains_string($text, $this->file, "Found text '{$text}' in currently opened file");
    }
    /**
     * Deletes a file
     */
    public function delete_this_file(): void
    {
        $this->delete_file($this->file_path);
    }
    /**
     * Checks if file exists in path.
     * Opens a file when it's exists
     *
     * ``` php
     * <?php
     * $I->seeFileFound('UserModel.php','app/models');
     * ```
     */
    public function see_file_found(string $filename, string $path = ''): void
    {
        if ($path === '' && file_exists($filename)) {
            $this->open_file($filename);
            Test_Case::assert_file_exists($filename);
            return;
        }
        $found = $this->find_file_in_path($filename, $path);
        if ($found === false) {
            $this->fail(sprintf('File "%s" not found at "%s"', $filename, $path));
        }
        $this->open_file($found);
        Test_Case::assert_file_exists($found);
    }
    /**
     * Checks if file does not exist in path
     */
    public function dont_see_file_found(string $filename, string $path = ''): void
    {
        if ($path === '') {
            Test_Case::assert_file_does_not_exist($filename);
            return;
        }
        $found = $this->find_file_in_path($filename, $path);
        if ($found === false) {
            //this line keeps a count of assertions correct
            Test_Case::assert_true(true);
            return;
        }
        Test_Case::assert_file_does_not_exist($found);
    }
    /**
     * Finds the first matching file
     *
     * @throws AssertionFailedError When path does not exist
     * @return string|false Path to the first matching file
     */
    private function find_file_in_path(string $filename, string $path): string|false
    {
        $path = $this->absolutize_path($path);
        if (!file_exists($path)) {
            $this->fail(sprintf('Directory does not exist: %s', $path));
        }
        $files = Finder::create()->files()->name($filename)->in($path);
        if ($files->count() === 0) {
            return false;
        }
        foreach ($files as $file) {
            return $file->get_real_path();
        }
    }
    /**
     * Erases directory contents
     *
     * ``` php
     * <?php
     * $I->cleanDir('logs');
     * ```
     */
    public function clean_dir(string $dirname): void
    {
        $path = $this->absolutize_path($dirname);
        Util::do_empty_dir($path);
    }
    /**
     * Saves contents to file
     */
    public function write_to_file(string $filename, string $contents): void
    {
        file_put_contents($filename, $contents);
    }
}