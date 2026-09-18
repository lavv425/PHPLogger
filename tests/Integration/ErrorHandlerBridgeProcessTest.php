<?php

declare(strict_types=1);

namespace Logger\Tests\Integration;

use Logger\Enum\Outcome;
use PHPUnit\Framework\TestCase;

/**
 * Drives the error bridge through real process deaths.
 *
 * A fatal error and an out-of-memory cannot be simulated in-process: the engine
 * is in a state no test double reproduces. These run a child process and read
 * back what it managed to write on its way down.
 */
final class ErrorHandlerBridgeProcessTest extends TestCase
{
    private string $target;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bridge');
        self::assertIsString($path);
        $this->target = $path;
    }

    protected function tearDown(): void
    {
        if (is_file($this->target)) {
            unlink($this->target);
        }
    }

    public function test_a_call_to_an_undefined_function_arrives_as_an_exception(): void
    {
        // Since PHP 7 this raises an Error rather than a plain fatal, so it
        // reaches the exception hook and keeps its stack trace. Worth pinning:
        // it is the difference between a debuggable record and a bare message.
        $this->runScenario('fatal');

        $record = $this->lastRecord();
        self::assertSame('php_log', $record['log_type']);
        self::assertSame('uncaught_exception', $record['event']);
        self::assertSame(Outcome::FAILURE, $record['outcome']);
        self::assertSame('Error', $record['error']['class']);
        self::assertStringContainsString('questa_funzione_non_esiste', $record['error']['message']);
        self::assertIsArray($record['error']['stack_trace']);
    }

    public function test_a_fatal_user_error_is_reported_once_not_twice(): void
    {
        // E_USER_ERROR reaches the error handler and then ends the request, so
        // it is visible to both hooks.
        $this->runScenario('user_error');

        self::assertCount(1, $this->records());
        self::assertSame('error_handler', $this->lastRecord()['event']);
        self::assertSame(Outcome::FAILURE, $this->lastRecord()['outcome']);
    }

    public function test_an_out_of_memory_fatal_is_still_logged(): void
    {
        // The reserved buffer exists for exactly this: with the heap exhausted
        // there is nothing left to allocate, not even the record.
        $this->runScenario('oom', ['-d', 'memory_limit=32M']);

        $record = $this->lastRecord();
        self::assertSame('shutdown_error', $record['event']);
        self::assertStringContainsString('emory', $record['error']['message']);
    }

    public function test_an_uncaught_exception_is_logged_and_still_terminates_the_process(): void
    {
        $result = $this->runScenario('exception');

        // Exactly one record: the rethrow leaves an E_ERROR behind, and the
        // shutdown hook must not file the same failure a second time.
        self::assertCount(1, $this->records());

        $record = $this->lastRecord();
        self::assertSame('uncaught_exception', $record['event']);
        self::assertSame(Outcome::FAILURE, $record['outcome']);
        self::assertSame('esplosione non catturata', $record['error']['message']);
        self::assertIsArray($record['error']['stack_trace']);

        // Installing the bridge must not turn a fatal condition into a silent
        // one: the process still dies the way PHP intended.
        self::assertNotSame(0, $result['code'], 'the process should have failed');
        self::assertStringContainsString('esplosione non catturata', $result['stderr'] . $result['stdout']);
    }

    public function test_a_recoverable_notice_is_logged_without_stopping_the_script(): void
    {
        $result = $this->runScenario('notice', ['-d', 'error_reporting=E_ALL']);

        $record = $this->lastRecord();
        self::assertSame('error_handler', $record['event']);
        self::assertSame(Outcome::UNKNOWN, $record['outcome'], 'a notice says nothing about the surrounding work');
        self::assertStringContainsString('sopravvissuto', $result['stdout'], 'execution must continue');
    }

    /**
     * @param string[] $iniArgs
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runScenario(string $scenario, array $iniArgs = []): array
    {
        $command = array_merge([PHP_BINARY], $iniArgs, [__DIR__ . '/../Fixture/error_bridge_scenario.php', $scenario, $this->target]);
        $escaped = implode(' ', array_map('escapeshellarg', $command));

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($escaped, $descriptors, $pipes);
        self::assertIsResource($process, 'could not start the child process');

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** @return array<string, mixed> */
    private function lastRecord(): array
    {
        $records = $this->records();
        self::assertNotSame([], $records, 'the child process wrote nothing');

        return $records[count($records) - 1];
    }

    /** @return array<int, array<string, mixed>> */
    private function records(): array
    {
        $contents = rtrim((string) file_get_contents($this->target), "\n");
        if ($contents === '') {
            return [];
        }

        $records = [];
        foreach (explode("\n", $contents) as $line) {
            $decoded = json_decode($line, true);
            self::assertIsArray($decoded, 'the child process wrote a line that is not a record');
            $records[] = $decoded;
        }

        return $records;
    }
}
