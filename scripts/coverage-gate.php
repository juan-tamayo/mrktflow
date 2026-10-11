<?php

declare(strict_types=1);

$path = $argv[1] ?? dirname(__DIR__).'/coverage/php.xml';
if (! is_file($path)) {
    fwrite(STDERR, "Coverage report is required.\n");
    exit(1);
}
libxml_use_internal_errors(true);
$report = simplexml_load_file($path, SimpleXMLElement::class, LIBXML_NONET);
if ($report === false || $report->getName() !== 'coverage') {
    fwrite(STDERR, "Invalid Clover coverage report.\n");
    exit(1);
}

// Apply the 90% minimum independently to each money/inventory/payment/download
// domain. Using whole domains avoids fragile filename-based exemptions.
$domains = ['Pricing', 'Catalog', 'CheckoutOrders', 'Fulfillment'];
$failed = false;
foreach ($domains as $domain) {
    $statements = 0;
    $covered = 0;
    $reported = [];
    foreach ($report->xpath('//file') ?: [] as $file) {
        $name = str_replace('\\', '/', (string) $file['name']);
        if (! str_contains($name, '/app/Domain/'.$domain.'/')) {
            continue;
        }
        $reported[] = $name;
        $statements += (int) $file->metrics['statements'];
        $covered += (int) $file->metrics['coveredstatements'];
    }
    $directory = dirname(__DIR__).'/app/Domain/'.$domain;
    if (is_dir($directory)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php' && ! in_array(str_replace('\\', '/', $file->getPathname()), $reported, true)) {
                fwrite(STDERR, "$domain has a PHP file missing from coverage.\n");
                $failed = true;
            }
        }
    }
    if ($statements === 0) {
        echo "$domain: no executable domain statements yet; coverage is not applicable.\n";

        continue;
    }
    $percentage = 100 * $covered / $statements;
    printf("%s: %.2f%% line coverage (minimum 90%%).\n", $domain, $percentage);
    if ($covered > $statements || $covered < 0 || $percentage < 90) {
        $failed = true;
    }
}
exit($failed ? 1 : 0);
