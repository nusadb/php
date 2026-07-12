<?php

declare(strict_types=1);

namespace NusaDB;

/**
 * A PDO-style connection to a NusaDB server (pure PHP — a real PDO driver would be a C extension,
 * so this mirrors the familiar PDO API over the Nusa Wire Protocol instead).
 *
 * Statements autocommit unless wrapped in an explicit transaction: {@see beginTransaction} issues
 * BEGIN, {@see commit} issues COMMIT, and {@see rollBack} issues ROLLBACK over the same connection.
 */
final class Connection
{
    /** @var resource */
    private $sock;
    /** @var int */
    private $counter = 0;
    /** @var bool */
    private $closed = false;
    /** @var bool */
    private $inTransaction = false;
    /** @var array|null */
    public $backendKey = null;
    /** @var Notification[] Async LISTEN/NOTIFY messages buffered while reading other responses. */
    private $notifications = [];

    /**
     * @param string $dsn      e.g. "nusadb:host=127.0.0.1;port=5678;dbname=nusadb"
     */
    public function __construct(string $dsn, string $user = 'nusa-root', ?string $password = 'nusa-root')
    {
        $params = self::parseDsn($dsn);
        $host = $params['host'] ?? '127.0.0.1';
        $port = (int) ($params['port'] ?? 5678);
        $database = $params['dbname'] ?? 'nusadb';

        $errno = 0;
        $errstr = '';
        // Disable Nagle's algorithm (tcp_nodelay, PHP 7.1+): the wire protocol is
        // request/response, so coalescing small frames only adds delayed-ACK latency.
        $ctx = stream_context_create(['socket' => ['tcp_nodelay' => true]]);
        $sock = @stream_socket_client(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            10.0,
            STREAM_CLIENT_CONNECT,
            $ctx
        );
        if ($sock === false) {
            throw new NusaException("nusadb: connect failed: {$errstr}", '08001');
        }
        $this->sock = $sock;
        $this->handshake($user, $database, $password);
    }

    private static function parseDsn(string $dsn): array
    {
        $prefix = 'nusadb:';
        if (strncmp($dsn, $prefix, strlen($prefix)) !== 0) {
            throw new NusaException("nusadb: DSN must start with 'nusadb:'", '08001');
        }
        $params = [];
        foreach (explode(';', substr($dsn, strlen($prefix))) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $params[trim($k)] = $v;
        }
        return $params;
    }

    private function send(string $frame): void
    {
        if (fwrite($this->sock, $frame) === false) {
            throw new NusaException('nusadb: socket write failed', '08006');
        }
    }

    private function readExact(int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($this->sock, $n - strlen($buf));
            if ($chunk === false || $chunk === '') {
                throw new NusaException('nusadb: server closed the connection', '08006');
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    /** @return array{0:int,1:Reader} the message type and a reader over its payload */
    private function readMessage(): array
    {
        $header = $this->readExact(Protocol::HEADER_LEN);
        $type = ord($header[0]);
        $total = unpack('N', substr($header, 1, 4))[1];
        if ($total < Protocol::HEADER_LEN || $total > Protocol::MAX_FRAME_LEN) {
            throw new NusaException("nusadb: invalid frame length {$total}", '08P01');
        }
        $payload = $total > Protocol::HEADER_LEN ? $this->readExact($total - Protocol::HEADER_LEN) : '';
        return [$type, new Reader($payload)];
    }

    private function handshake(string $user, string $database, ?string $password): void
    {
        $this->send(Protocol::startup($user, $database));
        while (true) {
            [$type, $r] = $this->readMessage();
            if ($type === Protocol::B_READY) {
                return;
            }
            if ($type === Protocol::B_ERROR) {
                throw self::serverError($r);
            }
            if ($type === Protocol::B_AUTH) {
                $sub = $r->u32();
                if ($sub === Protocol::AUTH_SASL) {
                    $this->scram($r, $user, $password);
                }
            } elseif ($type === Protocol::B_BACKEND_KEY) {
                $this->backendKey = ['pid' => $r->u32(), 'secret' => $r->u32()];
            }
        }
    }

    private function scram(Reader $offer, string $user, ?string $password): void
    {
        $count = $offer->u16();
        $supported = false;
        for ($i = 0; $i < $count; $i++) {
            if ($offer->str() === Protocol::SCRAM_MECHANISM) {
                $supported = true;
            }
        }
        if (!$supported) {
            throw new NusaException('nusadb: server offered no supported SASL mechanism', '28000');
        }
        if ($password === null) {
            throw new NusaException('nusadb: server requires a password but none was given', '28000');
        }

        $scram = Scram::start($user);
        $this->send(Protocol::saslInitial(Protocol::SCRAM_MECHANISM, $scram->fullClientFirst));

        [$type, $r] = $this->readMessage();
        if ($type === Protocol::B_ERROR) {
            throw self::serverError($r);
        }
        if ($type !== Protocol::B_AUTH || $r->u32() !== Protocol::AUTH_SASL_CONTINUE) {
            throw new NusaException('nusadb: expected a SASL continue message', '08P01');
        }
        $serverFirst = $r->rest();

        $this->send(Protocol::saslResponse($scram->clientFinal($password, $serverFirst)));

        [$type, $r] = $this->readMessage();
        if ($type === Protocol::B_ERROR) {
            throw self::serverError($r);
        }
        if ($type !== Protocol::B_AUTH || $r->u32() !== Protocol::AUTH_SASL_FINAL) {
            throw new NusaException('nusadb: expected a SASL final message', '08P01');
        }
        if (!$scram->verifyServerFinal($r->rest())) {
            throw new NusaException('nusadb: server signature did not verify', '28000');
        }
    }

    /** @return array{columns:string[],column_types:array<?string>,rows:array<array<?string>>,tag:?string} */
    public function collect(): array
    {
        $columns = [];
        $columnTypes = [];
        $rows = [];
        $tag = null;
        $error = null;
        while (true) {
            [$type, $r] = $this->readMessage();
            switch ($type) {
                case Protocol::B_ROW_DESCRIPTION:
                    $n = $r->u16();
                    $columns = [];
                    $columnTypes = [];
                    for ($i = 0; $i < $n; $i++) {
                        $columns[] = $r->str();
                        $columnTypes[] = null;
                    }
                    break;
                case Protocol::B_ROW_DESCRIPTION_TYPED:
                    // Protocol 1.1: each column is a name plus a 1-byte type tag (§9.2).
                    $n = $r->u16();
                    $columns = [];
                    $columnTypes = [];
                    for ($i = 0; $i < $n; $i++) {
                        $columns[] = $r->str();
                        $columnTypes[] = Protocol::typeName($r->u8());
                    }
                    break;
                case Protocol::B_DATA_ROW:
                    $rows[] = $r->fields();
                    break;
                case Protocol::B_COMMAND_COMPLETE:
                    $tag = $r->str();
                    break;
                case Protocol::B_NOTIFICATION:
                    // A pending LISTEN/NOTIFY message can lead the next query's response; buffer it.
                    $this->notifications[] = self::decodeNotification($r);
                    break;
                case Protocol::B_ERROR:
                    $error = self::serverError($r);
                    break;
                case Protocol::B_READY:
                    if ($error !== null) {
                        throw $error;
                    }
                    return ['columns' => $columns, 'column_types' => $columnTypes, 'rows' => $rows, 'tag' => $tag];
                default:
                    break;
            }
        }
    }

    public function runSimple(string $sql): array
    {
        $this->send(Protocol::query($sql));
        return $this->collect();
    }

    /** @param array<?string> $params */
    public function runExtended(string $sql, array $params): array
    {
        $this->send(Protocol::parse('', $sql));
        $this->send(Protocol::bind('', '', $params));
        $this->send(Protocol::describePortal(''));
        $this->send(Protocol::execute('', 0));
        $this->send(Protocol::sync());
        return $this->collect();
    }

    public function prepareWire(string $sql): string
    {
        $name = 'nusa_stmt_' . (++$this->counter);
        $this->send(Protocol::parse($name, $sql));
        $this->send(Protocol::sync());
        $this->collect();
        return $name;
    }

    /** @param array<?string> $params */
    public function runPrepared(string $name, array $params): array
    {
        $portal = 'nusa_portal_' . (++$this->counter);
        $this->send(Protocol::bind($portal, $name, $params));
        $this->send(Protocol::describePortal($portal));
        $this->send(Protocol::execute($portal, 0));
        $this->send(Protocol::sync());
        return $this->collect();
    }

    // --- COPY bulk load / export (wire-protocol.md §12) ---

    /**
     * Bulk-load via COPY ... FROM STDIN (§12.1). $source is a stream resource or a string already in
     * the server's text format (tab-delimited fields, \N for SQL NULL, one row per line). Returns the
     * loaded row count. A read error on a stream sends CopyFail; a refused COPY (bad SQL, an
     * RLS-protected table) throws — either way the connection is left ready.
     *
     * @param resource|string $source
     */
    public function copyIn(string $sql, $source): int
    {
        $this->send(Protocol::query($sql));
        $this->awaitCopyStart(Protocol::B_COPY_IN);
        if (is_resource($source)) {
            while (!feof($source)) {
                $chunk = fread($source, 65536);
                if ($chunk === false) {
                    $this->send(Protocol::copyFail('nusadb: client read error during COPY'));
                    $this->drainToReady();
                    throw new NusaException('nusadb: COPY source read failed', '57014');
                }
                if ($chunk !== '') {
                    $this->send(Protocol::copyData($chunk));
                }
            }
        } else {
            $len = strlen($source);
            for ($off = 0; $off < $len; $off += 65536) {
                $this->send(Protocol::copyData(substr($source, $off, 65536)));
            }
        }
        $this->send(Protocol::copyDone());
        return $this->finishCopy();
    }

    /**
     * Bulk-export via COPY ... TO STDOUT (§12.2). Writes each data chunk to $sink (a stream resource)
     * in the server's text format; returns the exported row count.
     *
     * @param resource $sink
     */
    public function copyOut(string $sql, $sink): int
    {
        $this->send(Protocol::query($sql));
        $this->awaitCopyStart(Protocol::B_COPY_OUT);
        while (true) {
            [$type, $r] = $this->readMessage();
            if ($type === Protocol::B_COPY_DATA) {
                fwrite($sink, $r->rest());
            } elseif ($type === Protocol::B_COPY_DONE) {
                break;
            } elseif ($type === Protocol::B_ERROR) {
                $err = self::serverError($r);
                $this->drainToReady();
                throw $err;
            }
        }
        return $this->finishCopy();
    }

    private function awaitCopyStart(int $expected): void
    {
        $error = null;
        while (true) {
            [$type, $r] = $this->readMessage();
            if ($type === $expected) {
                return;
            } elseif ($type === Protocol::B_ERROR) {
                $error = self::serverError($r);
            } elseif ($type === Protocol::B_READY) {
                throw $error ?? new NusaException('nusadb: COPY did not start', '08P01');
            }
        }
    }

    private function finishCopy(): int
    {
        $tag = null;
        $error = null;
        while (true) {
            [$type, $r] = $this->readMessage();
            if ($type === Protocol::B_COMMAND_COMPLETE) {
                $tag = $r->str();
            } elseif ($type === Protocol::B_ERROR) {
                $error = self::serverError($r);
            } elseif ($type === Protocol::B_READY) {
                if ($error !== null) {
                    throw $error;
                }
                return self::copyCount($tag);
            }
        }
    }

    private function drainToReady(): void
    {
        while (true) {
            [$type, $r] = $this->readMessage();
            if ($type === Protocol::B_READY) {
                return;
            }
        }
    }

    private static function copyCount(?string $tag): int
    {
        if ($tag === null) {
            return 0;
        }
        $sp = strrpos($tag, ' ');
        if ($sp === false) {
            return 0;
        }
        $n = substr($tag, $sp + 1);
        return ctype_digit($n) ? (int) $n : 0;
    }

    // --- PDO-style API ---

    public function query(string $sql): Statement
    {
        $stmt = new Statement($this, $sql, false);
        $stmt->execute();
        return $stmt;
    }

    public function prepare(string $sql): Statement
    {
        return new Statement($this, $sql, true);
    }

    public function exec(string $sql): int
    {
        $result = $this->runSimple($sql);
        return Statement::affected($result['tag']);
    }

    /**
     * Run one statement once per parameter set, reusing a single prepared statement — the bulk
     * insert/update path. Returns an array of per-set affected-row counts. One parse, then one
     * execute per set (the wire protocol has no batch pipeline, so this is N round-trips, not one);
     * the first failing set throws.
     *
     * @param array<int,array<int,mixed>> $paramSets
     * @return int[]
     */
    public function executeMany(string $sql, array $paramSets): array
    {
        $stmt = $this->prepare($sql);
        $counts = [];
        foreach ($paramSets as $params) {
            $stmt->execute($params);
            $counts[] = $stmt->rowCount();
        }
        return $counts;
    }

    public function beginTransaction(): bool
    {
        if ($this->inTransaction) {
            throw new NusaException('nusadb: there is already an active transaction', '25000');
        }
        $this->runSimple('BEGIN');
        $this->inTransaction = true;
        return true;
    }

    public function commit(): bool
    {
        if (!$this->inTransaction) {
            throw new NusaException('nusadb: there is no active transaction', '25000');
        }
        $this->runSimple('COMMIT');
        $this->inTransaction = false;
        return true;
    }

    public function rollBack(): bool
    {
        if (!$this->inTransaction) {
            throw new NusaException('nusadb: there is no active transaction', '25000');
        }
        $this->runSimple('ROLLBACK');
        $this->inTransaction = false;
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    /**
     * Establishes a savepoint inside the open transaction (`SAVEPOINT name`) — a named marker you
     * can later roll back to without ending the transaction.
     */
    public function savepoint(string $name): bool
    {
        if (!$this->inTransaction) {
            throw new NusaException('nusadb: no active transaction for a savepoint', '25000');
        }
        $this->runSimple('SAVEPOINT ' . self::quoteIdentifier($name));
        return true;
    }

    /** Rolls back to a savepoint (`ROLLBACK TO SAVEPOINT name`); the transaction stays open. */
    public function rollbackToSavepoint(string $name): bool
    {
        if (!$this->inTransaction) {
            throw new NusaException('nusadb: no active transaction for a savepoint', '25000');
        }
        $this->runSimple('ROLLBACK TO SAVEPOINT ' . self::quoteIdentifier($name));
        return true;
    }

    /** Releases (forgets) a savepoint (`RELEASE SAVEPOINT name`), keeping its work. */
    public function releaseSavepoint(string $name): bool
    {
        if (!$this->inTransaction) {
            throw new NusaException('nusadb: no active transaction for a savepoint', '25000');
        }
        $this->runSimple('RELEASE SAVEPOINT ' . self::quoteIdentifier($name));
        return true;
    }

    /**
     * Quotes a savepoint identifier so an arbitrary name is emitted safely; the server folds a
     * quoted identifier to its literal value, so the same quoting matches across
     * SAVEPOINT/ROLLBACK/RELEASE.
     */
    private static function quoteIdentifier(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    /** Quotes a string as a SQL literal ('…', doubling '), for a NOTIFY payload. */
    private static function quoteLiteral(string $text): string
    {
        return "'" . str_replace("'", "''", $text) . "'";
    }

    private static function decodeNotification(Reader $r): Notification
    {
        return new Notification($r->u32(), $r->str(), $r->str());
    }

    /**
     * Subscribes to asynchronous notifications on $channel (`LISTEN channel`). Collect them with
     * {@see getNotifications} or {@see pollNotification}.
     */
    public function listen(string $channel): void
    {
        $this->runSimple('LISTEN ' . self::quoteIdentifier($channel));
    }

    /** Stops listening on $channel (`UNLISTEN channel`); null unlistens all (`UNLISTEN *`). */
    public function unlisten(?string $channel = null): void
    {
        $target = $channel === null ? '*' : self::quoteIdentifier($channel);
        $this->runSimple('UNLISTEN ' . $target);
    }

    /** Sends a notification on $channel with an optional $payload (`NOTIFY channel[, 'payload']`). */
    public function notify(string $channel, ?string $payload = null): void
    {
        $sql = 'NOTIFY ' . self::quoteIdentifier($channel);
        if ($payload !== null) {
            $sql .= ', ' . self::quoteLiteral($payload);
        }
        $this->runSimple($sql);
    }

    /**
     * Returns and clears the notifications already received (buffered while reading other responses).
     * Does not touch the socket — use {@see pollNotification} to wait for new ones.
     *
     * @return Notification[]
     */
    public function getNotifications(): array
    {
        $pending = $this->notifications;
        $this->notifications = [];
        return $pending;
    }

    /**
     * Waits up to $timeoutMillis for the next notification (null on timeout; 0 polls without
     * blocking). Returns a buffered notification immediately if one is pending. Only meaningful
     * after {@see listen}.
     */
    public function pollNotification(int $timeoutMillis = 0): ?Notification
    {
        if (!empty($this->notifications)) {
            return array_shift($this->notifications);
        }
        stream_set_timeout($this->sock, intdiv($timeoutMillis, 1000), ($timeoutMillis % 1000) * 1000);
        $header = fread($this->sock, Protocol::HEADER_LEN);
        $meta = stream_get_meta_data($this->sock);
        // Restore blocking-ish reads for subsequent queries.
        stream_set_timeout($this->sock, (int) ini_get('default_socket_timeout'));
        if (($header === '' || $header === false) && !empty($meta['timed_out'])) {
            return null;
        }
        if ($header === '' || $header === false) {
            throw new NusaException('nusadb: server closed the connection', '08006');
        }
        while (strlen($header) < Protocol::HEADER_LEN) {
            $header .= $this->readExact(Protocol::HEADER_LEN - strlen($header));
        }
        $type = ord($header[0]);
        $total = unpack('N', substr($header, 1, 4))[1];
        $payload = $total > Protocol::HEADER_LEN ? $this->readExact($total - Protocol::HEADER_LEN) : '';
        $r = new Reader($payload);
        if ($type === Protocol::B_NOTIFICATION) {
            return self::decodeNotification($r);
        }
        if ($type === Protocol::B_ERROR) {
            throw self::serverError($r);
        }
        throw new NusaException('nusadb: unexpected message while polling for notifications', '08006');
    }

    public function close(): void
    {
        if (!$this->closed) {
            $this->closed = true;
            @$this->send(Protocol::terminate());
            @fclose($this->sock);
        }
    }

    private static function serverError(Reader $r): NusaException
    {
        $code = $r->str();
        $message = $r->str();
        return new NusaException("nusadb: {$message}", $code);
    }
}
