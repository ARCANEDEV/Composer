<?php namespace Arcanedev\Composer\Utilities;

use Composer\IO\IOInterface;

/**
 * Class     Logger
 *
 * @package  Arcanedev\Composer\Utilities
 * @author   ARCANEDEV <arcanedev.maroc@gmail.com>
 */
class Logger
{
    /* ------------------------------------------------------------------------------------------------
     |  Properties
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * The log name.
     *
     * @var string $name
     */
    protected $name;

    /**
     * The log IO instance.
     *
     * @var IOInterface $io
     */
    protected $io;

    /* ------------------------------------------------------------------------------------------------
     |  Constructor
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * @param  string       $name
     * @param  IOInterface  $io
     */
    public function __construct($name, IOInterface $io)
    {
        $this->name = $name;
        $this->io   = $io;
    }

    /* ------------------------------------------------------------------------------------------------
     |  Main Functions
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Log an info message.
     *
     * Messages will be output at the "verbose" logging level
     * (eg `-v` needed on the Composer command).
     *
     * @param  string  $message
     */
    public function info($message)
    {
        if ($this->io->isVerbose()) {
            $message = "  <info>[{$this->name}]</info> {$message}";

            $this->log($message);
        }
    }

    /**
     * Log a debug message.
     *
     * Messages will be output at the "very verbose" logging level
     * (eg `-vv` needed on the Composer command).
     *
     * @param  string  $message
     */
    public function debug($message)
    {
        if ($this->io->isVeryVerbose()) {
            $message = "  <info>[{$this->name}]</info> {$message}";

            $this->log($message);
        }
    }

    /**
     * Log a warning message.
     *
     * @param  string  $message
     */
    public function warning($message)
    {
        $message = "  <error>[{$this->name}]</error> {$message}";

        $this->log($message);
    }

    /**
     * Write a message.
     *
     * @param  string  $message
     */
    protected function log($message)
    {
        if (method_exists($this->io, 'writeError')) {
            $this->io->writeError($message);
        } else {
            // @codeCoverageIgnoreStart
                $this->io->write($message);
            // @codeCoverageIgnoreEn
        }
    }
}
