<?php

declare(strict_types=1);

namespace Fichtme\CommandRunner;

use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

use function count;
use function sprintf;
use function usleep;

class CommandRunner
{
    protected int $limit = 5;

    /** @var ArrayCollection<int, Process> */
    protected ArrayCollection $openProcesses;

    protected bool $active = false;

    /** @var ArrayCollection<int, Process> */
    protected ArrayCollection $activeProcesses;

    /** @var ArrayCollection<int, Process> */
    protected ArrayCollection $completedProcesses;

    protected ?SymfonyStyle $io = null;

    protected ?ProgressBar $progressBar = null;

    protected string $binary;

    protected string $subPath;

    /** @var ArrayCollection<int, array{command: string, error: string}> */
    protected ArrayCollection $errors;

    protected bool $continueOnError = true;

    /**
     * @param list<Process> $processes
     */
    public function __construct(array $processes, ?string $binary = null)
    {
        /** @var ArrayCollection<int, Process> $activeProcesses */
        $activeProcesses = new ArrayCollection();
        /** @var ArrayCollection<int, Process> $completedProcesses */
        $completedProcesses = new ArrayCollection();
        /** @var ArrayCollection<int, array{command: string, error: string}> $errors */
        $errors = new ArrayCollection();

        $this->openProcesses = new ArrayCollection($processes);
        $this->activeProcesses = $activeProcesses;
        $this->completedProcesses = $completedProcesses;
        $this->errors = $errors;

        $finder = new PhpExecutableFinder();
        $this->subPath = (string) ($_SERVER['PHP_SELF'] ?? $_SERVER['SCRIPT_NAME'] ?? $_SERVER['SCRIPT_FILENAME'] ?? '');

        if ($binary === null) {
            $this->setPhpBinary($finder->find());
        } else {
            $this->setBinary($binary);
        }
    }

    public function continueOnError(bool $continue = true): self
    {
        $this->continueOnError = $continue;

        return $this;
    }

    public function setPhpBinary(string|false|null $binary): CommandRunner
    {
        if (!$binary) {
            $this->io?->error('Unable to find PHP binary.');
            exit(500);
        }

        $this->setBinary($binary);

        return $this;
    }

    public function setBinary(string $binary): CommandRunner
    {
        $this->binary = $binary;

        return $this;
    }

    /**
     * The lock handler only works if you're using just one server.
     * If you have several hosts, you must not use this.
     */
    public static function lock(string $command, string $lockName = ''): LockInterface
    {
        $factory = new LockFactory(new FlockStore());
        $lock = $factory->createLock($command . $lockName, 0, true);

        if (!$lock->acquire()) {
            exit(1);
        }

        return $lock;
    }

    public function setSubPath(string $subPath): CommandRunner
    {
        $this->subPath = $subPath;

        return $this;
    }

    public function setIO(SymfonyStyle $io): CommandRunner
    {
        $this->io = $io;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setLimit(int $limit = 5): CommandRunner
    {
        $this->limit = $limit;

        return $this;
    }

    public function run(): void
    {
        $this->start();

        while ($this->hasOpenProcesses()) {
            if (!$this->process()) {
                break;
            }

            usleep(5000);
        }

        $this->finish();
    }

    private function start(): void
    {
        $this->active = true;

        if ($this->io !== null) {
            $this->createProgressBar();
        }
    }

    private function createProgressBar(): void
    {
        $io = $this->io;
        if ($io === null) {
            return;
        }

        $progressBar = $io->createProgressBar(count($this->openProcesses) * 2);
        $progressBar->setFormat("%current%/%max% [%bar%] %percent:3s%% | %elapsed% \n%message%\n");
        $progressBar->setBarCharacter('<fg=green>▓</>');
        $progressBar->setEmptyBarCharacter('<fg=red>░</>');
        $this->progressBar = $progressBar;
        $this->progressBar->start();
    }

    public function hasOpenProcesses(): bool
    {
        return !$this->openProcesses->isEmpty() || !$this->activeProcesses->isEmpty();
    }

    private function process(): bool
    {
        if ($this->activeProcesses->count() < $this->limit) {
            $this->spawnNextProcess();
        }

        return $this->validateRunningProcesses();
    }

    private function spawnNextProcess(): void
    {
        if ($this->openProcesses->isEmpty()) {
            return;
        }

        $originalProcess = $this->openProcesses->first();
        if ($originalProcess === false) {
            return;
        }

        $process = $this->modifyCommand($originalProcess);
        $this->activeProcesses->add($process);

        if ($this->progressBar !== null) {
            $this->progressBar->setMessage($process->getCommandLine());
            $this->progressBar->display();
        }

        $process->start();
        $this->openProcesses->removeElement($originalProcess);

        if ($this->progressBar !== null) {
            $this->progressBar->setProgress($this->progressBar->getProgress() + 1);
        }
    }

    private function modifyCommand(Process $process): Process
    {
        return Process::fromShellCommandline(sprintf(
            '%s %s %s',
            $this->binary,
            $this->subPath,
            $process->getCommandLine(),
        ));
    }

    private function validateRunningProcesses(): bool
    {
        foreach ($this->activeProcesses as $key => $activeProcess) {
            if ($activeProcess->isRunning()) {
                usleep(5000);
                continue;
            }

            $errorOutput = $activeProcess->getErrorOutput();
            if (!$activeProcess->isSuccessful() || $errorOutput !== '') {
                $this->errors->add([
                    'command' => $activeProcess->getCommandLine(),
                    'error' => $errorOutput !== ''
                        ? $errorOutput
                        : sprintf('Process exited with code %s.', (string) $activeProcess->getExitCode()),
                ]);

                if (!$this->continueOnError) {
                    return false;
                }
            }

            $this->completedProcesses->add($activeProcess);
            $this->activeProcesses->remove($key);

            if ($this->progressBar !== null) {
                $this->progressBar->setProgress($this->progressBar->getProgress() + 1);
            }

            usleep(5000);
        }

        return true;
    }

    private function finish(): void
    {
        if ($this->io !== null) {
            foreach ($this->errors as $error) {
                $this->io->warning($error);
            }
        }

        $this->active = false;
    }

    /**
     * @return ArrayCollection<int, array{command: string, error: string}>
     */
    public function getErrors(): ArrayCollection
    {
        return $this->errors;
    }
}
