<?php
/**
 * Copyright (c) 2016. Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 *
 *  THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 *  "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 *  LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 *  A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 *  OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 *  SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 *  LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 *  DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 *  THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 *  (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 *  OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 *  This software consists of voluntary contributions made by many individuals
 *  and is licensed under the MIT license.
 */

declare (strict_types=1);

namespace Humus\Amqp\Console\Command;

use Humus\Amqp\Consumer;
use Humus\Amqp\Container\JsonRpcServerFactory;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class StartJsonRpcServerCommand
 * @package Humus\Amqp\Console\Command
 */
class StartJsonRpcServerCommand extends AbstractCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('json_rpc_server')
            ->setAliases(['json_rpc_server'])
            ->setDescription('Start a JSON-RPC server')
            ->setDefinition([
                new InputOption(
                    'name',
                    null,
                    InputOption::VALUE_REQUIRED,
                    'name of the JSON-RPC server to start'
                ),
                new InputOption(
                    'amount',
                    null,
                    InputOption::VALUE_OPTIONAL,
                    'amount of messages to consume',
                    0
                )
            ])
            ->setHelp('Start a JSON-RPC server');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $config = $this->getHumusAmqpConfig();

        $serverName = $input->getOption('name');

        if (! isset($config['json_rpc_server'][$serverName])) {
            $output->writeln('No JSON-RPC server with name ' . $serverName . ' found');
            return 1;
        }

        $jsonRpcServer = JsonRpcServerFactory::$serverName($this->getContainer());
        /* @var Consumer $jsonRpcServer */

        $jsonRpcServer->consume($input->getOption('amount'));
    }
}
