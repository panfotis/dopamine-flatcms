<?php
/**
 * A JPEG carrying the two EXIF tags a phone upload always brings with it:
 * Orientation=6 ("rotate 90° clockwise to display") and a GPS position.
 *
 * Built rather than committed. A binary fixture is a fixture nobody can read,
 * and this one has to be *readable* — every offset below is the reason the
 * test proves anything, and a checked-in .jpg would hide all of them.
 *
 * GD writes no EXIF at all, so the APP1 segment is assembled by hand and
 * spliced in directly after the SOI marker, which is where a camera puts it.
 */

declare(strict_types=1);

return static function (int $width, int $height): string {
    // Offsets in a TIFF header are counted from the start of the header, so
    // every block below has to know where the previous one ended:
    //
    //   0   header          "II", 42, offset of IFD0
    //   8   IFD0            2 entries -> 2 + 2*12 + 4 = 30 bytes
    //   38  GPS IFD         2 entries -> 30 bytes
    //   68  GPS rationals   3 * 8 bytes
    $gpsIfd = 38;
    $gpsData = 68;

    $short = static fn (int $tag, int $value): string
        => pack('v', $tag) . pack('v', 3) . pack('V', 1) . pack('v', $value) . pack('v', 0);
    $long = static fn (int $tag, int $value): string
        => pack('v', $tag) . pack('v', 4) . pack('V', 1) . pack('V', $value);

    $tiff = 'II' . pack('v', 42) . pack('V', 8)

        // IFD0
        . pack('v', 2)
        . $short(0x0112, 6)              // Orientation: rotate 90° CW
        . $long(0x8825, $gpsIfd)         // GPSInfo IFD pointer
        . pack('V', 0)                   // no IFD1

        // GPS IFD
        . pack('v', 2)
        . pack('v', 0x0001) . pack('v', 2) . pack('V', 2) . "N\0\0\0"   // GPSLatitudeRef
        . pack('v', 0x0002) . pack('v', 5) . pack('V', 3) . pack('V', $gpsData)
        . pack('V', 0)

        // 40° 38' 0" N — Thessaloniki, as three rationals
        . pack('V', 40) . pack('V', 1)
        . pack('V', 38) . pack('V', 1)
        . pack('V', 0) . pack('V', 1);

    $app1 = "\xFF\xE1" . pack('n', strlen($tiff) + 8) . "Exif\0\0" . $tiff;

    $im = imagecreatetruecolor($width, $height);
    imagefilledrectangle($im, 0, 0, $width - 1, $height - 1, imagecolorallocate($im, 30, 90, 200));
    ob_start();
    imagejpeg($im, null, 92);
    $jpeg = (string) ob_get_clean();
    imagedestroy($im);

    // Straight after SOI, where a camera writes it.
    return substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2);
};
