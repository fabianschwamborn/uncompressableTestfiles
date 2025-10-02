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

// Check if running via web server - warn about potential issues
$isWebRequest = isset($_SERVER['REQUEST_METHOD']);
if ($isWebRequest) {
    echo "WARNING: Running via web server detected!\n";
    echo "This script generates large files and may timeout or be killed.\n";
    echo "Consider running from command line instead: php " . basename(__FILE__) . "\n\n";
    
    // Increase time limit for web requests (if allowed)
    @set_time_limit(0);
    @ini_set('memory_limit', '512M');
}

// Check if index files already exist - if so, don't run
$outdir = __DIR__;
$indexHtml = $outdir . DIRECTORY_SEPARATOR . 'index.html';
$indexTxt = $outdir . DIRECTORY_SEPARATOR . 'index.txt';

if (file_exists($indexHtml) || file_exists($indexTxt)) {
    echo "Index files already exist (index.html or index.txt found).\n";
    echo "The test files appear to have been generated already.\n";
    echo "Remove index.html and index.txt if you want to regenerate the files.\n";
    exit(0);
}

echo "Generating test files from 1 MiB to 10 GiB...\n\n";

// Auto-detect best method and settings
$method = detectBestMethod();
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
    echo "Attempting OpenSSL method for $size_mb MiB...\n";
    
    // Test if we can actually read from /dev/zero
    $test_cmd = 'dd if=/dev/zero bs=1024 count=1 2>/dev/null | wc -c';
    $test_output = trim(shell_exec($test_cmd));
    echo "Testing /dev/zero access: got $test_output bytes (expected 1024)\n";
    
    if ($test_output != '1024') {
        echo "ERROR: Cannot properly read from /dev/zero\n";
        return 1;
    }
    
    // prepare key/iv (hex)
    $key_hex = bin2hex(random_bytes(32)); // 256-bit key
    $iv_hex  = bin2hex(random_bytes(16)); // 128-bit IV

    // escapeshellarg outfile
    $outfile_esc = escapeshellarg($outfile);
    
    // Create temp file for raw data first
    $temp_file = $outfile . '.tmp';
    $temp_file_esc = escapeshellarg($temp_file);
    
    echo "Step 1: Creating $size_mb MiB of zero data...\n";
    $dd_cmd = "dd if=/dev/zero of=$temp_file_esc bs=1M count=$size_mb 2>&1";
    echo "Running: $dd_cmd\n";
    
    $dd_output = [];
    $dd_ret = 0;
    exec($dd_cmd, $dd_output, $dd_ret);
    
    foreach ($dd_output as $line) {
        echo "DD: $line\n";
    }
    
    if ($dd_ret !== 0) {
        echo "DD command failed with exit code: $dd_ret\n";
        @unlink($temp_file);
        return $dd_ret;
    }
    
    // Check if temp file was created with correct size
    if (!file_exists($temp_file)) {
        echo "ERROR: Temp file was not created\n";
        return 1;
    }
    
    $temp_size = filesize($temp_file);
    $expected_size = $size_mb * 1024 * 1024;
    echo "Temp file size: " . human_readable_bytes($temp_size) . " (expected: " . human_readable_bytes($expected_size) . ")\n";
    
    if ($temp_size != $expected_size) {
        echo "ERROR: Temp file size mismatch\n";
        @unlink($temp_file);
        return 1;
    }
    
    echo "Step 2: Encrypting data with OpenSSL...\n";
    $openssl_cmd = "timeout 600 openssl enc -aes-256-ctr -K $key_hex -iv $iv_hex -nosalt -in $temp_file_esc -out $outfile_esc 2>&1";
    echo "Running: $openssl_cmd\n";
    
    $start_time = time();
    $ssl_output = [];
    $ssl_ret = 0;
    exec($openssl_cmd, $ssl_output, $ssl_ret);
    $end_time = time();
    
    if ($ssl_ret === 124) {
        echo "OpenSSL command timed out after 600 seconds\n";
        @unlink($temp_file);
        @unlink($outfile);
        return 124;
    }
    
    foreach ($ssl_output as $line) {
        echo "OpenSSL: $line\n";
    }
    
    // Clean up temp file
    @unlink($temp_file);
    
    if ($ssl_ret !== 0) {
        echo "OpenSSL command failed with exit code: $ssl_ret\n";
        return $ssl_ret;
    }
    
    $duration = $end_time - $start_time;
    echo "Completed in {$duration}s\n";
    
    return 0;
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

// Check existing files and clean up incomplete ones
echo "Checking existing files...\n";
$existing_files = [];
$files_to_generate = [];

foreach ($sizes_mb as $size_mb) {
    if ($size_mb < 1024) {
        $fname = "testfile_{$size_mb}MiB.bin";
    } else {
        $gb = intdiv($size_mb, 1024);
        $fname = "testfile_{$gb}GiB.bin";
    }
    
    $binfile = rtrim($outdir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fname;
    $tmpfile = $binfile . '.tmp';
    $expected_size = $size_mb * 1024 * 1024;
    
    if (file_exists($tmpfile)) {
        echo "Found incomplete temp file: $fname.tmp - removing\n";
        @unlink($tmpfile);
        if (file_exists($binfile)) {
            echo "Found corresponding bin file: $fname - removing (likely incomplete)\n";
            @unlink($binfile);
        }
        $files_to_generate[] = $size_mb;
    } elseif (file_exists($binfile)) {
        $actual_size = filesize($binfile);
        if ($actual_size === $expected_size) {
            echo "✓ Found complete file: $fname [" . human_readable_bytes($actual_size) . "]\n";
            $existing_files[] = $fname;
        } else {
            echo "Found incomplete file: $fname (size: " . human_readable_bytes($actual_size) . ", expected: " . human_readable_bytes($expected_size) . ") - removing\n";
            @unlink($binfile);
            $files_to_generate[] = $size_mb;
        }
    } else {
        $files_to_generate[] = $size_mb;
    }
}

if (empty($files_to_generate)) {
    echo "All files already exist and are complete!\n";
    echo "Generating index files...\n";
    generateIndexFiles($outdir, $sizes_mb);
    echo "Generated index.html and index.txt files for easy access.\n";
    exit(0);
}

// iterate sizes and create files
echo "\nPlanning to generate " . count($files_to_generate) . " files: " . implode(', ', array_map(function($mb) {
    return $mb >= 1024 ? (intdiv($mb, 1024) . 'GiB') : ($mb . 'MiB');
}, $files_to_generate)) . "\n";
echo "Skipping " . count($existing_files) . " existing complete files.\n\n";

foreach ($files_to_generate as $index => $size_mb) {
    echo "Progress: " . ($index + 1) . "/" . count($sizes_mb) . "\n";
    
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
        echo "Attempting OpenSSL method...\n";
        $rc = run_openssl_dd($outfile, $size_mb, $has_gnu_dd, $useDirect);
        if ($rc === 124) {
            echo "OpenSSL method timed out for this file size. Skipping to next file.\n";
            echo "You may want to run this file generation separately or use a different method.\n";
            continue; // Skip to next file instead of aborting
        } elseif ($rc !== 0) {
            fwrite(STDERR, "OpenSSL/dd pipeline failed (exit $rc). Falling back to PHP method.\n");
            // Clean up partial file if it exists
            if (file_exists($outfile)) {
                @unlink($outfile);
            }
            echo "Switching to PHP random_bytes method for this file...\n";
            $rc = run_php_random_write($outfile, $size_mb);
            if ($rc !== 0) {
                fwrite(STDERR, "PHP writer fallback also failed (exit $rc). Skipping this file.\n");
                continue; // Skip to next file instead of aborting
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
    
    // Memory usage check
    $memUsage = memory_get_usage(true);
    $memPeak = memory_get_peak_usage(true);
    echo "Memory usage: " . human_readable_bytes($memUsage) . " (peak: " . human_readable_bytes($memPeak) . ")\n";
    
    echo "Completed file " . ($index + 1) . "/" . count($files_to_generate) . ": $fname\n";
    
    // Save progress - create a simple progress file
    $progress_file = $outdir . DIRECTORY_SEPARATOR . '.generation_progress';
    $completed_files = [];
    if (file_exists($progress_file)) {
        $completed_files = json_decode(file_get_contents($progress_file), true) ?: [];
    }
    $completed_files[] = $size_mb;
    file_put_contents($progress_file, json_encode($completed_files));
    
    // Flush output for web browsers and check if we should continue
    if ($isWebRequest ?? false) {
        flush();
        ob_flush();
        
        // Check if connection is still active
        if (connection_aborted()) {
            echo "Connection aborted by client. Stopping.\n";
            echo "Progress saved. Run the script again to continue from where it left off.\n";
            exit(1);
        }
        
        // Brief pause to prevent server overload
        usleep(100000); // 0.1 seconds
    }
    
    echo "Moving to next file...\n\n";
}

echo "\nAll files generated in: $outdir\n";

// Clean up progress file
$progress_file = $outdir . DIRECTORY_SEPARATOR . '.generation_progress';
if (file_exists($progress_file)) {
    @unlink($progress_file);
}

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