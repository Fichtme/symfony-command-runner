# symfony-command-runner
Run multiple commands in another process and wait for completion.

## Usage

```php
(new CommandRunner([
            new Process("my:command -q"),
            new Process("my:command2 -q"),
            new Process("my:command3 -q").
            new Process("my:command4 -q"),
            new Process("my:command5 -q"),
            new Process("my:command6 -q --env=$env"),
        ]))
            ->continueOnError(true)
            ->setIO($this->io)
            ->setLimit(3)
            ->run();
            
```

## Possible use case:
```php

/**
 * Class UpdateCommand
 *
 * @package App\Command\Update
 */
class UpdateCommand extends AbstractCommand
{
    /**
     * Configures the current command.
     */
    protected function configure()
    {
        $this->setName('app:update')
            ->setDescription('execute updates');
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io->writeln('Running update scripts');

        sleep(5); # Sleep so user can abort update
        
        (new CommandRunner([
            new Process("my:command -q"),
            new Process("my:command2 -q"),
            new Process("my:command3 -q").
            new Process("my:command4 -q"),
            new Process("my:command5 -q"),
            new Process("my:command6 -q"),
        ]))
            ->continueOnError(true)
            ->setIO($this->io)
            ->setLimit(3)
            ->run();
            
        return 0;
    }
}
```
