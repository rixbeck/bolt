<?php

namespace Bolt\Nut;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Info extends BaseCommand
{
    protected function configure()
    {
        $this
            ->setName('info')
            ->setDescription('Display phpinfo().');

    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        phpinfo();

    }
}
