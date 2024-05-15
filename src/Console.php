<?php

declare(strict_types=1);

namespace Atk4\Ui;

use Atk4\Core\DebugTrait;
use Atk4\Core\TraitUtil;
use Atk4\Ui\Js\JsBlock;
use Atk4\Ui\Js\JsExpressionable;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Console is a black square component resembling terminal window. It can be programmed
 * to run a job and output results to the user.
 */
class Console extends View implements LoggerInterface
{
    public $ui = 'inverted black segment';

    /**
     * Specify which event will trigger this console. Set to false
     * to disable automatic triggering if you need to trigger it
     * manually.
     *
     * @var bool
     */
    public $event = true;

    /**
     * Will be set to $true while executing callback. Some methods
     * will use this to automatically schedule their own callback
     * and allowing you a cleaner syntax, such as.
     *
     * $console->setModel($user, 'generateReport');
     *
     * @var bool
     */
    protected $sseInProgress = false;

    /** @var JsSse|null Stores object JsSse which is used for communication. */
    public $sse;

    /**
     * Bypass is used internally to capture and wrap direct output, but prevent JsSse from
     * triggering output recursively.
     *
     * @var bool
     */
    protected $_outputBypass = false;

    /** @var int|null */
    public $lastExitCode;

    /**
     * Set a callback method which will be executed with the output sent back to the terminal.
     *
     * Argument passed to your callback will be $this Console. You may perform calls
     * to methods such as
     *
     *   $console->output()
     *   $console->outputHtml()
     *
     * If you are using setModel, and if your model implements \Atk4\Core\DebugTrait,
     * then you you will see debug information generated by $this->debug() or $this->log().
     *
     * This intercepts default application logging for the duration of the process.
     *
     * If you are using runCommand, then server command will be executed with it's output
     * (stdout and stderr) redirected to the console.
     *
     * While inside a callback you may execute runCommand or setModel multiple times.
     *
     * @param \Closure($this): void $fx    callback which will be executed while displaying output inside console
     * @param bool|string           $event "true" would mean to execute on page load, string would indicate
     *                                     JS event. See first argument for View::js()
     */
    #[\Override]
    public function set($fx = null, $event = null)
    {
        if (!$fx instanceof \Closure) {
            throw new \TypeError('$fx must be of type Closure');
        }

        if ($event !== null) {
            $this->event = $event;
        }

        if (!$this->sse) {
            $this->sse = JsSse::addTo($this);
        }

        $this->sse->set(function () use ($fx) {
            $this->sseInProgress = true;
            $oldLogger = $this->getApp()->logger;
            $this->getApp()->logger = $this;
            try {
                ob_start(function (string $content) {
                    if ($this->_outputBypass || $content === '' /* needed as self::output() adds NL */) {
                        return $content;
                    }

                    $output = '';
                    $this->sse->echoFunction = static function (string $str) use (&$output) {
                        $output .= $str;
                    };
                    $this->output($content);
                    $this->sse->echoFunction = false;

                    return $output;
                }, 1);

                try {
                    $fx($this);
                } catch (\Throwable $e) {
                    $this->outputHtmlWithoutPre('<div class="ui segment">{0}</div>', [$this->getApp()->renderExceptionHtml($e)]);
                }
            } finally {
                $this->sseInProgress = false;
                $this->getApp()->logger = $oldLogger;
            }
        });

        if ($this->event) {
            $this->js($this->event, $this->jsExecute());
        }

        return $this;
    }

    public function jsExecute(): JsBlock
    {
        return $this->sse->jsExecute();
    }

    private function escapeOutputHtml(string $message): string
    {
        $res = $this->getApp()->encodeHtml($message);

        // TODO assert with Behat test
        // fix new lines for display and copy paste, testcase:
        // $genFx = function (array $values, int $maxLength, array $prev = null) use (&$genFx) {
        //     $res = [];
        //     foreach ($prev ?? [''] as $p) {
        //         foreach ($values as $v) {
        //             $res[] = $p . $v;
        //         }
        //     }
        //
        //     if (--$maxLength > 0) {
        //         $res = array_merge($res, $genFx($values, $maxLength, $res));
        //     }
        //
        //     if ($prev === null) {
        //         array_unshift($res, '');
        //     }
        //
        //     return $res;
        // };
        // $testCases = $genFx([' ', "\t", "\n", 'x'], 5);
        //
        // foreach ($testCases as $testCase) {
        //     $this->output('--------' . str_replace([' ', "\t", "\n", 'x'], [' sp', ' tab', ' nl', ' x'], $testCase));
        //     $this->output($testCase);
        // }
        // $this->output('--------');
        $res = preg_replace('~\r\n?|\n~s', "\n", $res);
        $res = preg_replace('~^$|(?<!^)(\n+)$~s', "$1\n", $res);

        return $res;
    }

    /**
     * Output a single line to the console.
     *
     * @return $this
     */
    public function output(string $message, array $context = [])
    {
        $this->outputHtml($this->escapeOutputHtml($message), $context);

        return $this;
    }

    /**
     * Output unescaped HTML to the console.
     *
     * @return $this
     */
    public function outputHtml(string $messageHtml, array $context = [])
    {
        $this->outputHtmlWithoutPre($this->getApp()->getTag('div', ['style' => 'font-family: monospace; white-space: pre;'], [$messageHtml]), $context);

        return $this;
    }

    /**
     * Output unescaped HTML to the console without wrapping in <pre>.
     *
     * @return $this
     */
    protected function outputHtmlWithoutPre(string $messageHtml, array $context = [])
    {
        $messageHtml = preg_replace_callback('~{([\w]+)}~', static function ($matches) use ($context) {
            if (isset($context[$matches[1]])) {
                return $context[$matches[1]];
            }

            return $matches[0];
        }, $messageHtml);

        $this->_outputBypass = true;
        try {
            $this->sse->send($this->js()->append($messageHtml));
        } finally {
            $this->_outputBypass = false;
        }

        return $this;
    }

    #[\Override]
    protected function renderView(): void
    {
        $this->setStyle('overflow-x', 'auto');

        parent::renderView();
    }

    /**
     * Executes a JavaScript action.
     *
     * @return $this
     */
    public function send(JsExpressionable $js)
    {
        $this->_outputBypass = true;
        try {
            $this->sse->send($js);
        } finally {
            $this->_outputBypass = false;
        }

        return $this;
    }

    /**
     * Executes command passing along escaped arguments.
     *
     * Will also stream stdout / stderr as the command executes.
     * once command terminates method will return the exit code.
     *
     * This method can be executed from inside callback or
     * without it.
     *
     * Example: $console->exec('ping', ['-c', '5', '8.8.8.8']);
     *
     * All arguments are escaped.
     */
    public function exec(string $command, array $args = []): ?bool
    {
        if (!$this->sseInProgress) {
            $this->set(function () use ($command, $args) {
                $this->output(
                    '--[ Executing ' . $command
                    . ($args ? ' with ' . count($args) . ' arguments' : '')
                    . ' ]--------------'
                );

                $this->exec($command, $args);

                $this->output('--[ Exit code: ' . $this->lastExitCode . ' ]------------');
            });

            return null;
        }

        [$proc, $pipes] = $this->execRaw($command, $args);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        // $pipes contain streams that are still open and not EOF
        while ($pipes) {
            $read = $pipes;
            $j1 = null;
            $j2 = null;
            if (stream_select($read, $j1, $j2, 2) === false) {
                throw new Exception('Unexpected stream_select() result');
            }

            $status = proc_get_status($proc);
            if (!$status['running']) {
                proc_close($proc);

                break;
            }

            foreach ($read as $f) {
                $data = rtrim((string) fgets($f));
                if ($data === '') {
                    // TODO fix coverage stability, add test with explicit empty string
                    // @codeCoverageIgnoreStart
                    continue;
                    // @codeCoverageIgnoreEnd
                }

                if ($f === $pipes[2]) { // stderr
                    $this->warning($data);
                } else { // stdout
                    $this->output($data);
                }
            }
        }

        $this->lastExitCode = $status['exitcode'];

        return $this->lastExitCode ? false : true;
    }

    /**
     * @return array{resource, non-empty-array}
     */
    protected function execRaw(string $command, array $args = [])
    {
        $args = array_map(static fn ($v) => escapeshellarg($v), $args);

        $spec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']]; // we want stdout and stderr
        $pipes = null;
        $proc = proc_open($command . ' ' . implode(' ', $args), $spec, $pipes);
        if (!is_resource($proc)) {
            throw (new Exception('Command failed to execute'))
                ->addMoreInfo('command', $command)
                ->addMoreInfo('args', $args);
        }

        return [$proc, $pipes];
    }

    /**
     * Execute method of a certain object. If object uses Atk4\Core\DebugTrait,
     * then debugging will also be used.
     *
     * During the invocation, Console will substitute $app->logger with itself,
     * capturing all debug/info/log messages generated by your code and displaying
     * it inside console.
     *
     * // runs $userController->generateReport('pdf')
     * Console::addTo($app)->runMethod($userController, 'generateReports', ['pdf']);
     *
     * // runs PainFactory::lastStaticMethod()
     * Console::addTo($app)->runMethod('PainFactory', 'lastStaticMethod');
     *
     * To produce output:
     *  - use $this->debug() or $this->info() (see documentation on DebugTrait)
     *
     * NOTE: debug() method will only output if you set debug=true. That is done
     * for the $userController automatically, but for any nested objects you would have
     * to pass on the property.
     *
     * @param object|class-string $object
     *
     * @return $this
     */
    public function runMethod($object, string $method, array $args = [])
    {
        if (!$this->sseInProgress) {
            $this->set(function () use ($object, $method, $args) {
                $this->runMethod($object, $method, $args);
            });

            return $this;
        }

        if (is_object($object)) {
            // temporarily override app logging
            if (TraitUtil::hasAppScopeTrait($object) && $object->issetApp()) {
                $loggerBak = $object->getApp()->logger;
                $object->getApp()->logger = $this;
            }
            if (TraitUtil::hasTrait($object, DebugTrait::class)) {
                $debugBak = $object->debug;
                $object->debug = true;
            }

            $this->output('--[ Executing ' . get_class($object) . '->' . $method . ' ]--------------');

            try {
                $result = $object->{$method}(...$args);
            } finally {
                if (TraitUtil::hasAppScopeTrait($object) && $object->issetApp()) {
                    $object->getApp()->logger = $loggerBak; // @phpstan-ignore variable.undefined
                }
                if (TraitUtil::hasTrait($object, DebugTrait::class)) {
                    $object->debug = $debugBak;
                }
            }
        } else {
            $this->output('--[ Executing ' . $object . '::' . $method . ' ]--------------');

            $result = $object::{$method}(...$args);
        }
        $this->output('--[ Result: ' . $this->getApp()->encodeJson($result) . ' ]------------');

        return $this;
    }

    #[\Override]
    public function emergency($message, array $context = []): void
    {
        $this->outputHtml('<font color="pink">' . $this->escapeOutputHtml($message) . '</font>', $context);
    }

    #[\Override]
    public function alert($message, array $context = []): void
    {
        $this->outputHtml('<font color="pink">' . $this->escapeOutputHtml($message) . '</font>', $context);
    }

    #[\Override]
    public function critical($message, array $context = []): void
    {
        $this->outputHtml('<font color="pink">' . $this->escapeOutputHtml($message) . '</font>', $context);
    }

    #[\Override]
    public function error($message, array $context = []): void
    {
        $this->outputHtml('<font color="pink">' . $this->escapeOutputHtml($message) . '</font>', $context);
    }

    #[\Override]
    public function warning($message, array $context = []): void
    {
        $this->outputHtml('<font color="pink">' . $this->escapeOutputHtml($message) . '</font>', $context);
    }

    #[\Override]
    public function notice($message, array $context = []): void
    {
        $this->outputHtml('<font color="yellow">' . $this->escapeOutputHtml($message) . '</font>', $context);
    }

    #[\Override]
    public function info($message, array $context = []): void
    {
        $this->outputHtml('<font color="gray">' . $this->escapeOutputHtml($message) . '</font>', $context);
    }

    #[\Override]
    public function debug($message, array $context = []): void
    {
        $this->outputHtml('<font color="cyan">' . $this->escapeOutputHtml($message) . '</font>', $context);
    }

    /**
     * @param LogLevel::* $level
     */
    #[\Override]
    public function log($level, $message, array $context = []): void
    {
        $this->{$level}($message, $context);
    }
}
