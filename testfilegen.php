<?php
/**
 * testfilegen.php
 *
 * Server-side script to create uncompressible test files (1 MiB .. 10 GiB)
 * Automatically detects available methods (openssl or PHP random_bytes fallback)
 * Generates files and creates index.html and index.txt for easy access
 *
 * This script runs directly on a server without parameters and automatically
 * determines the best available method for generating test files.
 */

declare(ticks = 1);

echo "=== Uncompressible Test File Generator ===\n";
echo "Generating test files from 1 MiB to 10 GiB...\n\n";

// Auto-detect best method and settings
$method = detectBestMethod();
$outdir = __DIR__;
$useDirect = false; // Keep it simple for server deployment

// sizes in MiB (1 MiB .. 10 GiB)
$sizes_mb = [1, 10, 100, 500, 1024, 2048, 3072, 4096, 5120, 10240];

/**
 * Auto-detect the best available method for generating files
 */
function detectBestMethod(): string {
    // Check if we're on Windows or Unix-like system
    $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    
    if (!$isWindows) {
        // Check for openssl and dd availability on Unix-like systems
        $hasOpenssl = (bool) shell_exec('command -v openssl 2>/dev/null');
        $hasDd = (bool) shell_exec('command -v dd 2>/dev/null');
        $hasDevZero = file_exists('/dev/zero');
        
        if ($hasOpenssl && $hasDd && $hasDevZero) {
            echo "✓ Detected: OpenSSL + dd method available (fastest)\n";
            return 'openssl';
        }
    }
    
    echo "✓ Using: PHP random_bytes method (slower but compatible)\n";
    return 'random';
}

// sanity: ensure outdir exists
if (!is_dir($outdir)) {
    if (!@mkdir($outdir, 0775, true)) {
        fwrite(STDERR, "Error: cannot create output directory: $outdir\n");
        exit(2);
    }
}

// check disk free space (bytes)
$total_required_bytes = array_reduce($sizes_mb, function($carry, $mb) { return $carry + ($mb * 1024 * 1024); }, 0);
$free_bytes = @disk_free_space($outdir);
if ($free_bytes === false) {
    fwrite(STDERR, "Warning: cannot determine free disk space for $outdir. Proceeding, but be careful.\n");
} else {
    // refuse if required > free * 0.95 to avoid filling the disk completely
    if ($total_required_bytes > $free_bytes * 0.95) {
        fwrite(STDERR, sprintf(
            "Error: not enough free space in %s. Required ~%s, available %s\n",
            $outdir,
            human_readable_bytes($total_required_bytes),
            human_readable_bytes($free_bytes)
        ));
        fwrite(STDERR, "Either free up space or pass --outdir to a location with more free space.\n");
        exit(3);
    }
}

// helper
function human_readable_bytes($n) {
    if ($n >= 1<<30) return round($n / (1<<30), 2) . " GiB";
    if ($n >= 1<<20) return round($n / (1<<20), 2) . " MiB";
    if ($n >= 1<<10) return round($n / (1<<10), 2) . " KiB";
    return $n . " B";
}

// detect GNU dd availability (for status=progress)
$has_gnu_dd = (bool) trim(shell_exec('dd --version 2>/dev/null'));

// main runners
function run_openssl_dd(string $outfile, int $size_mb, bool $has_gnu_dd, bool $useDirect): int {
    // prepare key/iv (hex)
    $key_hex = bin2hex(random_bytes(32)); // 256-bit key
    $iv_hex  = bin2hex(random_bytes(16)); // 128-bit IV
    $dd_opts = [];
    if ($has_gnu_dd) $dd_opts[] = 'status=progress';
    if ($useDirect) $dd_opts[] = 'oflag=direct';
    $dd_opts_str = $dd_opts ? ' ' . implode(' ', $dd_opts) : '';

    // escapeshellarg outfile
    $outfile_esc = escapeshellarg($outfile);
    
    // Use appropriate zero device based on OS
    $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    $zeroDevice = $isWindows ? 'NUL' : '/dev/zero';
    $nullRedirect = $isWindows ? '2>NUL' : '2>/dev/null';
    
    $cmd = sprintf(
        'openssl enc -aes-256-ctr -K %s -iv %s -nosalt -in %s %s | dd of=%s bs=1M count=%d%s',
        $key_hex,
        $iv_hex,
        $zeroDevice,
        $nullRedirect,
        $outfile_esc,
        $size_mb,
        $dd_opts_str
    );

    echo "Running: $cmd\n";
    passthru($cmd, $ret);
    return $ret;
}

function run_php_random_write(string $outfile, int $size_mb): int {
    $bytes_to_write = $size_mb * 1024 * 1024;
    $chunk = 1 * 1024 * 1024; // 1 MiB chunks
    $fh = @fopen($outfile, 'wb');
    if ($fh === false) {
        fwrite(STDERR, "Error: cannot open $outfile for writing\n");
        return 1;
    }

    $written = 0;
    $start = time();
    echo "Writing $size_mb MiB to $outfile using PHP random_bytes (may be slow)...\n";
    while ($written < $bytes_to_write) {
        $toWrite = min($chunk, $bytes_to_write - $written);
        $data = random_bytes($toWrite);
        $w = fwrite($fh, $data);
        if ($w === false) {
            fclose($fh);
            fwrite(STDERR, "Error: write failed for $outfile\n");
            return 2;
        }
        $written += $w;
        // simple progress every 10 MiB
        if (($written % (10 * 1024 * 1024)) === 0) {
            $elapsed = max(1, time() - $start);
            $rate = round($written / (1024*1024) / $elapsed, 2);
            printf("  -> %d MiB written (%.2f MiB/s)\n", (int)($written / (1024*1024)), $rate);
        }
    }
    fflush($fh);
    fclose($fh);
    return 0;
}

// iterate sizes and create files
foreach ($sizes_mb as $size_mb) {
    if ($size_mb < 1024) {
        $fname = "testfile_{$size_mb}MiB.bin";
    } else {
        $gb = intdiv($size_mb, 1024);
        $fname = "testfile_{$gb}GiB.bin";
    }
    $outfile = rtrim($outdir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fname;

    echo "\n=== Creating: $outfile (size: {$size_mb} MiB) ===\n";
    if (file_exists($outfile)) {
        echo "Note: $outfile already exists — it will be overwritten.\n";
        if (!@unlink($outfile)) {
            fwrite(STDERR, "Error: unable to remove existing $outfile\n");
            exit(4);
        }
    }

    if ($method === 'openssl') {
        $rc = run_openssl_dd($outfile, $size_mb, $has_gnu_dd, $useDirect);
        if ($rc !== 0) {
            fwrite(STDERR, "openssl/dd pipeline failed (exit $rc). Falling back to PHP method.\n");
            $rc = run_php_random_write($outfile, $size_mb);
            if ($rc !== 0) {
                fwrite(STDERR, "PHP writer fallback also failed (exit $rc). Aborting.\n");
                exit(8);
            }
        }
    } else if ($method === 'random') {
        $rc = run_php_random_write($outfile, $size_mb);
        if ($rc !== 0) {
            fwrite(STDERR, "PHP writer failed (exit $rc). Aborting.\n");
            exit(8);
        }
    } else {
        fwrite(STDERR, "Unknown method: $method\n");
        exit(9);
    }

    // fsync
    @system('sync');

    // verify size
    clearstatcache(true, $outfile);
    $actual = @filesize($outfile);
    if ($actual === false) {
        fwrite(STDERR, "Warning: cannot stat $outfile\n");
    } else {
        $expected = $size_mb * 1024 * 1024;
        if ($actual !== $expected) {
            fwrite(STDERR, sprintf("Warning: size mismatch for %s: expected %d, got %d\n", $outfile, $expected, $actual));
        } else {
            echo "Done: $outfile [" . human_readable_bytes($actual) . "]\n";
        }
    }
}

echo "\nAll files generated in: $outdir\n";

// Generate index files
generateIndexFiles($outdir, $sizes_mb);

echo "Generated index.html and index.txt files for easy access.\n";
echo "Tip: add these names to .gitignore or keep them outside OneDrive if you don't want them tracked/synced.\n";

exit(0);

/**
 * Generate index.html and index.txt files
 */
function generateIndexFiles(string $outdir, array $sizes_mb): void {
    $files = [];
    $totalSize = 0;
    
    // Collect file information
    foreach ($sizes_mb as $size_mb) {
        if ($size_mb < 1024) {
            $fname = "testfile_{$size_mb}MiB.bin";
            $displayName = "{$size_mb} MiB";
        } else {
            $gb = intdiv($size_mb, 1024);
            $fname = "testfile_{$gb}GiB.bin";
            $displayName = "{$gb} GiB";
        }
        
        $filepath = rtrim($outdir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fname;
        if (file_exists($filepath)) {
            $filesize = filesize($filepath);
            $files[] = [
                'filename' => $fname,
                'displayName' => $displayName,
                'size' => $filesize,
                'sizeFormatted' => human_readable_bytes($filesize)
            ];
            $totalSize += $filesize;
        }
    }
    
    // Generate index.txt
    $txtContent = "";
    foreach ($files as $file) {
        $txtContent .= $file['filename'] . "\n";
    }
    file_put_contents($outdir . DIRECTORY_SEPARATOR . 'index.txt', $txtContent);
    
    // Generate index.html
    $htmlContent = generateHtmlIndex($files, $totalSize);
    file_put_contents($outdir . DIRECTORY_SEPARATOR . 'index.html', $htmlContent);
    
    echo "Created index.html with " . count($files) . " file links\n";
    echo "Created index.txt with " . count($files) . " filenames\n";
}

/**
 * Generate HTML index page
 */
function generateHtmlIndex(array $files, int $totalSize): string {
    $html = '<html>
<head>
<title>Test Files</title>
</head>
<body>
<h1>Test Files</h1>
<p>Total: ' . count($files) . ' files (' . human_readable_bytes($totalSize) . ')</p>
<ul>';
    
    foreach ($files as $file) {
        $html .= '<li><a href="' . htmlspecialchars($file['filename']) . '">' . htmlspecialchars($file['displayName']) . '</a> - ' . htmlspecialchars($file['sizeFormatted']) . '</li>';
    }
    
    $html .= '</ul>
<p><a href="index.txt">index.txt</a></p>
</body>
</html>';
    
    return $html;
}