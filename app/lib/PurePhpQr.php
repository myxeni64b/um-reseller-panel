<?php
class PurePhpQr
{
    const EC_L = 1;
    const EC_M = 0;
    const EC_Q = 3;
    const EC_H = 2;

    protected static $rsBlockTable = [
        array(1, 26, 19),
        array(1, 26, 16),
        array(1, 26, 13),
        array(1, 26, 9),
        array(1, 44, 34),
        array(1, 44, 28),
        array(1, 44, 22),
        array(1, 44, 16),
        array(1, 70, 55),
        array(1, 70, 44),
        array(2, 35, 17),
        array(2, 35, 13),
        array(1, 100, 80),
        array(2, 50, 32),
        array(2, 50, 24),
        array(4, 25, 9),
        array(1, 134, 108),
        array(2, 67, 43),
        array(2, 33, 15, 2, 34, 16),
        array(2, 33, 11, 2, 34, 12),
        array(2, 86, 68),
        array(4, 43, 27),
        array(4, 43, 19),
        array(4, 43, 15),
        array(2, 98, 78),
        array(4, 49, 31),
        array(2, 32, 14, 4, 33, 15),
        array(4, 39, 13, 1, 40, 14),
        array(2, 121, 97),
        array(2, 60, 38, 2, 61, 39),
        array(4, 40, 18, 2, 41, 19),
        array(4, 40, 14, 2, 41, 15),
        array(2, 146, 116),
        array(3, 58, 36, 2, 59, 37),
        array(4, 36, 16, 4, 37, 17),
        array(4, 36, 12, 4, 37, 13),
        array(2, 86, 68, 2, 87, 69),
        array(4, 69, 43, 1, 70, 44),
        array(6, 43, 19, 2, 44, 20),
        array(6, 43, 15, 2, 44, 16),
        array(4, 101, 81),
        array(1, 80, 50, 4, 81, 51),
        array(4, 50, 22, 4, 51, 23),
        array(3, 36, 12, 8, 37, 13),
        array(2, 116, 92, 2, 117, 93),
        array(6, 58, 36, 2, 59, 37),
        array(4, 46, 20, 6, 47, 21),
        array(7, 42, 14, 4, 43, 15),
        array(4, 133, 107),
        array(8, 59, 37, 1, 60, 38),
        array(8, 44, 20, 4, 45, 21),
        array(12, 33, 11, 4, 34, 12),
        array(3, 145, 115, 1, 146, 116),
        array(4, 64, 40, 5, 65, 41),
        array(11, 36, 16, 5, 37, 17),
        array(11, 36, 12, 5, 37, 13),
        array(5, 109, 87, 1, 110, 88),
        array(5, 65, 41, 5, 66, 42),
        array(5, 54, 24, 7, 55, 25),
        array(11, 36, 12, 7, 37, 13),
        array(5, 122, 98, 1, 123, 99),
        array(7, 73, 45, 3, 74, 46),
        array(15, 43, 19, 2, 44, 20),
        array(3, 45, 15, 13, 46, 16),
        array(1, 135, 107, 5, 136, 108),
        array(10, 74, 46, 1, 75, 47),
        array(1, 50, 22, 15, 51, 23),
        array(2, 42, 14, 17, 43, 15),
        array(5, 150, 120, 1, 151, 121),
        array(9, 69, 43, 4, 70, 44),
        array(17, 50, 22, 1, 51, 23),
        array(2, 42, 14, 19, 43, 15),
        array(3, 141, 113, 4, 142, 114),
        array(3, 70, 44, 11, 71, 45),
        array(17, 47, 21, 4, 48, 22),
        array(9, 39, 13, 16, 40, 14),
        array(3, 135, 107, 5, 136, 108),
        array(3, 67, 41, 13, 68, 42),
        array(15, 54, 24, 5, 55, 25),
        array(15, 43, 15, 10, 44, 16),
        array(4, 144, 116, 4, 145, 117),
        array(17, 68, 42),
        array(17, 50, 22, 6, 51, 23),
        array(19, 46, 16, 6, 47, 17),
        array(2, 139, 111, 7, 140, 112),
        array(17, 74, 46),
        array(7, 54, 24, 16, 55, 25),
        array(34, 37, 13),
        array(4, 151, 121, 5, 152, 122),
        array(4, 75, 47, 14, 76, 48),
        array(11, 54, 24, 14, 55, 25),
        array(16, 45, 15, 14, 46, 16),
        array(6, 147, 117, 4, 148, 118),
        array(6, 73, 45, 14, 74, 46),
        array(11, 54, 24, 16, 55, 25),
        array(30, 46, 16, 2, 47, 17),
        array(8, 132, 106, 4, 133, 107),
        array(8, 75, 47, 13, 76, 48),
        array(7, 54, 24, 22, 55, 25),
        array(22, 45, 15, 13, 46, 16),
        array(10, 142, 114, 2, 143, 115),
        array(19, 74, 46, 4, 75, 47),
        array(28, 50, 22, 6, 51, 23),
        array(33, 46, 16, 4, 47, 17),
        array(8, 152, 122, 4, 153, 123),
        array(22, 73, 45, 3, 74, 46),
        array(8, 53, 23, 26, 54, 24),
        array(12, 45, 15, 28, 46, 16),
        array(3, 147, 117, 10, 148, 118),
        array(3, 73, 45, 23, 74, 46),
        array(4, 54, 24, 31, 55, 25),
        array(11, 45, 15, 31, 46, 16),
        array(7, 146, 116, 7, 147, 117),
        array(21, 73, 45, 7, 74, 46),
        array(1, 53, 23, 37, 54, 24),
        array(19, 45, 15, 26, 46, 16),
        array(5, 145, 115, 10, 146, 116),
        array(19, 75, 47, 10, 76, 48),
        array(15, 54, 24, 25, 55, 25),
        array(23, 45, 15, 25, 46, 16),
        array(13, 145, 115, 3, 146, 116),
        array(2, 74, 46, 29, 75, 47),
        array(42, 54, 24, 1, 55, 25),
        array(23, 45, 15, 28, 46, 16),
        array(17, 145, 115),
        array(10, 74, 46, 23, 75, 47),
        array(10, 54, 24, 35, 55, 25),
        array(19, 45, 15, 35, 46, 16),
        array(17, 145, 115, 1, 146, 116),
        array(14, 74, 46, 21, 75, 47),
        array(29, 54, 24, 19, 55, 25),
        array(11, 45, 15, 46, 46, 16),
        array(13, 145, 115, 6, 146, 116),
        array(14, 74, 46, 23, 75, 47),
        array(44, 54, 24, 7, 55, 25),
        array(59, 46, 16, 1, 47, 17),
        array(12, 151, 121, 7, 152, 122),
        array(12, 75, 47, 26, 76, 48),
        array(39, 54, 24, 14, 55, 25),
        array(22, 45, 15, 41, 46, 16),
        array(6, 151, 121, 14, 152, 122),
        array(6, 75, 47, 34, 76, 48),
        array(46, 54, 24, 10, 55, 25),
        array(2, 45, 15, 64, 46, 16),
        array(17, 152, 122, 4, 153, 123),
        array(29, 74, 46, 14, 75, 47),
        array(49, 54, 24, 10, 55, 25),
        array(24, 45, 15, 46, 46, 16),
        array(4, 152, 122, 18, 153, 123),
        array(13, 74, 46, 32, 75, 47),
        array(48, 54, 24, 14, 55, 25),
        array(42, 45, 15, 32, 46, 16),
        array(20, 147, 117, 4, 148, 118),
        array(40, 75, 47, 7, 76, 48),
        array(43, 54, 24, 22, 55, 25),
        array(10, 45, 15, 67, 46, 16),
        array(19, 148, 118, 6, 149, 119),
        array(18, 75, 47, 31, 76, 48),
        array(34, 54, 24, 34, 55, 25),
        array(20, 45, 15, 61, 46, 16)
    ];
    protected static $patternPositionTable = [
        array(),
        array(6, 18),
        array(6, 22),
        array(6, 26),
        array(6, 30),
        array(6, 34),
        array(6, 22, 38),
        array(6, 24, 42),
        array(6, 26, 46),
        array(6, 28, 50),
        array(6, 30, 54),
        array(6, 32, 58),
        array(6, 34, 62),
        array(6, 26, 46, 66),
        array(6, 26, 48, 70),
        array(6, 26, 50, 74),
        array(6, 30, 54, 78),
        array(6, 30, 56, 82),
        array(6, 30, 58, 86),
        array(6, 34, 62, 90),
        array(6, 28, 50, 72, 94),
        array(6, 26, 50, 74, 98),
        array(6, 30, 54, 78, 102),
        array(6, 28, 54, 80, 106),
        array(6, 32, 58, 84, 110),
        array(6, 30, 58, 86, 114),
        array(6, 34, 62, 90, 118),
        array(6, 26, 50, 74, 98, 122),
        array(6, 30, 54, 78, 102, 126),
        array(6, 26, 52, 78, 104, 130),
        array(6, 30, 56, 82, 108, 134),
        array(6, 34, 60, 86, 112, 138),
        array(6, 30, 58, 86, 114, 142),
        array(6, 34, 62, 90, 118, 146),
        array(6, 30, 54, 78, 102, 126, 150),
        array(6, 24, 50, 76, 102, 128, 154),
        array(6, 28, 54, 80, 106, 132, 158),
        array(6, 32, 58, 84, 110, 136, 162),
        array(6, 26, 54, 82, 110, 138, 166),
        array(6, 30, 58, 86, 114, 142, 170)
    ];
    protected static $expTable = null;
    protected static $logTable = null;
    protected static $generatorPolys = array();

    public static function svg($text, $options = array())
    {
        $text = (string) $text;
        $scale = isset($options['scale']) ? (int) $options['scale'] : 6;
        $border = isset($options['border']) ? (int) $options['border'] : 2;
        $ecc = isset($options['ecc']) ? (int) $options['ecc'] : self::EC_M;
        if ($scale < 1) { $scale = 1; }
        if ($border < 0) { $border = 0; }
        $matrix = self::encodeText($text, $ecc);
        if (!is_array($matrix) || !$matrix) {
            return '';
        }
        return self::matrixToSvg($matrix, $scale, $border);
    }

    public static function encodeText($text, $ecc)
    {
        self::initGalois();
        $data = (string) $text;
        $version = self::chooseVersion($data, $ecc);
        if ($version < 1) {
            return array();
        }
        $codewords = self::createData($version, $ecc, $data);
        return self::buildMatrix($version, $ecc, $codewords);
    }

    protected static function chooseVersion($data, $ecc)
    {
        $len = strlen($data);
        for ($version = 1; $version <= 40; $version++) {
            $ccBits = ($version < 10) ? 8 : 16;
            $bitLimit = self::bitLimit($version, $ecc);
            $needed = 4 + $ccBits + ($len * 8);
            if ($needed <= $bitLimit) {
                return $version;
            }
        }
        return 0;
    }

    protected static function bitLimit($version, $ecc)
    {
        $blocks = self::rsBlocks($version, $ecc);
        $bits = 0;
        $count = count($blocks);
        for ($i = 0; $i < $count; $i++) {
            $bits += $blocks[$i]['data_count'] * 8;
        }
        return $bits;
    }

    protected static function createData($version, $ecc, $data)
    {
        $buffer = array();
        $bitLen = 0;
        self::putBits($buffer, $bitLen, 4, 4);
        $ccBits = ($version < 10) ? 8 : 16;
        self::putBits($buffer, $bitLen, strlen($data), $ccBits);
        $dataLen = strlen($data);
        for ($i = 0; $i < $dataLen; $i++) {
            self::putBits($buffer, $bitLen, ord($data[$i]), 8);
        }

        $bitLimit = self::bitLimit($version, $ecc);
        $terminator = min(4, $bitLimit - $bitLen);
        if ($terminator > 0) {
            self::putBits($buffer, $bitLen, 0, $terminator);
        }
        while (($bitLen % 8) !== 0) {
            self::putBit($buffer, $bitLen, false);
        }
        $padBytes = array(0xEC, 0x11);
        $padIndex = 0;
        while ($bitLen < $bitLimit) {
            self::putBits($buffer, $bitLen, $padBytes[$padIndex % 2], 8);
            $padIndex++;
        }

        return self::createBytes($buffer, $version, $ecc);
    }

    protected static function createBytes($buffer, $version, $ecc)
    {
        $blocks = self::rsBlocks($version, $ecc);
        $offset = 0;
        $maxDc = 0;
        $maxEc = 0;
        $dcData = array();
        $ecData = array();

        $blockCount = count($blocks);
        for ($b = 0; $b < $blockCount; $b++) {
            $dcCount = $blocks[$b]['data_count'];
            $ecCount = $blocks[$b]['total_count'] - $dcCount;
            if ($dcCount > $maxDc) { $maxDc = $dcCount; }
            if ($ecCount > $maxEc) { $maxEc = $ecCount; }

            $currentDc = array();
            for ($i = 0; $i < $dcCount; $i++) {
                $currentDc[] = $buffer[$offset + $i] & 0xFF;
            }
            $offset += $dcCount;

            $gen = self::generatorPoly($ecCount);
            $raw = array_merge($currentDc, array_fill(0, $ecCount, 0));
            $remainder = self::polyMod($raw, $gen);
            $currentEc = $remainder;

            $dcData[] = $currentDc;
            $ecData[] = $currentEc;
        }

        $data = array();
        for ($i = 0; $i < $maxDc; $i++) {
            for ($b = 0; $b < $blockCount; $b++) {
                if ($i < count($dcData[$b])) {
                    $data[] = $dcData[$b][$i];
                }
            }
        }
        for ($i = 0; $i < $maxEc; $i++) {
            for ($b = 0; $b < $blockCount; $b++) {
                if ($i < count($ecData[$b])) {
                    $data[] = $ecData[$b][$i];
                }
            }
        }
        return $data;
    }

    protected static function buildMatrix($version, $ecc, $dataCodewords)
    {
        $bestMask = 0;
        $bestLost = null;

        for ($mask = 0; $mask < 8; $mask++) {
            $matrix = self::makeMatrix($version, $ecc, $dataCodewords, $mask, true);
            $lost = self::lostPoint($matrix);
            if ($bestLost === null || $lost < $bestLost) {
                $bestLost = $lost;
                $bestMask = $mask;
            }
        }

        return self::makeMatrix($version, $ecc, $dataCodewords, $bestMask, false);
    }

    protected static function makeMatrix($version, $ecc, $dataCodewords, $mask, $test)
    {
        $size = $version * 4 + 17;
        $modules = array();
        for ($r = 0; $r < $size; $r++) {
            $modules[$r] = array_fill(0, $size, null);
        }

        self::setupPositionProbePattern($modules, $size, 0, 0);
        self::setupPositionProbePattern($modules, $size, $size - 7, 0);
        self::setupPositionProbePattern($modules, $size, 0, $size - 7);
        self::setupPositionAdjustPattern($modules, $version);
        self::setupTimingPattern($modules, $size);
        self::setupTypeInfo($modules, $size, $ecc, $mask, $test);
        if ($version >= 7) {
            self::setupTypeNumber($modules, $size, $version, $test);
        }
        self::mapData($modules, $size, $dataCodewords, $mask);
        return $modules;
    }

    protected static function setupPositionProbePattern(&$modules, $size, $row, $col)
    {
        for ($r = -1; $r <= 7; $r++) {
            if ($row + $r <= -1 || $row + $r >= $size) {
                continue;
            }
            for ($c = -1; $c <= 7; $c++) {
                if ($col + $c <= -1 || $col + $c >= $size) {
                    continue;
                }
                if (((0 <= $r && $r <= 6) && ($c === 0 || $c === 6)) || ((0 <= $c && $c <= 6) && ($r === 0 || $r === 6)) || ((2 <= $r && $r <= 4) && (2 <= $c && $c <= 4))) {
                    $modules[$row + $r][$col + $c] = true;
                } else {
                    $modules[$row + $r][$col + $c] = false;
                }
            }
        }
    }

    protected static function setupTimingPattern(&$modules, $size)
    {
        for ($r = 8; $r < $size - 8; $r++) {
            if ($modules[$r][6] !== null) {
                continue;
            }
            $modules[$r][6] = ($r % 2) === 0;
        }
        for ($c = 8; $c < $size - 8; $c++) {
            if ($modules[6][$c] !== null) {
                continue;
            }
            $modules[6][$c] = ($c % 2) === 0;
        }
    }

    protected static function setupPositionAdjustPattern(&$modules, $version)
    {
        $pos = self::$patternPositionTable[$version - 1];
        $len = count($pos);
        for ($i = 0; $i < $len; $i++) {
            $row = $pos[$i];
            for ($j = 0; $j < $len; $j++) {
                $col = $pos[$j];
                if ($modules[$row][$col] !== null) {
                    continue;
                }
                for ($r = -2; $r <= 2; $r++) {
                    for ($c = -2; $c <= 2; $c++) {
                        if ($r === -2 || $r === 2 || $c === -2 || $c === 2 || ($r === 0 && $c === 0)) {
                            $modules[$row + $r][$col + $c] = true;
                        } else {
                            $modules[$row + $r][$col + $c] = false;
                        }
                    }
                }
            }
        }
    }

    protected static function setupTypeNumber(&$modules, $size, $version, $test)
    {
        $bits = self::bchTypeNumber($version);
        for ($i = 0; $i < 18; $i++) {
            $mod = (!$test) && (((($bits >> $i) & 1)) === 1);
            $modules[(int) floor($i / 3)][$i % 3 + $size - 11] = $mod;
        }
        for ($i = 0; $i < 18; $i++) {
            $mod = (!$test) && (((($bits >> $i) & 1)) === 1);
            $modules[$i % 3 + $size - 11][(int) floor($i / 3)] = $mod;
        }
    }

    protected static function setupTypeInfo(&$modules, $size, $ecc, $maskPattern, $test)
    {
        $data = ($ecc << 3) | $maskPattern;
        $bits = self::bchTypeInfo($data);
        for ($i = 0; $i < 15; $i++) {
            $mod = (!$test) && (((($bits >> $i) & 1)) === 1);
            if ($i < 6) {
                $modules[$i][8] = $mod;
            } elseif ($i < 8) {
                $modules[$i + 1][8] = $mod;
            } else {
                $modules[$size - 15 + $i][8] = $mod;
            }
        }
        for ($i = 0; $i < 15; $i++) {
            $mod = (!$test) && (((($bits >> $i) & 1)) === 1);
            if ($i < 8) {
                $modules[8][$size - $i - 1] = $mod;
            } elseif ($i < 9) {
                $modules[8][15 - $i] = $mod;
            } else {
                $modules[8][14 - $i] = $mod;
            }
        }
        $modules[$size - 8][8] = !$test;
    }

    protected static function mapData(&$modules, $size, $data, $maskPattern)
    {
        $inc = -1;
        $row = $size - 1;
        $bitIndex = 7;
        $byteIndex = 0;
        $dataLen = count($data);

        for ($colBase = $size - 1; $colBase > 0; $colBase -= 2) {
            $col = $colBase;
            if ($col <= 6) {
                $col--;
            }
            while (true) {
                for ($cOff = 0; $cOff < 2; $cOff++) {
                    $c = $col - $cOff;
                    if ($modules[$row][$c] === null) {
                        $dark = false;
                        if ($byteIndex < $dataLen) {
                            $dark = ((($data[$byteIndex] >> $bitIndex) & 1) === 1);
                        }
                        if (self::mask($maskPattern, $row, $c)) {
                            $dark = !$dark;
                        }
                        $modules[$row][$c] = $dark;
                        $bitIndex--;
                        if ($bitIndex === -1) {
                            $byteIndex++;
                            $bitIndex = 7;
                        }
                    }
                }
                $row += $inc;
                if ($row < 0 || $row >= $size) {
                    $row -= $inc;
                    $inc = -$inc;
                    break;
                }
            }
        }
    }

    protected static function mask($pattern, $i, $j)
    {
        switch ((int) $pattern) {
            case 0: return (($i + $j) % 2) === 0;
            case 1: return ($i % 2) === 0;
            case 2: return ($j % 3) === 0;
            case 3: return (($i + $j) % 3) === 0;
            case 4: return (((int) floor($i / 2) + (int) floor($j / 3)) % 2) === 0;
            case 5: return (((($i * $j) % 2) + (($i * $j) % 3))) === 0;
            case 6: return (((($i * $j) % 2) + (($i * $j) % 3)) % 2) === 0;
            case 7: return (((($i * $j) % 3) + (($i + $j) % 2)) % 2) === 0;
        }
        return false;
    }

    protected static function lostPoint($modules)
    {
        $count = count($modules);
        $lost = 0;
        $lost += self::lostPointLevel1($modules, $count);
        $lost += self::lostPointLevel2($modules, $count);
        $lost += self::lostPointLevel3($modules, $count);
        $lost += self::lostPointLevel4($modules, $count);
        return $lost;
    }

    protected static function lostPointLevel1($modules, $count)
    {
        $lost = 0;
        $container = array_fill(0, $count + 1, 0);

        for ($row = 0; $row < $count; $row++) {
            $previous = $modules[$row][0];
            $length = 0;
            for ($col = 0; $col < $count; $col++) {
                if ($modules[$row][$col] === $previous) {
                    $length++;
                } else {
                    if ($length >= 5) { $container[$length]++; }
                    $length = 1;
                    $previous = $modules[$row][$col];
                }
            }
            if ($length >= 5) { $container[$length]++; }
        }

        for ($col = 0; $col < $count; $col++) {
            $previous = $modules[0][$col];
            $length = 0;
            for ($row = 0; $row < $count; $row++) {
                if ($modules[$row][$col] === $previous) {
                    $length++;
                } else {
                    if ($length >= 5) { $container[$length]++; }
                    $length = 1;
                    $previous = $modules[$row][$col];
                }
            }
            if ($length >= 5) { $container[$length]++; }
        }

        for ($i = 5; $i <= $count; $i++) {
            $lost += $container[$i] * ($i - 2);
        }
        return $lost;
    }

    protected static function lostPointLevel2($modules, $count)
    {
        $lost = 0;
        for ($row = 0; $row < $count - 1; $row++) {
            for ($col = 0; $col < $count - 1; $col++) {
                $v = $modules[$row][$col];
                if ($v === $modules[$row][$col + 1] && $v === $modules[$row + 1][$col] && $v === $modules[$row + 1][$col + 1]) {
                    $lost += 3;
                }
            }
        }
        return $lost;
    }

    protected static function lostPointLevel3($modules, $count)
    {
        $lost = 0;
        for ($row = 0; $row < $count; $row++) {
            for ($col = 0; $col < $count - 10; $col++) {
                if (!$modules[$row][$col + 1] &&
                    $modules[$row][$col + 4] &&
                    !$modules[$row][$col + 5] &&
                    $modules[$row][$col + 6] &&
                    !$modules[$row][$col + 9] &&
                    (( $modules[$row][$col] && $modules[$row][$col + 2] && $modules[$row][$col + 3] && !$modules[$row][$col + 7] && !$modules[$row][$col + 8] && !$modules[$row][$col + 10]) ||
                     (!$modules[$row][$col] && !$modules[$row][$col + 2] && !$modules[$row][$col + 3] &&  $modules[$row][$col + 7] &&  $modules[$row][$col + 8] &&  $modules[$row][$col + 10]))) {
                    $lost += 40;
                }
            }
        }
        for ($col = 0; $col < $count; $col++) {
            for ($row = 0; $row < $count - 10; $row++) {
                if (!$modules[$row + 1][$col] &&
                    $modules[$row + 4][$col] &&
                    !$modules[$row + 5][$col] &&
                    $modules[$row + 6][$col] &&
                    !$modules[$row + 9][$col] &&
                    (( $modules[$row][$col] && $modules[$row + 2][$col] && $modules[$row + 3][$col] && !$modules[$row + 7][$col] && !$modules[$row + 8][$col] && !$modules[$row + 10][$col]) ||
                     (!$modules[$row][$col] && !$modules[$row + 2][$col] && !$modules[$row + 3][$col] &&  $modules[$row + 7][$col] &&  $modules[$row + 8][$col] &&  $modules[$row + 10][$col]))) {
                    $lost += 40;
                }
            }
        }
        return $lost;
    }

    protected static function lostPointLevel4($modules, $count)
    {
        $dark = 0;
        for ($r = 0; $r < $count; $r++) {
            for ($c = 0; $c < $count; $c++) {
                if ($modules[$r][$c]) { $dark++; }
            }
        }
        $percent = $dark / ($count * $count);
        $rating = (int) floor(abs($percent * 100 - 50) / 5);
        return $rating * 10;
    }

    protected static function bchTypeInfo($data)
    {
        $G15 = (1 << 10) | (1 << 8) | (1 << 5) | (1 << 4) | (1 << 2) | (1 << 1) | (1 << 0);
        $G15_MASK = (1 << 14) | (1 << 12) | (1 << 10) | (1 << 4) | (1 << 1);
        $d = $data << 10;
        while (self::bchDigit($d) - self::bchDigit($G15) >= 0) {
            $d ^= $G15 << (self::bchDigit($d) - self::bchDigit($G15));
        }
        return (($data << 10) | $d) ^ $G15_MASK;
    }

    protected static function bchTypeNumber($data)
    {
        $G18 = (1 << 12) | (1 << 11) | (1 << 10) | (1 << 9) | (1 << 8) | (1 << 5) | (1 << 2) | (1 << 0);
        $d = $data << 12;
        while (self::bchDigit($d) - self::bchDigit($G18) >= 0) {
            $d ^= $G18 << (self::bchDigit($d) - self::bchDigit($G18));
        }
        return ($data << 12) | $d;
    }

    protected static function bchDigit($data)
    {
        $digit = 0;
        while ($data !== 0) {
            $digit++;
            $data >>= 1;
        }
        return $digit;
    }

    protected static function rsBlocks($version, $ecc)
    {
        $offsetMap = array(
            self::EC_L => 0,
            self::EC_M => 1,
            self::EC_Q => 2,
            self::EC_H => 3,
        );
        $offset = isset($offsetMap[$ecc]) ? $offsetMap[$ecc] : 1;
        $row = self::$rsBlockTable[(($version - 1) * 4) + $offset];
        $blocks = array();
        $len = count($row);
        for ($i = 0; $i < $len; $i += 3) {
            $count = $row[$i];
            $total = $row[$i + 1];
            $data = $row[$i + 2];
            for ($j = 0; $j < $count; $j++) {
                $blocks[] = array('total_count' => $total, 'data_count' => $data);
            }
        }
        return $blocks;
    }

    protected static function generatorPoly($degree)
    {
        if (isset(self::$generatorPolys[$degree])) {
            return self::$generatorPolys[$degree];
        }
        $poly = array(1);
        for ($i = 0; $i < $degree; $i++) {
            $poly = self::polyMultiply($poly, array(1, self::gexp($i)));
        }
        self::$generatorPolys[$degree] = $poly;
        return $poly;
    }

    protected static function polyMultiply($a, $b)
    {
        $res = array_fill(0, count($a) + count($b) - 1, 0);
        $alen = count($a);
        $blen = count($b);
        for ($i = 0; $i < $alen; $i++) {
            for ($j = 0; $j < $blen; $j++) {
                if ($a[$i] === 0 || $b[$j] === 0) {
                    continue;
                }
                $res[$i + $j] ^= self::gexp(self::glog($a[$i]) + self::glog($b[$j]));
            }
        }
        return $res;
    }

    protected static function polyMod($data, $divisor)
    {
        $result = $data;
        $divLen = count($divisor);
        $dataLen = count($data);
        for ($i = 0; $i <= $dataLen - $divLen; $i++) {
            $coef = $result[$i];
            if ($coef === 0) {
                continue;
            }
            $ratio = self::glog($coef) - self::glog($divisor[0]);
            for ($j = 0; $j < $divLen; $j++) {
                if ($divisor[$j] !== 0) {
                    $result[$i + $j] ^= self::gexp(self::glog($divisor[$j]) + $ratio);
                }
            }
        }
        return array_slice($result, $dataLen - ($divLen - 1));
    }

    protected static function initGalois()
    {
        if (self::$expTable !== null && self::$logTable !== null) {
            return;
        }
        self::$expTable = array_fill(0, 256, 0);
        self::$logTable = array_fill(0, 256, 0);
        for ($i = 0; $i < 8; $i++) {
            self::$expTable[$i] = 1 << $i;
        }
        for ($i = 8; $i < 256; $i++) {
            self::$expTable[$i] = self::$expTable[$i - 4] ^ self::$expTable[$i - 5] ^ self::$expTable[$i - 6] ^ self::$expTable[$i - 8];
        }
        for ($i = 0; $i < 255; $i++) {
            self::$logTable[self::$expTable[$i]] = $i;
        }
    }

    protected static function glog($n)
    {
        if ($n < 1) {
            throw new Exception('glog(' . $n . ')');
        }
        return self::$logTable[$n];
    }

    protected static function gexp($n)
    {
        while ($n < 0) { $n += 255; }
        while ($n >= 256) { $n -= 255; }
        return self::$expTable[$n];
    }

    protected static function putBits(&$buffer, &$bitLen, $num, $length)
    {
        for ($i = 0; $i < $length; $i++) {
            self::putBit($buffer, $bitLen, ((($num >> ($length - $i - 1)) & 1) === 1));
        }
    }

    protected static function putBit(&$buffer, &$bitLen, $bit)
    {
        $bufIndex = (int) floor($bitLen / 8);
        if (!isset($buffer[$bufIndex])) {
            $buffer[$bufIndex] = 0;
        }
        if ($bit) {
            $buffer[$bufIndex] |= (0x80 >> ($bitLen % 8));
        }
        $bitLen++;
    }

    protected static function matrixToSvg($matrix, $scale, $border)
    {
        $count = count($matrix);
        $view = $count + ($border * 2);
        $size = $view * $scale;
        $parts = array();
        $parts[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $parts[] = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 ' . $view . ' ' . $view . '" shape-rendering="crispEdges">';
        $parts[] = '<rect width="100%" height="100%" fill="#ffffff"/>';
        $path = '';
        for ($r = 0; $r < $count; $r++) {
            for ($c = 0; $c < $count; $c++) {
                if (!empty($matrix[$r][$c])) {
                    $x = $c + $border;
                    $y = $r + $border;
                    $path .= 'M' . $x . ' ' . $y . 'h1v1h-1z';
                }
            }
        }
        $parts[] = '<path d="' . $path . '" fill="#111827"/>';
        $parts[] = '</svg>';
        return implode('', $parts);
    }
}
