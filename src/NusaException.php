<?php

declare(strict_types=1);

namespace NusaDB;

/** A driver or server error. {@see getSqlState} carries the 5-character SQLSTATE when present. */
final class NusaException extends \RuntimeException
{
    /** @var string */
    private $sqlState;

    public function __construct(string $message, string $sqlState = 'HY000')
    {
        parent::__construct($message);
        $this->sqlState = $sqlState;
    }

    public function getSqlState(): string
    {
        return $this->sqlState;
    }
}
