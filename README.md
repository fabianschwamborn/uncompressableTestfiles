# Uncompressable Test Files Generator

Generates uncompressible test files from 1 MiB to 10 GiB using cryptographically random data.

## Usage

Run from command line:

```bash
php testfilegen.php
```

Or access via web server (not recommended for large files due to timeout issues).

## Generated Files

- `testfile_1MiB.bin` (1 MiB)
- `testfile_10MiB.bin` (10 MiB)
- `testfile_100MiB.bin` (100 MiB)
- `testfile_500MiB.bin` (500 MiB)
- `testfile_1GiB.bin` (1 GiB)
- `testfile_2GiB.bin` (2 GiB)
- `testfile_3GiB.bin` (3 GiB)
- `testfile_4GiB.bin` (4 GiB)
- `testfile_5GiB.bin` (5 GiB)
- `testfile_10GiB.bin` (10 GiB)

The script also generates `index.html` and `index.txt` for easy file access.

## How It Works

Automatically detects the best method:
- **OpenSSL + dd** (Unix/Linux) - Fast, uses AES-256-CTR encryption on /dev/zero
- **PHP random_bytes** (fallback) - Slower but works everywhere

## Requirements

- PHP 7.0+
- Sufficient disk space (~25 GiB total)
- Optional: OpenSSL and dd (for faster generation on Unix-like systems)

## Notes

- Files are truly uncompressible due to cryptographic randomness
- Script checks for existing files and won't regenerate them
- Delete `index.html` and `index.txt` to force regeneration
