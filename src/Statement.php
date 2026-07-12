<?php

declare(strict_types=1);

namespace NusaDB;

/** A PDO-style statement. Placeholders are positional $1, $2, …. */
final class Statement
{
    public const FETCH_ASSOC = 1;
    public const FETCH_NUM = 2;
    public const FETCH_BOTH = 3;

    /** @var Connection */
    private $conn;
    /** @var string */
    private $sql;
    /** @var bool */
    private $prepared;
    /** @var string|null */
    private $wireName = null;
    /** @var array<int,?string> */
    private $bound = [];
    /** @var string[] */
    private $columns = [];
    /** @var array<?string> per-column type name (protocol 1.1), parallel to $columns */
    private $columnTypes = [];
    /** @var array<array<?string>> */
    private $rows = [];
    /** @var int */
    private $cursor = 0;
    /** @var int */
    private $rowCount = 0;

    public function __construct(Connection $conn, string $sql, bool $prepared)
    {
        $this->conn = $conn;
        $this->sql = $sql;
        $this->prepared = $prepared;
        if ($prepared) {
            $this->wireName = $conn->prepareWire($sql);
        }
    }

    /** Bind a value to placeholder $position (1-based). */
    public function bindValue(int $position, $value): void
    {
        $this->bound[$position] = self::encode($value);
    }

    /**
     * Execute, optionally with an ordered list of parameters (1-based order).
     * @param array<int,mixed>|null $params
     */
    public function execute(?array $params = null): bool
    {
        if ($params !== null) {
            $this->bound = [];
            $i = 1;
            foreach ($params as $value) {
                $this->bound[$i++] = self::encode($value);
            }
        }

        $ordered = [];
        if (!empty($this->bound)) {
            $max = max(array_keys($this->bound));
            for ($i = 1; $i <= $max; $i++) {
                $ordered[] = $this->bound[$i] ?? null;
            }
        }

        if ($this->prepared) {
            $result = $this->conn->runPrepared($this->wireName, $ordered);
        } elseif (!empty($ordered)) {
            $result = $this->conn->runExtended($this->sql, $ordered);
        } else {
            $result = $this->conn->runSimple($this->sql);
        }

        $this->columns = $result['columns'];
        $this->columnTypes = $result['column_types'] ?? array_fill(0, count($result['columns']), null);
        $this->rows = self::decodeColumns($result['rows'], $this->columnTypes);
        $this->cursor = 0;
        $this->rowCount = empty($this->columns) ? self::affected($result['tag']) : count($this->rows);
        return true;
    }

    /** @return array<string|int,?string>|false */
    public function fetch(int $mode = self::FETCH_ASSOC)
    {
        if ($this->cursor >= count($this->rows)) {
            return false;
        }
        $row = $this->rows[$this->cursor++];
        return $this->shape($row, $mode);
    }

    /** @return array<int,array<string|int,?string>> */
    public function fetchAll(int $mode = self::FETCH_ASSOC): array
    {
        $out = [];
        while (($row = $this->fetch($mode)) !== false) {
            $out[] = $row;
        }
        return $out;
    }

    /** @return ?string the first column of the next row, or null */
    public function fetchColumn()
    {
        $row = $this->fetch(self::FETCH_NUM);
        return $row === false ? null : ($row[0] ?? null);
    }

    public function rowCount(): int
    {
        return $this->rowCount;
    }

    public function columnCount(): int
    {
        return count($this->columns);
    }

    /**
     * PDO-style column metadata (protocol 1.1, R42-B.03): the column name and its NusaDB type name
     * under `native_type` (null if the server did not report it). Returns null for an out-of-range
     * column.
     *
     * @return array{name:string,native_type:?string}|null
     */
    public function getColumnMeta(int $column): ?array
    {
        if ($column < 0 || $column >= count($this->columns)) {
            return null;
        }
        return [
            'name' => $this->columns[$column],
            'native_type' => $this->columnTypes[$column] ?? null,
        ];
    }

    /** @return array<?string> per-column NusaDB type names (protocol 1.1), parallel to columns */
    public function columnTypes(): array
    {
        return $this->columnTypes;
    }

    /** @param array<?string> $row */
    private function shape(array $row, int $mode): array
    {
        if ($mode === self::FETCH_NUM) {
            return $row;
        }
        $assoc = [];
        foreach ($this->columns as $i => $name) {
            $assoc[$name] = $row[$i] ?? null;
        }
        if ($mode === self::FETCH_BOTH) {
            return $assoc + $row;
        }
        return $assoc;
    }

    public static function affected(?string $tag): int
    {
        if ($tag === null) {
            return 0;
        }
        $parts = explode(' ', $tag);
        $last = end($parts);
        return ctype_digit($last) ? (int) $last : 0;
    }

    private static function encode($value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return (string) $value;
    }

    /**
     * Decode each cell to the natural PHP type for its protocol 1.1 type tag: BOOL -> bool, INT ->
     * int, FLOAT -> float, JSON -> the decoded value, ARRAY -> array, BYTES -> raw byte string.
     * NUMERIC, DATE, TIMESTAMP, TIME, UUID, INTERVAL, and TEXT stay strings (PDO convention — PHP has
     * no lossless native type for them). To write BYTEA, pass the `\x<hex>` text the server coerces.
     *
     * @param array<int,array<?string>> $rows
     * @param array<?string> $types
     * @return array<int,array<int,mixed>>
     */
    private static function decodeColumns(array $rows, array $types): array
    {
        foreach ($rows as &$row) {
            foreach ($row as $i => $cell) {
                if ($cell !== null) {
                    $row[$i] = self::decodeCell($cell, $types[$i] ?? null);
                }
            }
        }
        unset($row);
        return $rows;
    }

    /**
     * Decode one non-null text cell by its type tag; a value that does not parse as its tag falls
     * back to the raw string, so an unexpected wire form never raises.
     *
     * @return mixed
     */
    private static function decodeCell(string $cell, ?string $type)
    {
        switch ($type) {
            case 'BYTES':
                $hex = substr($cell, 0, 2) === '\x' ? substr($cell, 2) : $cell;
                $decoded = hex2bin($hex);
                return $decoded === false ? $cell : $decoded;
            case 'BOOL':
                return $cell === 'true' || $cell === 't' || $cell === '1';
            case 'INT':
                $n = filter_var($cell, FILTER_VALIDATE_INT);
                return $n === false ? $cell : $n;
            case 'FLOAT':
                $f = filter_var($cell, FILTER_VALIDATE_FLOAT);
                return $f === false ? $cell : $f;
            case 'JSON':
                $decoded = json_decode($cell, true);
                return json_last_error() === JSON_ERROR_NONE ? $decoded : $cell;
            case 'ARRAY':
                try {
                    return self::parseArray($cell);
                } catch (\Throwable $e) {
                    return $cell;
                }
            default:
                return $cell;
        }
    }

    /**
     * Parse a SQL array literal (`{a,b,c}`, possibly nested, quoted, or with NULL) into a PHP
     * array. Elements stay strings (the wire array tag carries no per-element type); a quoted element
     * is unescaped and an unquoted NULL becomes null.
     *
     * @return array<int,mixed>
     */
    private static function parseArray(string $text): array
    {
        if (($text[0] ?? '') !== '{') {
            throw new \RuntimeException('not an array');
        }
        $pos = 0;
        $result = self::parseArrayAt($text, $pos);
        if ($pos !== strlen($text)) {
            throw new \RuntimeException('trailing array data');
        }
        return $result;
    }

    /** @return array<int,mixed> */
    private static function parseArrayAt(string $text, int &$pos): array
    {
        $pos++; // consume the opening '{'
        $items = [];
        if (($text[$pos] ?? '') === '}') {
            $pos++;
            return $items;
        }
        while (true) {
            $ch = $text[$pos] ?? '';
            if ($ch === '{') {
                $items[] = self::parseArrayAt($text, $pos);
            } elseif ($ch === '"') {
                $items[] = self::parseQuotedAt($text, $pos);
            } else {
                $items[] = self::parseUnquotedAt($text, $pos);
            }
            $sep = $text[$pos] ?? '';
            if ($sep === ',') {
                $pos++;
                continue;
            }
            if ($sep === '}') {
                $pos++;
                break;
            }
            throw new \RuntimeException('malformed array');
        }
        return $items;
    }

    private static function parseQuotedAt(string $text, int &$pos): string
    {
        $pos++; // opening quote
        $out = '';
        $len = strlen($text);
        while ($pos < $len && $text[$pos] !== '"') {
            if ($text[$pos] === '\\' && $pos + 1 < $len) {
                $pos++;
            }
            $out .= $text[$pos];
            $pos++;
        }
        if ($pos >= $len) {
            throw new \RuntimeException('unterminated array element');
        }
        $pos++; // closing quote
        return $out;
    }

    /** @return ?string */
    private static function parseUnquotedAt(string $text, int &$pos): ?string
    {
        $start = $pos;
        $len = strlen($text);
        while ($pos < $len && $text[$pos] !== ',' && $text[$pos] !== '}') {
            $pos++;
        }
        $token = substr($text, $start, $pos - $start);
        return $token === 'NULL' ? null : $token;
    }
}
