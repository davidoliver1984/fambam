<?php

namespace App\Demo;

final class DemoArchiveImage
{
    /** Create a deterministic, clearly illustrated PNG without private source imagery. */
    public function make(int $scene, int $width, int $height, int $people): string
    {
        $palettes = [
            [[213, 192, 160], [91, 70, 54], [244, 228, 194]],
            [[158, 185, 197], [42, 78, 91], [235, 208, 160]],
            [[199, 168, 130], [105, 118, 78], [238, 214, 181]],
            [[174, 157, 184], [71, 61, 91], [230, 202, 174]],
            [[189, 196, 164], [75, 91, 63], [241, 219, 184]],
            [[187, 153, 142], [94, 59, 55], [234, 205, 171]],
        ];
        [$sky, $ground, $skin] = $palettes[$scene % count($palettes)];
        $people = max(1, min(5, $people));
        $rows = '';

        for ($y = 0; $y < $height; $y++) {
            $row = "\0";
            for ($x = 0; $x < $width; $x++) {
                $rgb = $y < (int) ($height * 0.58) ? $sky : $ground;
                $grain = (($x * 13 + $y * 7 + $scene * 17) % 13) - 6;
                foreach (range(0, $people - 1) as $person) {
                    $cx = (int) (($person + 1) * $width / ($people + 1));
                    $headY = (int) ($height * (0.37 + (($person + $scene) % 3) * 0.025));
                    $radius = max(12, (int) ($width / ($people + 5) * 0.28));
                    if (($x - $cx) ** 2 + ($y - $headY) ** 2 <= $radius ** 2) {
                        $rgb = $skin;
                    } elseif (abs($x - $cx) < $radius * 1.25
                        && $y > $headY + $radius * 0.8
                        && $y < $height * 0.91) {
                        $rgb = $palettes[($scene + $person + 2) % count($palettes)][1];
                    }
                }
                if ($x < 5 || $y < 5 || $x >= $width - 5 || $y >= $height - 5) {
                    $rgb = [238, 229, 207];
                }
                $row .= chr(max(0, min(255, $rgb[0] + $grain)))
                    .chr(max(0, min(255, $rgb[1] + $grain)))
                    .chr(max(0, min(255, $rgb[2] + $grain)));
            }
            $rows .= $row;
        }

        return "\x89PNG\r\n\x1a\n"
            .$this->chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$this->chunk('IDAT', gzcompress($rows, 7))
            .$this->chunk('IEND', '');
    }

    private function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }
}
