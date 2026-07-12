<?php

declare(strict_types=1);

namespace NusaDB;

/**
 * An asynchronous LISTEN/NOTIFY message delivered by the server. Obtained from
 * {@see Connection::getNotifications} / {@see Connection::pollNotification}.
 */
final class Notification
{
    /** @var int Backend pid of the connection that issued the NOTIFY. */
    public $pid;
    /** @var string The channel the notification was sent on. */
    public $channel;
    /** @var string The payload (empty string when NOTIFY carried none). */
    public $payload;

    public function __construct(int $pid, string $channel, string $payload)
    {
        $this->pid = $pid;
        $this->channel = $channel;
        $this->payload = $payload;
    }
}
