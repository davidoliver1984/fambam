#!/bin/sh

set -eu

formats="$(docker compose exec -T api magick identify -list format)"
for format in JPEG PNG HEIC HEIF WEBP TIFF; do
    if ! printf '%s\n' "${formats}" | grep -Eq "^[[:space:]]*${format}\*?[[:space:]]"; then
        echo "ImageMagick does not report required ${format} support" >&2
        exit 1
    fi
done

docker compose exec -T api sh -eu -c '
media_smoke_dir="$(mktemp -d)"
trap '\''rm -rf "${media_smoke_dir}"'\'' EXIT
for extension in jpg png heic heif webp tif; do
    magick -size 2x2 xc:white "${media_smoke_dir}/sample.${extension}"
    magick \
        -limit memory 256MiB \
        -limit map 512MiB \
        -limit disk 1GiB \
        "${media_smoke_dir}/sample.${extension}[0]" \
        null:
done
'

docker compose exec -T api php artisan tinker --execute='$scanner = app(App\Media\MalwareScanner::class); $clean = tempnam(sys_get_temp_dir(), "fambam-clean-"); file_put_contents($clean, "clean family photograph bytes"); try { $scanner->assertClean($clean); } finally { @unlink($clean); }'

docker compose exec -T api php artisan tinker --execute='$scanner = app(App\Media\MalwareScanner::class); $infected = tempnam(sys_get_temp_dir(), "fambam-eicar-"); file_put_contents($infected, base64_decode("WDVPIVAlQEFQWzRcUFpYNTQoUF4pN0NDKTd9JEVJQ0FSLVNUQU5EQVJELUFOVElWSVJVUy1URVNULUZJTEUhJEgrSCo=", true)); try { $scanner->assertClean($infected); throw new RuntimeException("ClamAV accepted the EICAR test signature."); } catch (App\Media\MediaValidationFailed $failure) { throw_unless($failure->reason === "malware_detected", RuntimeException::class, "ClamAV returned an unexpected failure state."); } finally { @unlink($infected); }'

echo "Media validation smoke checks passed."
