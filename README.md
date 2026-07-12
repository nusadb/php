# nusadb — PHP driver for NusaDB

A pure-PHP client (no extension to compile) that speaks the
[Nusa Wire Protocol](../../docs/wire-protocol.md) (`PROTOCOL_VERSION 1.1`) directly
over a socket, exposing a familiar **PDO-style** API. `Statement::getColumnMeta($i)`
reports each column's NusaDB type under `native_type` (protocol 1.1). SCRAM-SHA-256 uses PHP's
built-in `hash`/`hash_hmac`/`hash_pbkdf2`. Requires PHP 7.2+.

> A *real* PDO driver is a C extension; this package implements the same connection /
> statement shape in pure PHP over the Nusa protocol, so application code reads like PDO
> without needing a compiled extension.

## Install

Via Composer (PSR-4 autoloading):

```bash
composer require nusadb/nusadb
```

Or without Composer, include the bundled autoloader:

```php
require '/path/to/drivers/php/autoload.php';
```

## Usage

```php
use NusaDB\Connection;
use NusaDB\Statement;

$conn = new Connection('nusadb:host=127.0.0.1;port=5678;dbname=nusadb', 'nusa-root', 'nusa-root');

$conn->exec('CREATE TABLE t (id INT NOT NULL, name TEXT)');

$ins = $conn->prepare('INSERT INTO t VALUES ($1, $2)');
$ins->execute([1, 'alice']);

$sel = $conn->prepare('SELECT id, name FROM t WHERE id = $1');
$sel->execute([1]);
foreach ($sel->fetchAll(Statement::FETCH_ASSOC) as $row) {
    echo $row['id'], ' ', $row['name'], "\n";
}

$conn->close();
```

### Value types

Each cell decodes to the natural PHP type for its protocol 1.1 type tag: `BOOL` → `bool`,
`INT` → `int`, `FLOAT` → `float`, `JSON` → the decoded value, `ARRAY` → `array` (elements stay
strings — the wire array tag carries no element type), `BYTEA` → a raw byte string. `NUMERIC`,
`DATE`, `TIMESTAMP`, `TIME`, `UUID`, `INTERVAL`, and `TEXT` stay strings (the PDO convention — PHP
has no lossless native type for them). A value that does not parse as its tag falls back to the raw
string, so an unexpected wire form never raises.

### DSN & parameters

DSN: `nusadb:host=127.0.0.1;port=5678;dbname=nusadb`. The user and password are the
2nd and 3rd constructor arguments. Placeholders are positional `$1`, `$2`, …; pass
the bound values to `execute([...])` (1-based order). `null` is SQL `NULL`.

### Batch (bulk insert/update)

`$conn->executeMany($sql, $paramSets)` runs one statement once per parameter set, reusing a single
prepared statement, and returns an array of per-set affected-row counts. The wire protocol has no
batch pipeline, so this is N round-trips, not one.

```php
$counts = $conn->executeMany('INSERT INTO t VALUES ($1, $2)', [[1, 'a'], [2, 'b'], [3, 'c']]);
```

### Bulk load / export (`COPY`)

For high-throughput load/export, `copyIn` / `copyOut` drive the `COPY` sub-protocol — one round-trip
for the whole dataset. Move bytes in the server's text format (tab-delimited fields, `\N` for SQL
`NULL`, one row per line); you write the `COPY` statement with any `WITH (...)` options.

```php
// Bulk load from a string or a stream resource.
$loaded = $conn->copyIn('COPY t (id, name) FROM STDIN', "1\talice\n2\t\\N\n");

// Bulk export into a stream resource.
$sink = fopen('php://temp', 'r+');
$exported = $conn->copyOut('COPY t TO STDOUT', $sink);
```

A `COPY` the server refuses (bad SQL, an RLS-protected table) throws; the connection stays usable.

### Authentication

For a server started with `--auth-user USER:PASSWORD`, pass the password as the third
constructor argument; the driver runs SCRAM-SHA-256 and verifies the server signature
(mutual auth, `hash_equals`).

## Transactions

Statements autocommit unless wrapped in an explicit transaction. `beginTransaction()`,
`commit()`, and `rollBack()` issue `BEGIN`, `COMMIT`, and `ROLLBACK` over the connection;
`inTransaction()` reports whether one is open. Calling `commit()`/`rollBack()` with no
active transaction, or a nested `beginTransaction()`, throws `NusaException` (PDO-style).

```php
$conn = new NusaDB\Connection("nusadb:host=127.0.0.1;port=5678;dbname=nusadb");
$conn->beginTransaction();
$conn->exec("INSERT INTO t VALUES (1)");
$conn->commit(); // or $conn->rollBack();
```

Inside a transaction, `savepoint($name)` marks a point you can later undo to with
`rollbackToSavepoint($name)` (the transaction stays open) or forget with `releaseSavepoint($name)`.

## Notifications (LISTEN/NOTIFY)

`listen($channel)` subscribes the connection; a `notify($channel, $payload)` from any connection on
the same database is then delivered asynchronously. `pollNotification($timeoutMillis)` waits for the
next one (`0` polls without blocking), or `getNotifications()` drains those buffered during other queries:

```php
$conn->listen('orders');
// ... elsewhere: $conn->notify('orders', '42');
$note = $conn->pollNotification(5000); // -> NusaDB\Notification, or null on timeout
echo $note->channel, ' ', $note->payload;
$conn->unlisten('orders');
```

## Test

```bash
cargo build -p nusadb-server
php drivers/php/test/test.php
```

The test boots a real `nusadb-server` (ephemeral port, honouring `CARGO_TARGET_DIR`)
and covers simple/parameterised/prepared queries, errors, transaction commit/rollback
and transaction-state errors, and SCRAM auth.

## License

Apache-2.0.
