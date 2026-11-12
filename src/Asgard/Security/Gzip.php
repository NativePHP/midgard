<?php

namespace Native\Api\Client\Asgard\Security;

/**
 * The gzip content coding, for fragments (protocol §7.4).
 *
 * encode() writes a fixed header (no file name, mtime 0, OS 255) around a
 * raw deflate stream at level 6, so the same zlib gives the same bytes on
 * any platform. decode() inflates in small steps and stops as soon as the
 * output passes the cap, so a small body cannot expand into a huge one.
 */
final class Gzip
{
    /** Bodies smaller than this are not worth compressing. */
    public const MIN_SIZE = 1024;

    /** The most a compressed body may inflate to: 8 MiB. */
    public const MAX_INFLATED = 8 * 1024 * 1024;

    /** Compressed input fed to zlib per step. Deflate expands at most ~1032:1, so one step makes about 1 MiB at most. */
    private const STEP = 1024;

    public static function encode(string $data): string
    {
        $deflated = gzdeflate($data, 6, ZLIB_ENCODING_RAW);

        if ($deflated === false) {
            throw new SecurityException('Compression failed.');
        }

        return "\x1f\x8b\x08\x00\x00\x00\x00\x00\x00\xff".$deflated.pack('V', crc32($data)).pack('V', strlen($data) & 0xFFFFFFFF);
    }

    public static function decode(string $data, int $max = self::MAX_INFLATED): string
    {
        $context = inflate_init(ZLIB_ENCODING_GZIP);

        if ($context === false) {
            throw new SecurityException('Could not start decompression.');
        }

        $out = '';
        $chunks = $data === '' ? [''] : str_split($data, self::STEP);
        $last = count($chunks) - 1;

        foreach ($chunks as $i => $chunk) {
            $inflated = @inflate_add($context, $chunk, $i === $last ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);

            if ($inflated === false) {
                throw new SecurityException('The body is not valid gzip.');
            }

            $out .= $inflated;

            if (strlen($out) > $max) {
                throw new SecurityException("The body inflates past {$max} bytes.");
            }
        }

        if (inflate_get_status($context) !== ZLIB_STREAM_END) {
            throw new SecurityException('The gzip stream is truncated.');
        }

        return $out;
    }
}
