<?php

/**
 * BfDI PIMS Registry Monitor
 * 
 * This script scrapes and structures the official registry of the Federal 
 * Commissioner for Data Protection and Freedom of Information (BfDI) in Germany.
 */

// Configuration
$bfdiUrl = 'https://www.bfdi.bund.de/DE/Fachthemen/Inhalte/Telefon-Internet/Einwilligungsverwaltung/Einwilligungsverwaltung.html';
$dataFile = './pims_registry.json';

// --- PHASE 1: Data Retrieval ---
$options = [
    'http' => [
        'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36\r\n"
    ]
];
$context = stream_context_create($options);
$html = @file_get_contents($bfdiUrl, false, $context);

if (!$html) {
    echo "❌ Error: Could not fetch the website (" . date('Y-m-d H:i:s') . ").\n";
    exit(1);
}

// Load existing data for comparison (Moved up to Phase 2 for 'first_detected' logic)
$oldProviders = file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) : [];
if (!is_array($oldProviders)) $oldProviders = [];

// --- PHASE 2: Parsing & Structuring ---
$dom = new DOMDocument();
@$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_NOERROR | LIBXML_NOWARNING);
$xpath = new DOMXPath($dom);

$currentProviders = [];
$nodes = $xpath->query("//p[strong[contains(text(), 'Name des Dienstes:')]]");

foreach ($nodes as $node) {
    $text = $node->textContent;
    $text = preg_replace('/[\t\n\r\0\x0B\xA0]+/u', ' ', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text);
    
    $pattern = '/Name des Dienstes:\s*(.*?)\s*Anbieter:\s*(.*?)\s*Datum der Anerkennung:\s*(.*)/i';
    
    if (preg_match($pattern, $text, $matches)) {
        $name = trim($matches[1]);
        
        $existingEntry = null;
        foreach ($oldProviders as $old) {
            if (isset($old['name']) && $old['name'] === $name) {
                $existingEntry = $old;
                break;
            }
        }
    
        $currentProviders[] = [
            'name'           => $name,
            'provider'       => trim($matches[2]),
            'date'           => trim($matches[3]),
            'first_detected' => $existingEntry ? $existingEntry['first_detected'] : date('c')
        ];
    }
}

// Ensure unique entries
$uniqueProviders = [];
foreach ($currentProviders as $p) {
    $uniqueProviders[$p['name']] = $p;
}
$currentProviders = array_values($uniqueProviders);

// --- PHASE 3: Stability Check ---
if (empty($currentProviders)) {
    echo "❌ Error: No providers found at " . date('Y-m-d H:i:s') . ".\n";
    exit(1);
}

// --- PHASE 4: Comparison & Notification Logic ---
$oldNames = array_column($oldProviders, 'name');
$currentNames = array_column($currentProviders, 'name');

$newEntries = array_diff($currentNames, $oldNames);
$missingEntries = array_diff($oldNames, $currentNames);

// Check for modifications (same name, but provider or date changed)
$modifiedEntries = [];
foreach ($currentProviders as $current) {
    foreach ($oldProviders as $old) {
        if ($current['name'] === $old['name']) {
            if ($current['provider'] !== $old['provider'] || $current['date'] !== $old['date']) {
                $modifiedEntries[] = $current['name'];
            }
        }
    }
}

if (!empty($newEntries) || !empty($missingEntries) || !empty($modifiedEntries)) {
    // Save updated data
    file_put_contents($dataFile, json_encode($currentProviders, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    // Prepare detailed message for GitHub Issue
    $details = [];
    if (!empty($newEntries)) $details[] = "🆕 Added: " . implode(', ', $newEntries);
    if (!empty($modifiedEntries)) $details[] = "✏️ Modified: " . implode(', ', $modifiedEntries);
    if (!empty($missingEntries)) $details[] = "🗑️ Removed: " . implode(', ', $missingEntries);
    
    $fullMessage = implode(" | ", $details);
    echo "ALARM: " . $fullMessage . "\n";

    // Write to GitHub Output
    $outputFile = getenv('GITHUB_OUTPUT');
    if ($outputFile) {
        file_put_contents($outputFile, "details=$fullMessage" . PHP_EOL, FILE_APPEND);
    }
    
    // Exit with 1 to trigger the GitHub Action Alert/Issue
    exit(1);
} else {
    echo "✅ Status Stable (" . count($currentProviders) . " services) as of " . date('Y-m-d H:i:s') . "\n";
    exit(0);
}
