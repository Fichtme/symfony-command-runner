<?php

declare(strict_types=1);

namespace Fichtme\CommandRunner\Tests;

use Fichtme\CommandRunner\CommandRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Process\Process;

use function array_map;
use function escapeshellarg;
use function file;
use function file_get_contents;
use function json_decode;
use function sort;
use function sys_get_temp_dir;
use function tempnam;
use function trim;
use function uniqid;
use function unlink;

#[CoversClass(CommandRunner::class)]
final class CommandRunnerTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testEmptyRunnerCanBeCreatedAndIsNotActive(): void
    {
        $runner = $this->createRunner();

        self::assertFalse($runner->isActive());
        self::assertFalse($runner->hasOpenProcesses());
        self::assertTrue($runner->getErrors()->isEmpty());
    }

    public function testRunsOneSuccessfulSubprocess(): void
    {
        $output = $this->temporaryFile();
        $runner = $this->createRunner([
            $this->fixtureProcess('success', $output, 'first'),
        ]);

        $runner->run();

        self::assertSame("first\n", file_get_contents($output));
        self::assertTrue($runner->getErrors()->isEmpty());
    }

    public function testRunsMultipleSubprocesses(): void
    {
        $output = $this->temporaryFile();
        $runner = $this->createRunner([
            $this->fixtureProcess('success', $output, 'first'),
            $this->fixtureProcess('success', $output, 'second'),
            $this->fixtureProcess('success', $output, 'third'),
        ]);

        $runner->run();

        $lines = array_map(trim(...), file($output, FILE_IGNORE_NEW_LINES) ?: []);
        sort($lines);
        self::assertSame(['first', 'second', 'third'], $lines);
    }

    public function testSetLimitRestrictsParallelProcesses(): void
    {
        $state = $this->temporaryFile();
        $processes = [];
        for ($id = 1; $id <= 4; ++$id) {
            $processes[] = $this->fixtureProcess('barrier', $state, '2', (string) $id);
        }

        $runner = $this->createRunner($processes)->setLimit(2);
        $runner->run();

        /** @var array{active: int, maximum: int, arrived: int, generation: int} $result */
        $result = json_decode((string) file_get_contents($state), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(2, $result['maximum']);
        self::assertSame(0, $result['active']);
        self::assertSame(4, $result['arrived']);
    }

    public function testContinueOnErrorRunsRemainingProcessesAndCollectsFailure(): void
    {
        $output = $this->temporaryFile();
        $runner = $this->createRunner([
            $this->fixtureProcess('fail', 'expected failure'),
            $this->fixtureProcess('success', $output, 'continued'),
        ])->setLimit(1)->continueOnError(true);

        $runner->run();

        self::assertSame("continued\n", file_get_contents($output));
        self::assertCount(1, $runner->getErrors());
        $error = $runner->getErrors()->first();
        self::assertIsArray($error);
        self::assertStringContainsString('expected failure', $error['error']);
    }

    public function testFailedExitCodeWithoutStderrIsCollected(): void
    {
        $runner = $this->createRunner([
            $this->fixtureProcess('silent-fail'),
        ]);

        $runner->run();

        self::assertCount(1, $runner->getErrors());
        $error = $runner->getErrors()->first();
        self::assertIsArray($error);
        self::assertSame('Process exited with code 7.', $error['error']);
    }

    public function testOpenAndActiveStateDuringAndAfterExecution(): void
    {
        $outputFile = $this->temporaryFile();
        $runner = $this->createRunner([
            $this->fixtureProcess('success', $outputFile, 'state'),
        ]);
        $observedDuringRun = false;
        $output = new ObservingOutput(function () use ($runner, &$observedDuringRun): void {
            $observedDuringRun = $observedDuringRun
                || ($runner->isActive() && $runner->hasOpenProcesses());
        });
        $runner->setIO(new SymfonyStyle(new ArrayInput([]), $output));

        self::assertFalse($runner->isActive());
        self::assertTrue($runner->hasOpenProcesses());

        $runner->run();

        self::assertTrue($observedDuringRun);
        self::assertFalse($runner->isActive());
        self::assertFalse($runner->hasOpenProcesses());
    }

    public function testSetIoAndProgressDisplayDoNotFail(): void
    {
        $outputFile = $this->temporaryFile();
        $output = new ObservingOutput(static function (): void {});
        $runner = $this->createRunner([
            $this->fixtureProcess('success', $outputFile, 'progress'),
        ])->setIO(new SymfonyStyle(new ArrayInput([]), $output));

        $runner->run();

        self::assertStringContainsString('2/2', $output->contents());
        self::assertSame("progress\n", file_get_contents($outputFile));
    }

    public function testUniqueLockCanBeAcquiredAndReleased(): void
    {
        $resource = 'command-runner-test-' . uniqid('', true);
        $lock = CommandRunner::lock($resource);

        self::assertInstanceOf(LockInterface::class, $lock);
        self::assertTrue($lock->isAcquired());

        $lock->release();
        self::assertFalse($lock->isAcquired());

        $secondLock = CommandRunner::lock($resource);
        self::assertTrue($secondLock->isAcquired());
        $secondLock->release();
    }

    /**
     * @param list<Process> $processes
     */
    private function createRunner(array $processes = []): CommandRunner
    {
        return (new CommandRunner($processes, PHP_BINARY))
            ->setSubPath(__DIR__ . '/fixture/worker.php');
    }

    private function fixtureProcess(string ...$arguments): Process
    {
        return Process::fromShellCommandline(implode(' ', array_map(escapeshellarg(...), $arguments)));
    }

    private function temporaryFile(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'command-runner-');
        self::assertNotFalse($file);
        $this->temporaryFiles[] = $file;

        return $file;
    }
}

final class ObservingOutput extends Output
{
    private string $buffer = '';

    /** @var \Closure(): void */
    private readonly \Closure $observer;

    /** @param callable(): void $observer */
    public function __construct(callable $observer)
    {
        parent::__construct();
        $this->observer = \Closure::fromCallable($observer);
    }

    public function contents(): string
    {
        return $this->buffer;
    }

    protected function doWrite(string $message, bool $newline): void
    {
        $this->buffer .= $message . ($newline ? "\n" : '');
        ($this->observer)();
    }
}
