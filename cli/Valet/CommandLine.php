<?php

namespace Valet;

use Symfony\Component\Process\Process;

class CommandLine
{
    /**
     * Simple global function to run commands.
     *
     * @param  string  $command
     * @return void
     */
    public function quietly($command)
    {
        $this->runCommand($command.' > /dev/null 2>&1');
    }

    /**
     * Simple global function to run commands.
     *
     * @param  string  $command
     * @return void
     */
    public function quietlyAsUser($command)
    {
        $this->quietly($command.' > /dev/null 2>&1');
    }

    /**
     * Pass the command to the command line and display the output.
     *
     * @param  string  $command
     * @return void
     */
    public function passthru($command)
    {
        passthru($command);
    }

    /**
     * Run the given command as the non-root user.
     *
     * @param  string  $command
     * @param  callable  $onError
     * @return ProcessOutput
     */
    public function run($command, ?callable $onError = null)
    {
        return $this->runCommand($command, $onError);
    }

    /**
     * Run the given command.
     *
     * @param  string  $command
     * @param  callable  $onError
     * @return ProcessOutput
     */
    public function runAsUser($command, ?callable $onError = null)
    {
        return $this->runCommand($command, $onError);
    }

    /**
     * Run the given command with PowerShell.
     *
     * @param  string  $command
     * @param  callable|null  $onError
     * @return ProcessOutput
     */
    public function powershell(string $command, ?callable $onError = null)
    {
        return $this->runCommand("powershell -command \"$command\"", $onError);
    }

    /**
     * @deprecated
     */
    public function runOrDie($command, ?callable $onError = null)
    {
        return $this->runOrExit($command, $onError);
    }

    /**
     * Run the given command and exit if fails.
     *
     * @param  string  $command
     * @param  callable  $onError  (int $code, string $output)
     * @return ProcessOutput
     */
    public function runOrExit($command, ?callable $onError = null)
    {
        return $this->run($command, function ($code, $output) use ($onError) {
            if ($onError) {
                $onError($code, $output);
            }

            exit(1);
        });
    }

    /**
     * Run the given command.
     *
     * @param  string  $command
     * @param  callable  $onError
     * @return ProcessOutput
     */
    public function runCommand($command, ?callable $onError = null)
    {
        $onError = $onError ?: function () {
        };

        // Symfony's 4.x Process component has deprecated passing a command string
        // to the constructor, but older versions (which Valet's Composer
        // constraints allow) don't have the fromShellCommandLine method.
        // For more information, see: https://github.com/laravel/valet/pull/761
        if (method_exists(Process::class, 'fromShellCommandline')) {
            $process = Process::fromShellCommandline($command);
        } else {
            $process = new Process($command);
        }

        $processOutput = '';
        $process->setTimeout(60)->run(function ($type, $line) use (&$processOutput) {
            $processOutput .= $line;
        });

        if ($process->getExitCode() !== 0) {
            $onError($process->getExitCode(), $processOutput);
        }

        return new ProcessOutput($process);
    }
}
