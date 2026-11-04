<?php

namespace App\Support;

/**
 * Rewrites a PDF 1.5+ file that keeps its cross-reference table in a compressed stream (and its
 * objects in object streams) as a plain PDF with a classic xref table — the only kind the free
 * FPDI parser bundled with mpdf can open ("This PDF document probably uses a compression
 * technique which is not supported…"). Many scanner / Office / Acrobat exports are like that.
 *
 * Objects are found by scanning the file (the way PDF repair tools do), so the compressed xref
 * itself never needs decoding; a later definition of an object (incremental update) wins.
 * Page content is copied byte for byte. Encrypted files are not handled (null).
 */
class PdfXrefRewriter
{
    /** @return ?string the rewritten PDF, or null when it can't be done. */
    public static function rewrite(string $pdf): ?string
    {
        if (! str_starts_with(ltrim($pdf), '%PDF-') || preg_match('#/Encrypt\s+\d+\s+\d+\s+R#', $pdf)) {
            return null;
        }
        if (! preg_match_all('#/Root\s+(\d+)\s+(\d+)\s+R#', $pdf, $roots, PREG_SET_ORDER)) {
            return null;
        }
        $root = end($roots);
        $info = preg_match_all('#/Info\s+(\d+)\s+(\d+)\s+R#', $pdf, $infos, PREG_SET_ORDER) ? end($infos) : null;

        /** @var array<int, array{0: int, 1: string}> $objects number => [generation, body between "obj" and "endobj"] */
        $objects = [];
        $offset = 0;
        $length = strlen($pdf);
        while ($offset < $length && preg_match('#(?<![0-9])(\d+)\s+(\d+)\s+obj\b#', $pdf, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $bodyStart = $m[0][1] + strlen($m[0][0]);
            $end = self::objectEnd($pdf, $bodyStart);
            if ($end === null) {
                break;
            }
            $body = substr($pdf, $bodyStart, $end - $bodyStart);
            $offset = $end + strlen('endobj');

            $dictionary = self::dictionaryOf($body);
            if (preg_match('#/Type\s*/XRef\b#', $dictionary)) {
                continue; // the compressed xref is replaced by a plain table below
            }
            if (preg_match('#/Type\s*/ObjStm\b#', $dictionary)) {
                foreach (self::objectStreamMembers($body, $dictionary) as $number => $memberBody) {
                    $objects[$number] = [0, $memberBody];
                }

                continue;
            }
            $objects[(int) $m[1][0]] = [(int) $m[2][0], $body];
        }
        if (! isset($objects[(int) $root[1]])) {
            return null;
        }

        $version = preg_match('#^%PDF-(\d\.\d)#', ltrim($pdf), $v) ? $v[1] : '1.4';
        $out = "%PDF-{$version}\n%\xE2\xE3\xCF\xD3\n";
        $positions = [];
        ksort($objects);
        foreach ($objects as $number => [$generation, $body]) {
            $positions[$number] = [strlen($out), $generation];
            $out .= "{$number} {$generation} obj".$body."endobj\n";
        }

        $size = max(array_keys($objects)) + 1;
        $xrefAt = strlen($out);
        $out .= "xref\n0 {$size}\n0000000000 65535 f \n";
        for ($number = 1; $number < $size; $number++) {
            $out .= isset($positions[$number])
                ? sprintf("%010d %05d n \n", $positions[$number][0], $positions[$number][1])
                : "0000000000 65535 f \n";
        }
        $out .= "trailer\n<< /Size {$size} /Root {$root[1]} {$root[2]} R"
            .($info && isset($objects[(int) $info[1]]) ? " /Info {$info[1]} {$info[2]} R" : '')
            ." >>\nstartxref\n{$xrefAt}\n%%EOF\n";

        return $out;
    }

    /** Position of the "endobj" closing an object body — a stream's binary data is skipped whole. */
    private static function objectEnd(string $pdf, int $from): ?int
    {
        $endobj = strpos($pdf, 'endobj', $from);
        $stream = strpos($pdf, 'stream', $from);
        if ($stream !== false && ($endobj === false || $stream < $endobj)) {
            $endstream = strpos($pdf, 'endstream', $stream + 6);
            if ($endstream === false) {
                return null;
            }
            $endobj = strpos($pdf, 'endobj', $endstream + 9);
        }

        return $endobj === false ? null : $endobj;
    }

    private static function dictionaryOf(string $body): string
    {
        $stream = strpos($body, 'stream');

        return $stream === false ? $body : substr($body, 0, $stream);
    }

    /** The objects packed in one object stream (/Type /ObjStm): number => body. */
    private static function objectStreamMembers(string $body, string $dictionary): array
    {
        if (! preg_match('#/N\s+(\d+)#', $dictionary, $n) || ! preg_match('#/First\s+(\d+)#', $dictionary, $first)) {
            return [];
        }
        if (preg_match('#/DecodeParms#', $dictionary) || (preg_match('#/Filter\s*\[?\s*/(\w+)#', $dictionary, $filter) && $filter[1] !== 'FlateDecode')) {
            return []; // predictors / other filters: not seen on object streams in practice
        }
        $data = self::streamData($body);
        if (isset($filter[1])) {
            $data = @gzuncompress($data);
            if ($data === false) {
                return [];
            }
        }

        $pairs = preg_split('#\s+#', trim(substr($data, 0, (int) $first[1])));
        $members = [];
        $count = min((int) $n[1], intdiv(count($pairs), 2));
        for ($i = 0; $i < $count; $i++) {
            $start = (int) $first[1] + (int) $pairs[$i * 2 + 1];
            $end = $i + 1 < $count ? (int) $first[1] + (int) $pairs[$i * 2 + 3] : strlen($data);
            $members[(int) $pairs[$i * 2]] = ' '.trim(substr($data, $start, $end - $start))."\n";
        }

        return $members;
    }

    private static function streamData(string $body): string
    {
        $start = strpos($body, 'stream') + 6;
        if (substr($body, $start, 2) === "\r\n") {
            $start += 2;
        } elseif (in_array($body[$start] ?? '', ["\n", "\r"], true)) {
            $start++;
        }

        return substr($body, $start, strrpos($body, 'endstream') - $start);
    }
}
