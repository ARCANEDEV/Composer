<?php namespace Arcanedev\Composer\Utilities;

use Composer\IO\IOInterface;

/**
 * Class Logger
 * @package Arcanedev\Composer\Helpers
 */
class Logger
{
    /* ------------------------------------------------------------------------------------------------
     |  Properties
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * @var string $name
     */
    protected $name;

    /**
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
     * Logger a debug message
     *
     * Messages will be output at the "verbose" logging level
     * (eg `-v` needed on the Composer command).
     *
     * @param  string  $message
     */
    public function debug($message)
    {
        if ($this->io->isVerbose()) {
            $message = "  <info>[{$this->name}]</info> {$message}";

            if (method_exists($this->io, 'writeError')) {
                $this->io->writeError($message);
            }
            else {
                // @codeCoverageIgnoreStart
                // Backwards compatibility for Composer before cb336a5
                $this->io->write($message);
                // @codeCoverageIgnoreEnd
            }
        }
    }
}
