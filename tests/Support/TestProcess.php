<?php

namespace Tests\Support;

use Illuminate\Testing\AssertableJsonString;
use PHPUnit\Framework\Assert as PHPUnit;
use PHPUnit\Framework\Constraint\LogicalNot;
use Symfony\Component\Process\Process;

class TestProcess
{
    /**
     * The process to delegate to.
     *
     * @var \Symfony\Component\Process\Process
     */
    public $baseProcess;

    /**
     * Create a new test process instance.
     *
     * @param  \Symfony\Component\Process\Process  $process
     * @return void
     */
    public function __construct(Process $process)
    {
        $this->baseProcess = $process;
    }

    /**
     * @return string
     */
    public function getOutput(): string
    {
        if ($this->baseProcess->isSuccessful()) {
            return $this->baseProcess->getOutput();
        }

        return $this->baseProcess->getErrorOutput();
    }

    /**
     * Validate and return the decoded output JSON.
     *
     * @param  string|null  $key
     * @return mixed
     */
    public function json(?string $key = null)
    {
        return $this->decodeOuputJson()->json($key);
    }

    /**
     * Validate and return the decoded output JSON.
     *
     * @return \Illuminate\Testing\AssertableJsonString
     *
     * @throws \Throwable
     */
    public function decodeOuputJson()
    {
        $testJson = new AssertableJsonString($this->getOutput());

        $decodedResponse = $testJson->json();

        if (is_null($decodedResponse) || $decodedResponse === false) {
            if ($this->exception) {
                throw $this->exception;
            } else {
                PHPUnit::fail('Invalid JSON was returned from the output.');
            }
        }

        return $testJson;
    }

    /**
     * Dump the output.
     *
     * @return self
     */
    public function dump()
    {
        fwrite(STDERR, print_r($this->getOutput(), true));

        return $this;
    }

    /**
     * Assert that the process has a successful exit code.
     *
     * @return self
     */
    public function assertSuccessful(): self
    {
        PHPUnit::assertTrue(
            $this->baseProcess->isSuccessful(),
            'Exit code ['.$this->baseProcess->getExitCode().'] is not a successful exit code. Error Output:'.
            PHP_EOL.$this->baseProcess->getErrorOutput()
        );

        return $this;
    }

    /**
     * Assert that the output contains the given string.
     *
     * @param  string  $needle
     * @return self
     */
    public function assertContains(string $needle): self
    {
        PHPUnit::assertThat($this->getOutput(), new ConsoleOutputContainsConstraint($needle));

        return $this;
    }

    /**
     * Assert that the output does not contain the given string.
     *
     * @param  string  $needle
     * @return self
     */
    public function assertNotContains(string $needle)
    {
        PHPUnit::assertThat($this->getOutput(), new LogicalNot(new ConsoleOutputContainsConstraint($needle)));

        return $this;
    }

    /**
     * Handle dynamic calls into macros or pass missing methods to the base process.
     *
     * @param  string  $method
     * @param  array  $args
     * @return mixed
     */
    public function __call($method, $args)
    {
        return $this->baseProcess->{$method}(...$args);
    }
}
