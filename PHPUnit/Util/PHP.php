<?php
/**
 * PHPUnit
 *
 * Copyright (c) 2001-2013, Sebastian Bergmann <sebastian@phpunit.de>.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 *
 *   * Redistributions of source code must retain the above copyright
 *     notice, this list of conditions and the following disclaimer.
 *
 *   * Redistributions in binary form must reproduce the above copyright
 *     notice, this list of conditions and the following disclaimer in
 *     the documentation and/or other materials provided with the
 *     distribution.
 *
 *   * Neither the name of Sebastian Bergmann nor the names of his
 *     contributors may be used to endorse or promote products derived
 *     from this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS
 * FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
 * INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
 * BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT
 * LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN
 * ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * @package    PHPUnit
 * @subpackage Util
 * @author     Sebastian Bergmann <sebastian@phpunit.de>
 * @copyright  2001-2013 Sebastian Bergmann <sebastian@phpunit.de>
 * @license    http://www.opensource.org/licenses/BSD-3-Clause  The BSD 3-Clause License
 * @link       http://www.phpunit.de/
 * @since      File available since Release 3.4.0
 */

/**
 * Utility methods for PHP sub-processes.
 *
 * @package    PHPUnit
 * @subpackage Util
 * @author     Sebastian Bergmann <sebastian@phpunit.de>
 * @copyright  2001-2013 Sebastian Bergmann <sebastian@phpunit.de>
 * @license    http://www.opensource.org/licenses/BSD-3-Clause  The BSD 3-Clause License
 * @link       http://www.phpunit.de/
 * @since      Class available since Release 3.4.0
 */
abstract class PHPUnit_Util_PHP
{
    /**
     * @return PHPUnit_Util_PHP
     * @since  Method available since Release 3.5.12
     */
    public static function factory()
    {
        if (DIRECTORY_SEPARATOR == '\\') {
            return new PHPUnit_Util_PHP_Windows;
        }

        return new PHPUnit_Util_PHP_Default;
    }

    /**
     * Runs a single job (PHP code) using a separate PHP process.
     *
     * @param  string                       $job
     * @param  PHPUnit_Framework_TestCase   $test
     * @param  PHPUnit_Framework_TestResult $result
     * @return array|null
     * @throws PHPUnit_Framework_Exception
     */
    public function runJob($job, PHPUnit_Framework_Test $test = NULL, PHPUnit_Framework_TestResult $result = NULL)
    {
        // HHVM support
        if (($phpBinary = getenv("PHP_BINARY")) === false) {
            $phpBinary = PHP_BINARY;
        }

        $process = proc_open(
          escapeshellarg($phpBinary),
          array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w')
          ),
          $pipes
        );

        if (!is_resource($process)) {
            throw new PHPUnit_Framework_Exception(
              'Unable to create process for process isolation.'
            );
        }

        if ($result !== NULL) {
            $result->startTest($test);
        }

        $this->process($pipes[0], $job);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        proc_close($process);
        $this->cleanup();

        if ($result !== NULL) {
            $this->processChildResult($test, $result, $stdout, $stderr);
        } else {
            return array('stdout' => $stdout, 'stderr' => $stderr);
        }
    }

    /**
     * @param resource $pipe
     * @param string   $job
     * @since Method available since Release 3.5.12
     */
    abstract protected function process($pipe, $job);

    /**
     * @since Method available since Release 3.5.12
     */
    protected function cleanup()
    {
    }

    /**
     * Processes the TestResult object from an isolated process.
     *
     * @param PHPUnit_Framework_TestCase   $test
     * @param PHPUnit_Framework_TestResult $result
     * @param string                       $stdout
     * @param string                       $stderr
     * @since Method available since Release 3.5.0
     */
    protected function processChildResult(PHPUnit_Framework_Test $test, PHPUnit_Framework_TestResult $result, $stdout, $stderr)
    {
        $time = 0;

        if (!empty($stderr)) {
            $result->addError(
              $test,
              new PHPUnit_Framework_Exception(trim($stderr)), $time
            );
        } else {
            set_error_handler(function($errno, $errstr, $errfile, $errline) {
                throw new ErrorException($errstr, $errno, $errno, $errfile, $errline);
            });
            try {
                if (strpos($stdout, "#!/usr/bin/env php\n") === 0) {
                    $stdout = substr($stdout, 19);
                }

                $childResult = unserialize(str_replace("#!/usr/bin/env php\n", '', $stdout));
                restore_error_handler();
            } catch (ErrorException $e) {
                restore_error_handler();
                $childResult = FALSE;

                $result->addError(
                  $test, new PHPUnit_Framework_Exception(trim($stdout), 0, $e), $time
                );
            }

            if ($childResult !== FALSE) {
                if (!empty($childResult['output'])) {
                    print $childResult['output'];
                }

                $test->setResult($childResult['testResult']);
                $test->addToAssertionCount($childResult['numAssertions']);

                $childResult = $childResult['result'];

                if ($result->getCollectCodeCoverageInformation()) {
                    $result->getCodeCoverage()->merge(
                      $childResult->getCodeCoverage()
                    );
                }

                $time           = $childResult->time();
                $notImplemented = $childResult->notImplemented();
                $skipped        = $childResult->skipped();
                $errors         = $childResult->errors();
                $failures       = $childResult->failures();

                if (!empty($notImplemented)) {
                    $result->addError(
                      $test, $this->getException($notImplemented[0]), $time
                    );
                }

                else if (!empty($skipped)) {
                    $result->addError(
                      $test, $this->getException($skipped[0]), $time
                    );
                }

                else if (!empty($errors)) {
                    $result->addError(
                      $test, $this->getException($errors[0]), $time
                    );
                }

                else if (!empty($failures)) {
                    $result->addFailure(
                      $test, $this->getException($failures[0]), $time
                    );
                }
            }
        }

        $result->endTest($test, $time);
    }

    /**
     * Gets the thrown exception from a PHPUnit_Framework_TestFailure.
     *
     * @param PHPUnit_Framework_TestFailure $error
     * @since Method available since Release 3.6.0
     * @see   https://github.com/sebastianbergmann/phpunit/issues/74
     */
    protected function getException(PHPUnit_Framework_TestFailure $error)
    {
        $exception = $error->thrownException();

        if ($exception instanceof __PHP_Incomplete_Class) {
            $exceptionArray = array();
            foreach ((array)$exception as $key => $value) {
                $key = substr($key, strrpos($key, "\0") + 1);
                $exceptionArray[$key] = $value;
            }

            $exception = new PHPUnit_Framework_SyntheticError(
              sprintf(
                '%s: %s',
                $exceptionArray['_PHP_Incomplete_Class_Name'],
                $exceptionArray['message']
              ),
              $exceptionArray['code'],
              $exceptionArray['file'],
              $exceptionArray['line'],
              $exceptionArray['trace']
            );
        }

        return $exception;
    }
}
