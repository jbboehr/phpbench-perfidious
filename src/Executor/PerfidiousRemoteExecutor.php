<?php
/**
 * Copyright (c) anno Domini nostri Jesu Christi MMXXIV John Boehr & contributors
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace jbboehr\PhpBenchPerfidious\Executor;

use InvalidArgumentException;
use PhpBench\Executor\Benchmark\TemplateExecutor;
use PhpBench\Executor\BenchmarkExecutorInterface;
use PhpBench\Executor\Exception\ExecutionError;
use PhpBench\Executor\ExecutionContext;
use PhpBench\Executor\ExecutionResults;
use PhpBench\Model\Result\MemoryResult;
use PhpBench\Model\Result\TimeResult;
use PhpBench\Registry\Config;
use PhpBench\Remote\Exception\ScriptErrorException;
use PhpBench\Remote\IniStringBuilder;
use PhpBench\Remote\Launcher;
use PhpBench\Remote\Payload;
use PhpBench\Remote\ProcessFactory;
use PhpBench\Remote\ProcessFactoryInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use const _PHPStan_ab84e5579\__;

class PerfidiousRemoteExecutor implements BenchmarkExecutorInterface
{
    private const DEFAULT_SCRIPT_PATH = __DIR__ . '/../../bin/remote.php';

    public function __construct(
        private readonly string $bootstrap,
        private readonly ExecutableFinder $finder = new ExecutableFinder(),
        private readonly ProcessFactoryInterface $processFactory = new ProcessFactory(),
        private readonly IniStringBuilder $iniStringBuilder = new IniStringBuilder(),
        private readonly ?string $phpBinary = null,
        private readonly ?float $timeout = null,
        private readonly string $scriptPath = self::DEFAULT_SCRIPT_PATH,
    ) {
    }

    public function execute(ExecutionContext $context, Config $config): ExecutionResults
    {
        $args = $this->createArgs($context, $config);
        $input = serialize($context->getParameterSet()->toSerializedParameters());

        try {
            $result = $this->launch($args, $input);
        } catch (ScriptErrorException $error) {
            throw new ExecutionError(sprintf(
                "Benchmarking script exited with code %s\n\n%s",
                $error->getExitCode() ?? 'unknown',
                $error->getMessage()
            ));
        }

        if (isset($result['buffer']) && $result['buffer']) {
            throw new \RuntimeException(sprintf(
                'Benchmark made some noise: %s',
                $result['buffer']
            ));
        }

        return ExecutionResults::fromResults(
            TimeResult::fromArray($result['time']),
            MemoryResult::fromArray($result['mem'])
        );
    }

    public function configure(OptionsResolver $options): void
    {

    }

    private function launch(array $args, mixed $input)
    {
        $commandLine = $this->buildCommandLine([], $this->scriptPath, $args);

        $process = $this->processFactory->create($commandLine, $this->timeout);
        $process->setInput($input);
        $process->run();

        if (false === $process->isSuccessful()) {
            throw new ScriptErrorException(sprintf(
                '%s%s',
                $process->getErrorOutput(),
                $process->getOutput()
            ), $process->getExitCode());
        }

        return $this->decodeResults($process);
    }

    /**
     * @return array<string,mixed>
     */
    private function createArgs(ExecutionContext $context, Config $config): array
    {
        return [
            'class' => $context->getClassName(),
            'file' => $context->getClassPath(),
            'subject' => $context->getMethodName(),
            'revolutions' => $context->getRevolutions(),
            'beforeMethods' => join(',', $context->getBeforeMethods()),
            'afterMethods' => join(',', $context->getAfterMethods()),
            // 'parameters' => var_export($this->resolveParameterSet($context, $config), true),
            'warmup' => $context->getWarmup() ?: 0,
            'bootstrap' => $this->bootstrap,
        ];
    }

    /**
     * @see Launcher::resolvePhpBinary()
     * @license idk, probably MIT or something
     */
    private function resolvePhpBinary(): ?string
    {
        // if no php binary, use the PhpExecutableFinder (generally will resolve to PHP_BINARY)
        if (!$this->phpBinary) {
            $finder = new PhpExecutableFinder();

            return $finder->find() ?: null;
        }

        // if the php binary is absolute, fine.
        if (str_starts_with($this->phpBinary, '/')) {
            return $this->phpBinary;
        }

        // otherwise try and find it in PATH etc.
        /** @var string|null $phpBinary */
        $phpBinary = $this->finder->find($this->phpBinary);

        if (null === $phpBinary) {
            throw new InvalidArgumentException(sprintf(
                'Could not find PHP binary "%s"',
                $this->phpBinary
            ));
        }

        return $phpBinary;
    }

    /**
     * @param array<string, scalar|scalar[]> $phpIni
     * @see Payload::buildCommandLine()
     */
    private function buildCommandLine(
        array $phpIni,
        string $scriptPath,
        array $scriptArgs,
    ): string {
        $phpBinary = $this->resolvePhpBinary();

        $arguments = [];

        $arguments[] = escapeshellarg($phpBinary);

        $arguments[] = $this->getIniString($phpIni);
        $arguments[] = escapeshellarg($scriptPath);

        foreach ($scriptArgs as $scriptArg => $value) {
            $arguments[] = '--' . $scriptArg . '=' . escapeshellarg($value);
        }

        return implode(' ', $arguments);
    }

    /**
     * @param array<string, scalar|scalar[]> $ini
     * @see Payload::getIniString()
     */
    private function getIniString(array $ini): string
    {
        return $this->iniStringBuilder->build($ini);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResults(Process $process): array
    {
        $output = $process->getOutput();

        $result = @unserialize($output);

        if (is_array($result)) {
            return $result;
        }

        throw new \RuntimeException(sprintf(
            'Script "%s" did not return an array, got: %s',
            $this->scriptPath,
            $output
        ));
    }
}
