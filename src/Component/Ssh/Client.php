<?php
/* (c) Anton Medvedev <anton@medv.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Deployer\Component\Ssh;

use Deployer\Deployer;
use Deployer\Exception\RunException;
use Deployer\Host\Host;
use Deployer\Component\ProcessRunner\Printer;
use Deployer\Logger\Logger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class Client
{
    private $output;
    private $pop;
    private $logger;

    public function __construct(OutputInterface $output, Printer $pop, Logger $logger)
    {
        $this->output = $output;
        $this->pop = $pop;
        $this->logger = $logger;
    }

    public function run(Host $host, string $command, array $config = [])
    {
        $hostname = $host->hostname();
        $defaults = [
            'timeout' => $host->get('default_timeout', 300),
        ];

        $config = array_merge($defaults, $config);
        $sshArguments = $host->getSshArguments();
        if ($host->sshMultiplexing()) {
            $sshArguments = $this->initMultiplexing($host);
        }

        $become = '';
        if ($host->has('become')) {
            $become = sprintf('sudo -H -u %s', $host->get('become'));
        }

        $shellCommand = $host->shell();

        if (strtolower(substr(PHP_OS, 0, 3)) === 'win') {
            $ssh = "ssh $sshArguments $hostname $become \"$shellCommand; printf '[exit_code:%s]' $?;\"";
        } else {
            $ssh = "ssh $sshArguments $hostname $become '$shellCommand; printf \"[exit_code:%s]\" $?;'";
        }

        // -vvv for ssh command
        if ($this->output->isDebug()) {
            $this->pop->writeln(Process::OUT, $host, "$ssh");
        }

        $this->pop->command($host, $command);
        $this->logger->log("[{$host->alias()}] run $command");

        $terminalOutput = $this->pop->callback($host);
        $callback = function ($type, $buffer) use ($host, $terminalOutput) {
            $this->logger->printBuffer($host, $type, $buffer);
            $terminalOutput($type, $buffer);
        };

        $process = $this->createProcess($ssh);
        $process
            ->setInput(str_replace('%secret%', $config['secret'] ?? '', $command))
            ->setTimeout($config['timeout']);


        $process->run($callback);

        $output = $this->pop->filterOutput($process->getOutput());
        $exitCode = $this->parseExitStatus($process);

        if ($exitCode !== 0) {
            throw new RunException(
                $hostname,
                $command,
                $exitCode,
                $output,
                $process->getErrorOutput()
            );
        }

        return $output;
    }

    private function parseExitStatus(Process $process)
    {
        $output = $process->getOutput();
        preg_match('/\[exit_code:(.*?)\]/', $output, $match);

        if (!isset($match[1])) {
            return -1;
        }

        $exitCode = (int)$match[1];
        return $exitCode;
    }

    public function connect(Host $host)
    {
        if ($host->sshMultiplexing()) {
            $this->initMultiplexing($host);
        }
    }

    private function initMultiplexing(Host $host)
    {
        $sshArguments = $host->getSshArguments()->withMultiplexing($host);

        if (!$this->isMultiplexingInitialized($host, $sshArguments)) {
            $hostname = $host->hostname();

            if ($this->output->isDebug()) {
                $this->pop->writeln(Process::OUT, $host, '<info>ssh multiplexing initialization</info>');
                $this->pop->writeln(Process::OUT, $host, "ssh -N $sshArguments $hostname");
            }

            $output = $this->exec("ssh -N $sshArguments $hostname");

            if ($this->output->isDebug()) {
                $this->pop->printBuffer(Process::OUT, $host, $output);
            }
        }

        return $sshArguments;
    }

    private function isMultiplexingInitialized(Host $host, Arguments $sshArguments)
    {
        $process = $this->createProcess("ssh -O check $sshArguments {$host->alias()} 2>&1");
        $process->run();
        return (bool)preg_match('/Master running/', $process->getOutput());
    }

    private function exec($command, &$exitCode = null)
    {
        $descriptors = [
            ['pipe', 'r'],
            ['pipe', 'w'],
            ['pipe', 'w'],
        ];

        // Don't read from stderr, there is a bug in OpenSSH_7.2p2 (stderr doesn't closed with ControlMaster)

        $process = proc_open($command, $descriptors, $pipes);
        if (is_resource($process)) {
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
        } else {
            $output = 'proc_open failure';
            $exitCode = 1;
        }
        return $output;
    }

    private function createProcess($command)
    {
        if (method_exists('Symfony\Component\Process\Process', 'fromShellCommandline')) {
            return Process::fromShellCommandline($command);
        } else {
            return new Process($command);
        }
    }
}
