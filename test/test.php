<?php

declare(strict_types=1);

// Integration tests for the NusaDB PHP driver against a real server.
// Run: php drivers/php/test/test.php   (after `cargo build -p nusadb-server`).

require __DIR__ . '/../autoload.php';

use NusaDB\Connection;
use NusaDB\NusaException;
use NusaDB\Statement;

const REPO_ROOT_LEVELS = 3;

function repo_root(): string
{
    return dirname(__DIR__, REPO_ROOT_LEVELS);
}

function server_binary(): ?string
{
    $bases = [];
    $env = getenv('CARGO_TARGET_DIR');
    if ($env !== false && $env !== '') {
        $bases[] = $env;
    }
    $bases[] = repo_root() . DIRECTORY_SEPARATOR . 'target';
    $names = stripos(PHP_OS, 'WIN') === 0 ? ['nusadb-server.exe'] : ['nusadb-server'];
    foreach ($bases as $base) {
        foreach (['debug', 'release'] as $profile) {
            foreach ($names as $name) {
                $p = $base . DIRECTORY_SEPARATOR . $profile . DIRECTORY_SEPARATOR . $name;
                if (is_file($p)) {
                    return $p;
                }
            }
        }
    }
    return null;
}

function free_port(): int
{
    $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $name = stream_socket_get_name($sock, false);
    fclose($sock);
    return (int) substr($name, strrpos($name, ':') + 1);
}

function wait_ready(int $port): void
{
    $deadline = microtime(true) + 15.0;
    while (microtime(true) < $deadline) {
        $c = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 0.5);
        if ($c !== false) {
            fclose($c);
            return;
        }
        usleep(100000);
    }
    throw new RuntimeException("server on port {$port} did not become ready");
}

/** @return array{0:resource,1:int,2:string} process, port, data dir */
function start_server(array $extra = []): array
{
    $bin = server_binary();
    if ($bin === null) {
        fwrite(STDERR, "SKIP: nusadb-server binary not found; run `cargo build -p nusadb-server`\n");
        exit(0);
    }
    $port = free_port();
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nusadb-php-' . bin2hex(random_bytes(6));
    mkdir($dir);
    $cmd = array_merge([$bin, '--listen', "127.0.0.1:{$port}", '--data-dir', $dir], $extra);
    // PHP 7.2 proc_open takes a command STRING (the array form is 7.4+), so quote each argument.
    $cmdStr = implode(' ', array_map('escapeshellarg', $cmd));
    if (DIRECTORY_SEPARATOR === '\\') {
        // proc_open runs the string via `cmd /c`, which strips the outer quote pair; wrapping the
        // whole command in an extra pair preserves the per-argument quoting.
        $cmdStr = '"' . $cmdStr . '"';
    }
    $nullDevice = DIRECTORY_SEPARATOR === '/' ? '/dev/null' : 'NUL';
    $descriptors = [0 => ['pipe', 'r'], 1 => ['file', $nullDevice, 'w'], 2 => ['file', $nullDevice, 'w']];
    // Inherit the parent environment (null): on Windows a custom env array replaces it entirely,
    // which can break process creation. The server's logs go to the null device regardless.
    $proc = proc_open($cmdStr, $descriptors, $pipes, null, null);
    if (!is_resource($proc)) {
        throw new RuntimeException('failed to start server');
    }
    wait_ready($port);
    return [$proc, $port, $dir];
}

function getenv_all(): array
{
    $env = [];
    foreach ($_ENV as $k => $v) {
        $env[$k] = $v;
    }
    if (empty($env)) {
        // $_ENV may be empty depending on variables_order; fall back to getenv.
        foreach (['PATH', 'SystemRoot', 'TEMP', 'TMP', 'CARGO_TARGET_DIR'] as $k) {
            $v = getenv($k);
            if ($v !== false) {
                $env[$k] = $v;
            }
        }
    }
    return $env;
}

function stop_server(array $server): void
{
    [$proc, , $dir] = $server;
    proc_terminate($proc);
    proc_close($proc);
    @array_map('unlink', glob($dir . DIRECTORY_SEPARATOR . '*') ?: []);
    @rmdir($dir);
}

$passed = 0;
$failed = 0;

function check(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        $passed++;
        echo "ok   - {$name}\n";
    } catch (Throwable $t) {
        $failed++;
        echo "FAIL - {$name}\n      {$t->getMessage()}\n";
    }
}

function expect(bool $cond, string $what): void
{
    if (!$cond) {
        throw new RuntimeException("expectation failed: {$what}");
    }
}

$trust = start_server();
try {
    $port = $trust[1];
    $dsn = "nusadb:host=127.0.0.1;port={$port};dbname=nusadb";

    check('simple query round trip', function () use ($dsn) {
        $conn = new Connection($dsn, 'u');
        $conn->exec('CREATE TABLE php_simple (id INT NOT NULL, name TEXT)');
        $n = $conn->exec("INSERT INTO php_simple VALUES (5, 'alice')");
        expect($n === 1, 'insert count == 1');
        $stmt = $conn->query('SELECT id, name FROM php_simple');
        $rows = $stmt->fetchAll(Statement::FETCH_ASSOC);
        expect($rows === [['id' => 5, 'name' => 'alice']], 'one assoc row'); // INT -> int
        // Protocol 1.1 typed metadata (R42-B.03): per-column type names.
        $stmt = $conn->query('SELECT id, name FROM php_simple');
        expect($stmt->columnTypes() === ['INT', 'TEXT'], 'column types');
        expect($stmt->getColumnMeta(0) === ['name' => 'id', 'native_type' => 'INT'], 'column meta');
        $conn->close();
    });

    check('copy bulk load and export', function () use ($dsn) {
        $conn = new Connection($dsn, 'u');
        $conn->exec('CREATE TABLE php_copy (id INT NOT NULL, name TEXT, PRIMARY KEY (id))');

        // COPY FROM STDIN: tab-delimited rows, \N for NULL (string source).
        $loaded = $conn->copyIn('COPY php_copy FROM STDIN', "1\talice\n2\t\\N\n3\tcarol\n");
        expect($loaded === 3, 'copyIn loaded 3 rows');

        $rows = $conn->query('SELECT id, name FROM php_copy ORDER BY id')->fetchAll(Statement::FETCH_ASSOC);
        expect(
            $rows === [['id' => 1, 'name' => 'alice'], ['id' => 2, 'name' => null], ['id' => 3, 'name' => 'carol']],
            'rows with NULL'
        );

        // COPY TO STDOUT: write into a temp stream.
        $sink = fopen('php://temp', 'r+');
        $exported = $conn->copyOut('COPY php_copy TO STDOUT', $sink);
        expect($exported === 3, 'copyOut exported 3 rows');
        rewind($sink);
        $text = stream_get_contents($sink);
        fclose($sink);
        expect(explode("\n", rtrim($text, "\n")) === ["1\talice", "2\t\\N", "3\tcarol"], 'exported text');

        // A refused COPY throws; the connection stays usable.
        $threw = false;
        try {
            $conn->copyIn('COPY no_such_table FROM STDIN', '');
        } catch (NusaException $e) {
            $threw = true;
        }
        expect($threw, 'COPY into a missing table throws');
        $cnt = $conn->query('SELECT count(*) FROM php_copy')->fetchAll(Statement::FETCH_ASSOC);
        expect(count($cnt) === 1, 'connection usable');
        $conn->close();
    });

    check('bytea round trip', function () use ($dsn) {
        $conn = new Connection($dsn, 'u');
        $conn->exec('CREATE TABLE bt (id INT NOT NULL, data BYTEA, PRIMARY KEY (id))');
        $payload = "\xde\xad\xbe\xef\x00\x7f";
        // Write BYTEA via the \x<hex> text form (the server coerces it into the BYTEA column).
        $conn->exec("INSERT INTO bt VALUES (1, '\\x" . bin2hex($payload) . "')");
        $conn->exec("INSERT INTO bt VALUES (2, '\\x')");
        $conn->exec('INSERT INTO bt VALUES (3, NULL)');
        $rows = $conn->query('SELECT id, data FROM bt ORDER BY id')->fetchAll(Statement::FETCH_NUM);
        expect($rows[0][1] === $payload, 'bytea decodes to the original bytes');
        expect($rows[1][1] === '', 'empty bytea decodes to an empty string');
        expect($rows[2][1] === null, 'null bytea');
        $conn->close();
    });

    check('typed value decoding', function () use ($dsn) {
        $conn = new Connection($dsn, 'u');
        $conn->exec('CREATE TABLE php_typed (b BOOL, i INT, f FLOAT, n NUMERIC(10,2), j JSON, arr INT[])');
        $conn->exec("INSERT INTO php_typed VALUES (true, 42, 1.5, 12.34, JSON '{\"k\": 1}', ARRAY[1, 2, 3])");
        $row = $conn->query('SELECT b, i, f, n, j, arr FROM php_typed')->fetch(Statement::FETCH_NUM);
        expect($row[0] === true, 'BOOL -> bool true');
        expect($row[1] === 42, 'INT -> int 42');
        expect($row[2] === 1.5, 'FLOAT -> float 1.5');
        // NUMERIC stays a string (PHP has no lossless decimal; PDO convention).
        expect($row[3] === '12.34', 'NUMERIC -> string');
        expect($row[4] === ['k' => 1], 'JSON -> decoded array');
        // ARRAY -> PHP array; elements stay strings (the wire array tag carries no element type).
        expect($row[5] === ['1', '2', '3'], 'ARRAY -> array of strings');
        $conn->close();
    });

    check('null values decode to null', function () use ($dsn) {
        $conn = new Connection($dsn, 'u');
        $conn->exec('CREATE TABLE php_typed_null (b BOOL, i INT, arr INT[])');
        $conn->exec('INSERT INTO php_typed_null VALUES (NULL, NULL, NULL)');
        $row = $conn->query('SELECT b, i, arr FROM php_typed_null')->fetch(Statement::FETCH_NUM);
        expect($row === [null, null, null], 'all null');
        $conn->close();
    });

    check('query surface', function () use ($dsn) {
        $conn = new Connection($dsn, 'u');
        $conn->exec('CREATE TABLE surf_a (id INT NOT NULL, grp TEXT, v INT)');
        $conn->exec("INSERT INTO surf_a VALUES (1, 'a', 10), (2, 'a', 30), (3, 'b', 20), (4, 'b', 20), (5, 'a', 10)");
        $conn->exec('CREATE TABLE surf_b (id INT NOT NULL, a_id INT, tag TEXT)');
        $conn->exec("INSERT INTO surf_b VALUES (10, 1, 'p'), (11, 1, 'q'), (12, 2, 'r')");

        // [query, expected row count]
        $cases = [
            ['A ORDER BY', 'SELECT id FROM surf_a ORDER BY v DESC, id', 5],
            ['B DISTINCT', 'SELECT DISTINCT v FROM surf_a ORDER BY v', 3],
            ['C DISTINCT ON', 'SELECT DISTINCT ON (grp) grp, v FROM surf_a ORDER BY grp, v', 2],
            ['D LIMIT', 'SELECT id FROM surf_a ORDER BY id LIMIT 2', 2],
            ['E OFFSET', 'SELECT id FROM surf_a ORDER BY id LIMIT 2 OFFSET 3', 2],
            ['F GROUP/HAVING', 'SELECT grp, count(*) FROM surf_a GROUP BY grp HAVING count(*) > 1 ORDER BY grp', 2],
            ['G window', 'SELECT id, row_number() OVER (PARTITION BY grp ORDER BY v) FROM surf_a ORDER BY id', 5],
            ['H CTE', 'WITH g AS (SELECT grp, count(*) c FROM surf_a GROUP BY grp) SELECT count(*) FROM g', 1],
            ['I subquery IN', 'SELECT id FROM surf_a WHERE v IN (SELECT max(v) FROM surf_a) ORDER BY id', 1],
            ['J UNION', 'SELECT id FROM surf_a WHERE id = 1 UNION SELECT id FROM surf_a WHERE id = 2', 2],
            ['K INNER JOIN', 'SELECT surf_a.grp, surf_b.tag FROM surf_a JOIN surf_b ON surf_a.id = surf_b.a_id ORDER BY surf_b.id', 3],
            ['L LEFT JOIN', 'SELECT surf_a.grp, surf_b.tag FROM surf_a LEFT JOIN surf_b ON surf_a.id = surf_b.a_id ORDER BY surf_a.id, surf_b.id', 6],
            ['M RIGHT JOIN', 'SELECT surf_a.grp, surf_b.tag FROM surf_a RIGHT JOIN surf_b ON surf_a.id = surf_b.a_id ORDER BY surf_b.id', 3],
            ['N FULL JOIN', 'SELECT surf_a.grp, surf_b.tag FROM surf_a FULL JOIN surf_b ON surf_a.id = surf_b.a_id ORDER BY surf_a.id, surf_b.id', 6],
            ['O CROSS JOIN', 'SELECT surf_a.id, surf_b.id FROM surf_a CROSS JOIN surf_b', 15],
            ['P LATERAL', 'SELECT surf_a.id, l.tag FROM surf_a JOIN LATERAL (SELECT tag FROM surf_b WHERE surf_b.a_id = surf_a.id LIMIT 1) l ON true ORDER BY surf_a.id', 2],
        ];
        foreach ($cases as [$label, $sql, $want]) {
            $got = count($conn->query($sql)->fetchAll());
            expect($got === $want, "{$label}: {$got} rows == {$want}");
        }
        $conn->close();
    });

    check('parameterised query with NULL', function () use ($dsn) {
        $conn = new Connection($dsn, 'u');
        $conn->exec('CREATE TABLE php_params (id INT NOT NULL, name TEXT)');
        $ins = $conn->prepare('INSERT INTO php_params VALUES ($1, $2)');
        $ins->execute([1, 'alice']);
        $ins->execute([2, null]);
        $sel = $conn->prepare('SELECT id, name FROM php_params WHERE id = $1');
        $sel->execute([2]);
        $row = $sel->fetch(Statement::FETCH_NUM);
        expect($row === [2, null], 'row is [2, null]');
        $conn->close();
    });

    check('executeMany bulk insert', function () use ($dsn) {
        $conn = new Connection($dsn, 'u');
        $conn->exec('CREATE TABLE php_batch (id INT NOT NULL, name TEXT)');
        $counts = $conn->executeMany('INSERT INTO php_batch VALUES ($1, $2)', [[1, 'a'], [2, 'b'], [3, 'c']]);
        expect($counts === [1, 1, 1], 'each set affected 1 row');
        $stmt = $conn->query('SELECT count(*) FROM php_batch');
        expect($stmt->fetch(Statement::FETCH_NUM) === [3], 'three rows persisted');
        expect($conn->executeMany('INSERT INTO php_batch VALUES ($1, $2)', []) === [], 'empty batch is a no-op');
        $conn->close();
    });

    check('server error surfaces and connection survives', function () use ($dsn) {
        $conn = new Connection($dsn, 'u');
        $threw = false;
        try {
            $conn->query('SELECT * FROM ghost');
        } catch (NusaException $e) {
            $threw = true;
            expect(strlen($e->getSqlState()) === 5, '5-char SQLSTATE');
        }
        expect($threw, 'missing table throws');
        $stmt = $conn->query('SELECT 1');
        expect(count($stmt->fetchAll()) === 1, 'connection usable after error');
        $conn->close();
    });

    check('transaction commit persists', function () use ($dsn) {
        $conn = new Connection($dsn, 'u');
        $conn->exec('CREATE TABLE php_txn (id INT NOT NULL)');
        expect($conn->beginTransaction() === true, 'beginTransaction returns true');
        expect($conn->inTransaction(), 'inTransaction true after begin');
        $conn->exec('INSERT INTO php_txn VALUES (1)');
        expect($conn->commit() === true, 'commit returns true');
        expect(!$conn->inTransaction(), 'inTransaction false after commit');
        $stmt = $conn->query('SELECT count(*) FROM php_txn');
        expect($stmt->fetch(Statement::FETCH_NUM) === [1], 'committed row persists');
        $conn->close();
    });

    check('transaction rollback discards', function () use ($dsn) {
        $conn = new Connection($dsn, 'u');
        $conn->exec('CREATE TABLE php_txn_rb (id INT NOT NULL)');
        $conn->beginTransaction();
        $conn->exec('INSERT INTO php_txn_rb VALUES (1)');
        expect($conn->rollBack() === true, 'rollBack returns true');
        expect(!$conn->inTransaction(), 'inTransaction false after rollback');
        $stmt = $conn->query('SELECT count(*) FROM php_txn_rb');
        expect($stmt->fetch(Statement::FETCH_NUM) === [0], 'rolled-back row discarded');
        $conn->close();
    });

    check('savepoint rollback and release', function () use ($dsn) {
        $conn = new Connection($dsn, 'u');
        $conn->exec('CREATE TABLE php_sp (id INT NOT NULL)');
        $conn->beginTransaction();
        $conn->exec('INSERT INTO php_sp VALUES (1)');
        expect($conn->savepoint('sp1') === true, 'savepoint returns true');
        $conn->exec('INSERT INTO php_sp VALUES (2)');
        expect($conn->rollbackToSavepoint('sp1') === true, 'rollbackToSavepoint returns true');
        $conn->exec('INSERT INTO php_sp VALUES (3)');
        $conn->savepoint('sp2');
        $conn->exec('INSERT INTO php_sp VALUES (4)');
        expect($conn->releaseSavepoint('sp2') === true, 'releaseSavepoint returns true');
        $conn->commit();
        $stmt = $conn->query('SELECT count(*) FROM php_sp');
        expect($stmt->fetch(Statement::FETCH_NUM) === [3], 'savepoint kept 1,3,4 (2 rolled back)');
        // A savepoint with no active transaction throws.
        $threw = false;
        try {
            $conn->savepoint('nope');
        } catch (NusaException $e) {
            $threw = true;
        }
        expect($threw, 'savepoint with no active transaction throws');
        $conn->close();
    });

    check('LISTEN/NOTIFY self-delivery', function () use ($dsn) {
        $conn = new Connection($dsn, 'u');
        $conn->listen('php_chan');
        // Self-delivery: NOTIFY on a channel this connection listens to comes back to it.
        $conn->notify('php_chan', 'hello');
        $note = $conn->pollNotification(5000);
        expect($note !== null, 'a notification was delivered');
        expect($note->channel === 'php_chan', 'channel is php_chan');
        expect($note->payload === 'hello', 'payload is hello');
        // NOTIFY without a payload delivers an empty string.
        $conn->notify('php_chan');
        $note2 = $conn->pollNotification(5000);
        expect($note2 !== null && $note2->payload === '', 'empty payload');
        // After UNLISTEN, a further NOTIFY is not delivered (poll times out -> null).
        $conn->unlisten('php_chan');
        $conn->notify('php_chan', 'ignored');
        expect($conn->pollNotification(300) === null, 'no notification after unlisten');
        $conn->close();
    });

    check('transaction-state errors', function () use ($dsn) {
        $conn = new Connection($dsn, 'u');
        // commit / rollback with no active transaction throw.
        $threw = false;
        try {
            $conn->commit();
        } catch (NusaException $e) {
            $threw = true;
        }
        expect($threw, 'commit with no active transaction throws');
        $threw = false;
        try {
            $conn->rollBack();
        } catch (NusaException $e) {
            $threw = true;
        }
        expect($threw, 'rollBack with no active transaction throws');
        // a nested beginTransaction throws while one is open.
        $conn->beginTransaction();
        $threw = false;
        try {
            $conn->beginTransaction();
        } catch (NusaException $e) {
            $threw = true;
        }
        expect($threw, 'nested beginTransaction throws');
        $conn->rollBack();
        $conn->close();
    });
} finally {
    stop_server($trust);
}

$auth = start_server(['--auth-user', 'alice:secret']);
try {
    $port = $auth[1];
    $dsn = "nusadb:host=127.0.0.1;port={$port};dbname=nusadb";

    check('scram correct password', function () use ($dsn) {
        $conn = new Connection($dsn, 'alice', 'secret');
        $stmt = $conn->query('SELECT 1');
        expect(count($stmt->fetchAll()) === 1, 'authenticated query returns a row');
        $conn->close();
    });

    check('scram wrong password', function () use ($dsn) {
        $threw = false;
        try {
            new Connection($dsn, 'alice', 'wrong');
        } catch (NusaException $e) {
            $threw = true;
        }
        expect($threw, 'wrong password rejected');
    });
} finally {
    stop_server($auth);
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
