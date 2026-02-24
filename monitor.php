<?php

/**
 * BfDI PIMS Registry Monitor
 * 
 * This script scrapes and structures the official registry of the Federal 
 * Commissioner for Data Protection and Freedom of Information (BfDI) in Germany.
 * 
 * Context: According to Section 18 of the EinwV (in conjunction with Section 25 TDDDG), 
 * providers of digital services in Germany may use recognized Personal Information 
 * Management Systems (PIMS) for consent management.
 * 
 * Repository:     https://github.comhttps://github.com/d0ubIeU/pims-monitor
 * Author:         d0ubIeU
 * Date:           2026-02-23 (Initial Setup)
 * License:        Mozilla Public License 2.0 (MPL 2.0)
 * License-URL:    https://www.mozilla.org/MPL/2.0/
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
    fwrite(STDERR, "❌ Error: Could not fetch the website (" . date('Y-m-d H:i:s') . ").\n");
    exit(1);
}

// Load existing data for comparison and history tracking
$oldRegistry = file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) : [];
if (!is_array($oldRegistry)) $oldRegistry = [];

// Index old data by name for efficient lookup
$historyMap = [];
foreach ($oldRegistry as $item) {
    $historyMap[$item['name']] = $item;
}

// --- PHASE 2: Parsing & Structuring ---
$dom = new DOMDocument();
@$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_NOERROR | LIBXML_NOWARNING);
$xpath = new DOMXPath($dom);

$currentEntries = [];
$nodes = $xpath->query("//p[strong[contains(text(), 'Name des Dienstes:')]]");

foreach ($nodes as $node) {
    $text = preg_replace('/\s+/', ' ', trim($node->textContent));
    $pattern = '/Name des Dienstes:\s*(.*?)\s*Anbieter:\s*(.*?)\s*Datum der Anerkennung:\s*(.*)/i';
    
    if (preg_match($pattern, $text, $matches)) {
        $name = trim($matches[1]);
        $provider = trim($matches[2]);
        $recognitionDate = trim($matches[3]);
        
        // Retain initial detection date or set current if new
        $firstDetected = isset($historyMap[$name]) ? $historyMap[$name]['first_detected'] : date('c');

        $currentEntries[$name] = [
            'name'           => $name,
            'provider'       => $provider,
            'date'           => $recognitionDate,
            'status'         => 'Verified',
            'first_detected' => $firstDetected,
            'last_seen'      => date('c') // Always updated to current run time
        ];
    }
}

// --- PHASE 3: Stability Check ---
if (empty($currentEntries)) {
    fwrite(STDERR, "❌ Error: No providers found. Possible layout change on website.\n");
    exit(1);
}

// --- PHASE 4: Merging & Change Detection ---
$finalRegistry = $currentEntries;
$hasAlertableChanges = false;
$changeDetails = [];

// Process removed or modified entries from history
foreach ($historyMap as $name => $oldItem) {
    if (!isset($currentEntries[$name])) {
        // Entry disappeared from website -> Mark as 'removed'
        if ($oldItem['status'] !== 'removed') {
            $oldItem['status'] = 'removed';
            $oldItem['removed_at'] = date('c');
            $hasAlertableChanges = true;
            $changeDetails[] = "🗑️ Deactivated: $name";
        }
        $finalRegistry[$name] = $oldItem;
    } else {
        // Check for content updates (Provider name or Recognition date)
        $current = $currentEntries[$name];
        if ($current['provider'] !== $oldItem['provider'] || $current['date'] !== $oldItem['date'] || $oldItem['status'] === 'removed') {
            $hasAlertableChanges = true;
            if ($oldItem['status'] === 'removed') {
                $changeDetails[] = "♻️ Reactivated: $name";
            } else {
                $changeDetails[] = "✏️ Modified: $name";
            }
        }
    }
}

// Detect brand new entries
foreach ($currentEntries as $name => $item) {
    if (!isset($historyMap[$name])) {
        $hasAlertableChanges = true;
        $changeDetails[] = "🆕 New: $name";
    }
}

// --- PHASE 5: Persistence & GitHub Communication ---
// Sort by name for a consistent JSON structure
ksort($finalRegistry);
$outputData = array_values($finalRegistry);
file_put_contents($dataFile, json_encode($outputData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

if ($hasAlertableChanges) {
    $fullMessage = implode(" | ", $changeDetails);
    
    // Write summary to GitHub Output for Step 6
    $outputFile = getenv('GITHUB_OUTPUT');
    if ($outputFile) {
        file_put_contents($outputFile, "details=$fullMessage" . PHP_EOL, FILE_APPEND);
    }
    exit(1); // Trigger Issue Alert
} else {
    echo "✅ Status Stable (" . count($currentEntries) . " verified services) as of " . date('Y-m-d H:i:s') . "\n";
    exit(0); // Regular exit, no Issue
}
