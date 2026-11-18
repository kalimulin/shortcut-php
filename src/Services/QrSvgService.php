<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Самодостаточный генератор QR-кодов в формате SVG (ISO/IEC 18004, Byte mode, ECC Level M, версии 1–10).
 * Работает на чистом PHP без расширений GD / Imagick и без внешних библиотек.
 */
final class QrSvgService
{
    // Параметры для ECC Level M (версии 1..10): [dataCodewords, ecCodewordsPerBlock, numBlocks]
    private const VERSION_TABLE_M = [
        1  => [16,  10, 1],
        2  => [28,  16, 1],
        3  => [44,  26, 1],
        4  => [64,  18, 2],
        5  => [86,  24, 2],
        6  => [108, 16, 4],
        7  => [124, 18, 4],
        8  => [154, 22, 4],
        9  => [182, 22, 5],
        10 => [216, 26, 5],
    ];

    // Таблица координат центров выравнивающих шаблонов (alignment patterns) для версий 2..10
    private const ALIGNMENT_CENTERS = [
        1  => [],
        2  => [6, 18],
        3  => [6, 22],
        4  => [6, 26],
        5  => [6, 30],
        6  => [6, 34],
        7  => [6, 22, 38],
        8  => [6, 24, 42],
        9  => [6, 26, 46],
        10 => [6, 28, 50],
    ];

    // 18-битная информация о версии (версии 7..10)
    private const VERSION_INFO_BITS = [
        7  => 0x07C94,
        8  => 0x085BC,
        9  => 0x09A99,
        10 => 0x0A4D3,
    ];

    // 15-битные строки формата для ECC Level M (bits 00) и масок 0..7 (уже с XOR 0x5412)
    private const FORMAT_BITS_M = [
        0 => 0x5412,
        1 => 0x5125,
        2 => 0x5E7C,
        3 => 0x5B4B,
        4 => 0x45F9,
        5 => 0x40CE,
        6 => 0x4F97,
        7 => 0x4AA0,
    ];

    public function generateSvg(string $text, int $quietZone = 4): string
    {
        $matrix = $this->encode($text);
        $n = count($matrix);
        $total = $n + $quietZone * 2;

        $paths = [];
        for ($y = 0; $y < $n; $y++) {
            $x = 0;
            while ($x < $n) {
                if (!$matrix[$y][$x]) {
                    $x++;
                    continue;
                }
                $start = $x;
                while ($x < $n && $matrix[$y][$x]) {
                    $x++;
                }
                $w = $x - $start;
                $px = $start + $quietZone;
                $py = $y + $quietZone;
                $paths[] = "M{$px},{$py}h{$w}v1h-{$w}z";
            }
        }

        $d = implode('', $paths);
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 {$total} {$total}\" shape-rendering=\"crispEdges\">"
            . '<rect width="100%" height="100%" fill="#ffffff"/>'
            . "<path fill=\"#000000\" d=\"{$d}\"/>"
            . '</svg>';
    }

    /** @return list<list<bool>> */
    public function encode(string $text): array
    {
        $bytes = array_values(unpack('C*', $text) ?: []);
        $len = count($bytes);

        $version = $this->selectVersion($len);
        [$totalDataCw, $ecPerBlock, $numBlocks] = self::VERSION_TABLE_M[$version];
        $size = $version * 4 + 17;

        $dataCodewords = $this->buildDataCodewords($bytes, $version, $totalDataCw);
        $finalCodewords = $this->interleaveBlocks($dataCodewords, $ecPerBlock, $numBlocks);

        // Инициализация матрицы и карты функциональных модулей
        $modules = array_fill(0, $size, array_fill(0, $size, false));
        $isFunction = array_fill(0, $size, array_fill(0, $size, false));

        $this->drawFunctionPatterns($modules, $isFunction, $version, $size);
        $this->drawCodewords($modules, $isFunction, $finalCodewords, $size);

        // Выбор лучшей маски (0..7) по штрафу
        $bestMask = 0;
        $bestPenalty = PHP_INT_MAX;
        $bestMatrix = $modules;

        for ($mask = 0; $mask < 8; $mask++) {
            $candidate = $this->applyMask($modules, $isFunction, $mask, $size);
            $this->drawFormatBits($candidate, $mask, $size);
            $penalty = $this->penaltyScore($candidate, $size);
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $bestMask = $mask;
                $bestMatrix = $candidate;
            }
        }

        unset($bestMask);
        return $bestMatrix;
    }

    private function selectVersion(int $byteLength): int
    {
        foreach (self::VERSION_TABLE_M as $version => [$dataCw]) {
            $countBits = $version < 10 ? 8 : 16;
            $capacityBits = $dataCw * 8;
            $requiredBits = 4 + $countBits + $byteLength * 8;
            if ($requiredBits <= $capacityBits) {
                return $version;
            }
        }
        throw new RuntimeException('Строка слишком длинная для QR-кода (максимум 213 байт)');
    }

    /**
     * @param list<int> $bytes
     * @return list<int>
     */
    private function buildDataCodewords(array $bytes, int $version, int $totalDataCw): array
    {
        $bits = [];
        $appendBits = static function (int $val, int $len) use (&$bits): void {
            for ($i = $len - 1; $i >= 0; $i--) {
                $bits[] = ($val >> $i) & 1;
            }
        };

        // Byte mode indicator (0100) + character count
        $appendBits(0b0100, 4);
        $appendBits(count($bytes), $version < 10 ? 8 : 16);
        foreach ($bytes as $b) {
            $appendBits($b, 8);
        }

        $capacityBits = $totalDataCw * 8;
        $terminator = min(4, $capacityBits - count($bits));
        $appendBits(0, $terminator);

        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }

        $codewords = [];
        $bitCount = count($bits);
        for ($i = 0; $i < $bitCount; $i += 8) {
            $byte = 0;
            for ($j = 0; $j < 8; $j++) {
                $byte = ($byte << 1) | $bits[$i + $j];
            }
            $codewords[] = $byte;
        }

        $padBytes = [0xEC, 0x11];
        $padIdx = 0;
        while (count($codewords) < $totalDataCw) {
            $codewords[] = $padBytes[$padIdx % 2];
            $padIdx++;
        }

        return $codewords;
    }

    /**
     * @param list<int> $data
     * @return list<int>
     */
    private function interleaveBlocks(array $data, int $ecPerBlock, int $numBlocks): array
    {
        $totalData = count($data);
        $shortBlockLen = intdiv($totalData, $numBlocks);
        $numLongBlocks = $totalData % $numBlocks;
        $numShortBlocks = $numBlocks - $numLongBlocks;

        $gen = $this->rsGeneratorPoly($ecPerBlock);
        $dataBlocks = [];
        $ecBlocks = [];

        $offset = 0;
        for ($b = 0; $b < $numBlocks; $b++) {
            $len = $shortBlockLen + ($b >= $numShortBlocks ? 1 : 0);
            $block = array_slice($data, $offset, $len);
            $offset += $len;
            $dataBlocks[] = $block;
            $ecBlocks[] = $this->rsRemainder($block, $gen);
        }

        $result = [];
        $maxDataLen = $shortBlockLen + ($numLongBlocks > 0 ? 1 : 0);
        for ($i = 0; $i < $maxDataLen; $i++) {
            for ($b = 0; $b < $numBlocks; $b++) {
                if ($i < count($dataBlocks[$b])) {
                    $result[] = $dataBlocks[$b][$i];
                }
            }
        }
        for ($i = 0; $i < $ecPerBlock; $i++) {
            for ($b = 0; $b < $numBlocks; $b++) {
                $result[] = $ecBlocks[$b][$i];
            }
        }

        return $result;
    }

    /** @return list<int> */
    private function rsGeneratorPoly(int $degree): array
    {
        $poly = [1];
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            $next = array_fill(0, count($poly) + 1, 0);
            foreach ($poly as $j => $coef) {
                $next[$j] ^= $coef;
                $next[$j + 1] ^= $this->gfMul($coef, $root);
            }
            $poly = $next;
            $root = $this->gfMul($root, 2);
        }
        return array_slice($poly, 1);
    }

    /**
     * @param list<int> $data
     * @param list<int> $gen
     * @return list<int>
     */
    private function rsRemainder(array $data, array $gen): array
    {
        $rem = array_fill(0, count($gen), 0);
        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($rem);
            $rem[] = 0;
            foreach ($gen as $j => $coef) {
                $rem[$j] ^= $this->gfMul($coef, $factor);
            }
        }
        return $rem;
    }

    private function gfMul(int $a, int $b): int
    {
        $res = 0;
        for ($i = 0; $i < 8; $i++) {
            if (($b & 1) !== 0) {
                $res ^= $a;
            }
            $hi = ($a & 0x80) !== 0;
            $a = ($a << 1) & 0xFF;
            if ($hi) {
                $a ^= 0x1D;
            }
            $b >>= 1;
        }
        return $res;
    }

    /**
     * @param list<list<bool>> $modules
     * @param list<list<bool>> $isFunction
     */
    private function drawFunctionPatterns(array &$modules, array &$isFunction, int $version, int $size): void
    {
        $set = static function (int $x, int $y, bool $dark) use (&$modules, &$isFunction, $size): void {
            if ($x >= 0 && $x < $size && $y >= 0 && $y < $size) {
                $modules[$y][$x] = $dark;
                $isFunction[$y][$x] = true;
            }
        };

        // Timing patterns
        for ($i = 0; $i < $size; $i++) {
            $set(6, $i, $i % 2 === 0);
            $set($i, 6, $i % 2 === 0);
        }

        // 3 Finder patterns + separators
        $finders = [[3, 3], [$size - 4, 3], [3, $size - 4]];
        foreach ($finders as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $dist = max(abs($dx), abs($dy));
                    $set($cx + $dx, $cy + $dy, $dist !== 2 && $dist !== 4);
                }
            }
        }

        // Alignment patterns
        $centers = self::ALIGNMENT_CENTERS[$version] ?? [];
        $count = count($centers);
        for ($i = 0; $i < $count; $i++) {
            for ($j = 0; $j < $count; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $count - 1) || ($i === $count - 1 && $j === 0)) {
                    continue;
                }
                $cx = $centers[$i];
                $cy = $centers[$j];
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $set($cx + $dx, $cy + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }

        // Reserve format info areas + dark module
        for ($i = 0; $i <= 8; $i++) {
            if ($i !== 6) {
                $set(8, $i, false);
                $set($i, 8, false);
            }
        }
        for ($i = $size - 8; $i < $size; $i++) {
            $set(8, $i, false);
            $set($i, 8, false);
        }
        $set(8, $size - 8, true);

        // Version info (версии >= 7)
        if (isset(self::VERSION_INFO_BITS[$version])) {
            $bits = self::VERSION_INFO_BITS[$version];
            for ($i = 0; $i < 18; $i++) {
                $bit = (($bits >> $i) & 1) !== 0;
                $a = $size - 11 + ($i % 3);
                $b = intdiv($i, 3);
                $set($a, $b, $bit);
                $set($b, $a, $bit);
            }
        }
    }

    /**
     * @param list<list<bool>> $modules
     * @param list<list<bool>> $isFunction
     * @param list<int> $codewords
     */
    private function drawCodewords(array &$modules, array $isFunction, array $codewords, int $size): void
    {
        $bitIdx = 0;
        $totalBits = count($codewords) * 8;

        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $size; $vert++) {
                $upward = ((($right + 1) & 2) === 0);
                $y = $upward ? ($size - 1 - $vert) : $vert;
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    if (!$isFunction[$y][$x]) {
                        $dark = false;
                        if ($bitIdx < $totalBits) {
                            $dark = (($codewords[$bitIdx >> 3] >> (7 - ($bitIdx & 7))) & 1) !== 0;
                            $bitIdx++;
                        }
                        $modules[$y][$x] = $dark;
                    }
                }
            }
        }
    }

    /**
     * @param list<list<bool>> $modules
     * @param list<list<bool>> $isFunction
     * @return list<list<bool>>
     */
    private function applyMask(array $modules, array $isFunction, int $mask, int $size): array
    {
        $result = $modules;
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($isFunction[$y][$x]) {
                    continue;
                }
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0,
                    5 => (($x * $y) % 2 + ($x * $y) % 3) === 0,
                    6 => ((($x * $y) % 2 + ($x * $y) % 3) % 2) === 0,
                    default => ((($x + $y) % 2 + ($x * $y) % 3) % 2) === 0,
                };
                if ($invert) {
                    $result[$y][$x] = !$result[$y][$x];
                }
            }
        }
        return $result;
    }

    /** @param list<list<bool>> $modules */
    private function drawFormatBits(array &$modules, int $mask, int $size): void
    {
        $bits = self::FORMAT_BITS_M[$mask];

        for ($i = 0; $i <= 5; $i++) {
            $modules[$i][8] = (($bits >> $i) & 1) !== 0;
        }
        $modules[7][8] = (($bits >> 6) & 1) !== 0;
        $modules[8][8] = (($bits >> 7) & 1) !== 0;
        $modules[8][7] = (($bits >> 8) & 1) !== 0;
        for ($i = 9; $i < 15; $i++) {
            $modules[8][14 - $i] = (($bits >> $i) & 1) !== 0;
        }

        for ($i = 0; $i < 8; $i++) {
            $modules[8][$size - 1 - $i] = (($bits >> $i) & 1) !== 0;
        }
        for ($i = 8; $i < 15; $i++) {
            $modules[$size - 15 + $i][8] = (($bits >> $i) & 1) !== 0;
        }
        $modules[$size - 8][8] = true;
    }

    /** @param list<list<bool>> $modules */
    private function penaltyScore(array $modules, int $size): int
    {
        $score = 0;

        // Rule 1: 5+ одинаковых подряд по строкам и столбцам
        for ($y = 0; $y < $size; $y++) {
            $run = 1;
            for ($x = 1; $x < $size; $x++) {
                if ($modules[$y][$x] === $modules[$y][$x - 1]) {
                    $run++;
                    if ($run === 5) {
                        $score += 3;
                    } elseif ($run > 5) {
                        $score += 1;
                    }
                } else {
                    $run = 1;
                }
            }
        }
        for ($x = 0; $x < $size; $x++) {
            $run = 1;
            for ($y = 1; $y < $size; $y++) {
                if ($modules[$y][$x] === $modules[$y - 1][$x]) {
                    $run++;
                    if ($run === 5) {
                        $score += 3;
                    } elseif ($run > 5) {
                        $score += 1;
                    }
                } else {
                    $run = 1;
                }
            }
        }

        // Rule 2: блоки 2x2 одного цвета
        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                $c = $modules[$y][$x];
                if ($c === $modules[$y][$x + 1] && $c === $modules[$y + 1][$x] && $c === $modules[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }

        // Rule 4: баланс тёмных и светлых модулей
        $dark = 0;
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($modules[$y][$x]) {
                    $dark++;
                }
            }
        }
        $total = $size * $size;
        $k = (int) floor(abs($dark * 20 - $total * 10) / $total);
        $score += $k * 10;

        return $score;
    }
}
