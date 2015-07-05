<?php namespace Arcanedev\Composer\Helpers;

use Composer\IO\IOInterface;

class Log
{
    /* ------------------------------------------------------------------------------------------------
     |  Properties
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * @var IOInterface $io
     */
    protected $io;

    /* ------------------------------------------------------------------------------------------------
     |  Getters & Setters
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Set the IO
     *
     * @param  IOInterface $io
     *
     * @return self
     */
    public function setIO($io)
    {
        $this->io = $io;

        return $this;
    }

    /* ------------------------------------------------------------------------------------------------
     |  Constructor
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * @param IOInterface $io
     */
    public function __construct(IOInterface $io)
    {
        $this->setIO($io);
    }

    /* ------------------------------------------------------------------------------------------------
     |  Main Functions
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Log a debug message
     *
     * Messages will be output at the "verbose" logging level
     * (eg `-v` needed on the Composer command).
     *
     * @param string $message
     */
    public function debug($message)
    {
        if ($this->io->isVerbose()) {
            $message = "  <info>[merge]</info> {$message}";

            if (method_exists($this->io, 'writeError')) {
                $this->io->writeError($message);
            }
            else {
                // Backwards compatibility for Composer before cb336a5
                $this->io->write($message);
            }
        }
    }
}
