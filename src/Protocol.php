<?php

declare(strict_types=1);

namespace NusaDB;

/**
 * Low-level Nusa Wire Protocol codec (docs/wire-protocol.md, PROTOCOL_VERSION 1.1).
 *
 * A frame is [type:u8][len:u32][payload]; len is the total including the 5-byte header.
 * Big-endian throughout; strings are [len:u32][utf8 bytes], not null-terminated.
 */
final class Protocol
{
    public const MAGIC = 0x4E555341; // "NUSA"
    public const MAJOR = 1;
    // Request minor 1 to receive the typed RowDescription (per-column type tags). A 1.0 server
    // ignores it and answers with the classic untyped form, which the driver still handles.
    public const MINOR = 1;
    public const HEADER_LEN = 5;
    public const MAX_FRAME_LEN = 268435456; // 256 MiB
    public const SCRAM_MECHANISM = 'SCRAM-SHA-256';

    // Frontend type bytes.
    public const T_STARTUP = 0x53;
    public const T_QUERY = 0x51;
    public const T_PARSE = 0x50;
    public const T_BIND = 0x42;
    public const T_DESCRIBE = 0x44;
    public const T_EXECUTE = 0x45;
    public const T_SYNC = 0x59;
    public const T_SASL_INITIAL = 0x70;
    public const T_SASL_RESPONSE = 0x72;
    public const T_TERMINATE = 0x58;
    public const T_COPY_DATA = 0x64; // 'd' — COPY ... FROM STDIN data chunk (§12.1)
    public const T_COPY_DONE = 0x63; // 'c' — end of the client's COPY data stream
    public const T_COPY_FAIL = 0x66; // 'f' — abort the in-progress COPY: [message:Str]

    // Backend type bytes.
    public const B_AUTH = 0x52;
    public const B_BACKEND_KEY = 0x4B;
    public const B_READY = 0x5A;
    public const B_COMMAND_COMPLETE = 0x43;
    public const B_ERROR = 0x45;
    public const B_ROW_DESCRIPTION = 0x54;
    public const B_ROW_DESCRIPTION_TYPED = 0x79; // 'y' — protocol 1.1 (typed columns)
    public const B_DATA_ROW = 0x44;
    public const B_COPY_IN = 0x47;   // 'G' — CopyInResponse (§12.1)
    public const B_COPY_OUT = 0x48;  // 'H' — CopyOutResponse (§12.2)
    public const B_COPY_DATA = 0x64; // 'd' — a COPY data chunk
    public const B_COPY_DONE = 0x63; // 'c' — end of the COPY data stream
    public const B_NOTIFICATION = 0x41; // 'A' — async LISTEN/NOTIFY: [pid:u32][channel:str][payload:str]

    // Column type tags carried by RowDescriptionTyped (protocol 1.1, wire-protocol.md §9.2). Maps
    // the 1-byte tag to a canonical type name; an unknown/0x00 tag is UNKNOWN (treated as text).
    public const TYPE_TAGS = [
        0x00 => 'UNKNOWN', 0x01 => 'BOOL', 0x02 => 'INT', 0x03 => 'FLOAT', 0x04 => 'NUMERIC',
        0x05 => 'TEXT', 0x06 => 'BYTES', 0x07 => 'DATE', 0x08 => 'TIME', 0x09 => 'TIMETZ',
        0x0A => 'TIMESTAMP', 0x0B => 'TIMESTAMPTZ', 0x0C => 'INTERVAL', 0x0D => 'UUID',
        0x0E => 'JSON', 0x0F => 'ARRAY', 0x10 => 'VECTOR',
    ];

    /** Canonical type name for a RowDescriptionTyped type tag (UNKNOWN if unrecognised). */
    public static function typeName(int $tag): string
    {
        return self::TYPE_TAGS[$tag] ?? 'UNKNOWN';
    }

    // Auth sub-codes.
    public const AUTH_OK = 0;
    public const AUTH_SASL = 10;
    public const AUTH_SASL_CONTINUE = 11;
    public const AUTH_SASL_FINAL = 12;

    public static function u16(int $v): string
    {
        return pack('n', $v);
    }

    public static function u32(int $v): string
    {
        return pack('N', $v);
    }

    public static function str(string $s): string
    {
        return self::u32(strlen($s)) . $s;
    }

    /** Encode a Fields list: [count:u16] then per field present byte + optional [len][bytes]. */
    public static function fields(array $values): string
    {
        $out = self::u16(count($values));
        foreach ($values as $v) {
            if ($v === null) {
                $out .= "\x00";
            } else {
                $out .= "\x01" . self::u32(strlen($v)) . $v;
            }
        }
        return $out;
    }

    public static function frame(int $type, string $payload): string
    {
        $total = strlen($payload) + self::HEADER_LEN;
        return chr($type) . self::u32($total) . $payload;
    }

    public static function startup(string $user, string $database): string
    {
        $payload = self::u32(self::MAGIC) . self::u16(self::MAJOR) . self::u16(self::MINOR)
            . self::str($user) . self::str($database);
        return self::frame(self::T_STARTUP, $payload);
    }

    public static function query(string $sql): string
    {
        return self::frame(self::T_QUERY, self::str($sql));
    }

    public static function parse(string $name, string $sql): string
    {
        return self::frame(self::T_PARSE, self::str($name) . self::str($sql) . self::u16(0));
    }

    public static function bind(string $portal, string $statement, array $values): string
    {
        return self::frame(
            self::T_BIND,
            self::str($portal) . self::str($statement) . self::fields($values) . self::u16(0)
        );
    }

    public static function describePortal(string $name): string
    {
        return self::frame(self::T_DESCRIBE, 'P' . self::str($name));
    }

    public static function execute(string $portal, int $maxRows = 0): string
    {
        return self::frame(self::T_EXECUTE, self::str($portal) . self::u32($maxRows));
    }

    public static function sync(): string
    {
        return self::frame(self::T_SYNC, '');
    }

    public static function terminate(): string
    {
        return self::frame(self::T_TERMINATE, '');
    }

    public static function saslInitial(string $mechanism, string $data): string
    {
        return self::frame(self::T_SASL_INITIAL, self::str($mechanism) . self::u32(strlen($data)) . $data);
    }

    public static function saslResponse(string $data): string
    {
        return self::frame(self::T_SASL_RESPONSE, $data);
    }

    public static function copyData(string $data): string
    {
        return self::frame(self::T_COPY_DATA, $data);
    }

    public static function copyDone(): string
    {
        return self::frame(self::T_COPY_DONE, '');
    }

    public static function copyFail(string $message): string
    {
        return self::frame(self::T_COPY_FAIL, self::str($message));
    }
}

/** Reads the protocol's primitive encodings from a payload buffer. */
final class Reader
{
    /** @var string */
    private $buf;
    /** @var int */
    private $pos = 0;

    public function __construct(string $buf)
    {
        $this->buf = $buf;
    }

    public function u8(): int
    {
        $v = ord($this->buf[$this->pos]);
        $this->pos += 1;
        return $v;
    }

    public function u16(): int
    {
        $v = unpack('n', substr($this->buf, $this->pos, 2))[1];
        $this->pos += 2;
        return $v;
    }

    public function u32(): int
    {
        $v = unpack('N', substr($this->buf, $this->pos, 4))[1];
        $this->pos += 4;
        return $v;
    }

    public function str(): string
    {
        $n = $this->u32();
        $s = substr($this->buf, $this->pos, $n);
        $this->pos += $n;
        return $s;
    }

    public function rest(): string
    {
        $s = substr($this->buf, $this->pos);
        $this->pos = strlen($this->buf);
        return $s;
    }

    /** Decode a Fields list; a null entry is SQL NULL. */
    public function fields(): array
    {
        $n = $this->u16();
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            if ($this->u8() === 0) {
                $out[] = null;
            } else {
                $len = $this->u32();
                $out[] = substr($this->buf, $this->pos, $len);
                $this->pos += $len;
            }
        }
        return $out;
    }
}
