<?php

use PHPUnit\Framework\TestCase;

final class CommandRunnerTest extends TestCase
{
    public function testCanBeCreatedFromEmptyArray(): void
    {
        $cmd = new Fichtme\CommandRunner\CommandRunner([

        ]);

        self::assertFalse($cmd->isActive());
    }
}
