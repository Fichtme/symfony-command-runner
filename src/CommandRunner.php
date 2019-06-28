<?php
/**
 * User: Henny Krijnen
 * Date: 10/27/17 3:17 PM.
 */

namespace Fichtme\CommandRunner;

use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\LockHandler;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Class CommandRunner
 */
class CommandRunner
{
    /** @var integer */
    protected $limit = 5;

    /** @var ArrayCollection|Process[] */
    protected $openProcesses = [];

    /** @var bool */
    protected $active = false;

    /** @var ArrayCollection|Process[] */
    protected $activeProcesses;

    /** @var ArrayCollection|Process[] */
    protected $completedProcesses;

    /** @var SymfonyStyle */
    protected $io;

    /** @var ProgressBar */
    protected $progressBar;

    /** @var string */
    protected $binary;

    /** @var string */
    protected $subPath;

    /** @var ArrayCollection */
    protected $errors;

    /** @var bool */
    protected $continueOnError = true;

    /**
     * CommandRunner constructor.
     *
     * @param array       $processes
     * @param null|string $binary
     */
    public function __construct(array $processes, $binary = null)
    {
        $this->openProcesses = new ArrayCollection($processes);
        $this->activeProcesses = new ArrayCollection();
        $this->completedProcesses = new ArrayCollection();
        $this->errors = new ArrayCollection();

        $finder = new PhpExecutableFinder();
        $this->subPath = $_SERVER['PHP_SELF'] ?? $_SERVER['SCRIPT_NAME'] ?? $_SERVER['SCRIPT_FILENAME'];
        if ($binary === null) {
            $this->setPhpBinary($finder->find());
        } else {
            $this->setBinary($binary);
        }
    }

    /**
     * @param bool $continue
     *
     * @return $this
     */
    public function continueOnError($continue = true)
    {
        $this->continueOnError = $continue;

        return $this;
    }

    /**
     * @param string
     * @return $this
     */
    public function setPhpBinary($binary): CommandRunner
    {
        if (!$binary) {
            $this->io->error('Unable to find PHP binary.');
            exit(500);
        }

        $this->setBinary($binary);

        return $this;
    }

    /**
     * @param string $binary
     * @return $this
     */
    public function setBinary($binary): CommandRunner
    {
        $this->binary = $binary;

        return $this;
    }

    /**
     * The lock handler only works if you're using just one server.
     * If you have several hosts, you must not use this.
     *
     * @param string $lock
     *
     * @return LockHandler
     */
    public static function lock($command, $lock = '')
    {
        $lockHandler = new LockHandler($command . $lock);
        if (!$lockHandler->lock()) {
//            $this->io->error('This command is already running in another process.');
            exit(500);
        }

        return $lockHandler;
    }

    /**
     * @param $subPath
     *
     * @return $this
     */
    public function setSubPath($subPath): CommandRunner
    {
        $this->subPath = $subPath;

        return $this;
    }

    /**
     * @param SymfonyStyle $io
     *
     * @return $this
     */
    public function setIO(SymfonyStyle $io): CommandRunner
    {
        $this->io = $io;

        return $this;
    }

    /**
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * @param int $limit
     *
     * @return $this
     */
    public function setLimit($limit = 5): CommandRunner
    {
        $this->limit = $limit;

        return $this;
    }

    public function run()
    {
        $this->start();
        while ($this->hasOpenProcesses()) {
            if(!$this->process()) {
                break;
            }
            usleep(1000);
        }
        $this->finish();
    }

    private function start()
    {
        $this->active = true;
        if ($this->io) {
            $this->createProgressBar();
        }
    }

    private function createProgressBar()
    {
        $progressBar = $this->io->createProgressBar(count($this->openProcesses) * 2);
        $progressBar->setFormat("%current%/%max% [%bar%] %percent:3s%% | %elapsed% \n%message%\n");
        $progressBar->setBarCharacter('<fg=green>▓</>');
        $progressBar->setEmptyBarCharacter('<fg=red>░</>');
        $this->progressBar = $progressBar;
        $this->progressBar->start();
    }

    /**
     * @return bool
     */
    public function hasOpenProcesses(): bool
    {
        return !$this->openProcesses->isEmpty() || !$this->activeProcesses->isEmpty();
    }

    /**
     * @return bool
     */
    private function process()
    {
        if ($this->activeProcesses->count() < $this->limit) {
            //add new process
            $this->spawnNextProcess();
        }

        return $this->validateRunningProcesses();
    }

    private function spawnNextProcess()
    {
        if (!$this->openProcesses->isEmpty()) {
            $process = $this->openProcesses->first();
            $this->activeProcesses->add($process);
            $process = $this->modifyCommand($process);
            $process->start();
            $this->openProcesses->removeElement($process);
            if ($this->progressBar) {
                $this->progressBar->setMessage($process->getCommandLine());
                $this->progressBar->setProgress($this->progressBar->getProgress() + 1);
            }
        }
    }

    /**
     * @param Process $process
     *
     * @return Process
     */
    private function modifyCommand(Process $process): Process
    {
        $command = $process->getCommandLine();

        $process->setCommandLine(\sprintf(
            '%s %s %s',
            $this->binary,
            $this->subPath,
            $command
        ));

        return $process;
    }

    /**
     * @return bool
     */
    private function validateRunningProcesses()
    {
        $activeProcesses = $this->activeProcesses;
        foreach ($activeProcesses as $activeProcess) {
            if (!$activeProcess->isRunning()) {
                if($activeProcess->getErrorOutput()){
                    $this->errors->add([
                        'command' => $activeProcess->getCommandLine(),
                        'error' =>  $activeProcess->getErrorOutput()
                    ]);
                    if (!$this->continueOnError) {
                        return false;
                    }
                }

                $this->completedProcesses->add($activeProcess);
                $this->activeProcesses->removeElement($activeProcess);

                if ($this->progressBar) {
                    $this->progressBar->setProgress($this->progressBar->getProgress() + 1);
                }
            }
            usleep(1000);
        }

        return true;
    }

    private function finish()
    {
        if (!$this->errors->isEmpty()) {
            foreach($this->errors as $error) {
                $this->io->warning($error);
            }
        }

        $this->active = false;
    }
}
