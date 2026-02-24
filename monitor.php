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
    echo "❌ Error: Could not fetch the website (" . date('Y-m-d H:i:s') . ").\n";
    exit(1);
}

// Load existing data for comparison and history
$oldRegistry = file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) : [];
if (!is_array($oldRegistry)) $oldRegistry = [];

// Index old data by name for faster lookup
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
        
        // Logic: Keep old first_detected or set new one
        $firstDetected = isset($historyMap[$name]) ? $historyMap[$name]['first_detected'] : date('c');

        $currentEntries[$name] = [
            'name'           => $name,
            'provider'       => $provider,
            'date'           => $recognitionDate,
            'status'         => 'Verified',
            'first_detected' => $firstDetected,
            'last_seen'      => date('c')
        ];
    }
}

// --- PHASE 3: Stability Check ---
if (empty($currentEntries)) {
    echo "❌ Error: No providers found. Possible layout change on website.\n";
    exit(1);
}

// --- PHASE 4: Merging & History ---
$finalRegistry = $currentEntries;
$hasChanges = false;
$changeDetails = [];

// Check for removed or modified entries
foreach ($historyMap as $name => $oldItem) {
    if (!isset($currentEntries[$name])) {
        // Entry is no longer on the website -> Mark as 'removed'
        if ($oldItem['status'] !== 'removed') {
            $oldItem['status'] = 'removed';
            $oldItem['removed_at'] = date('c');
            $hasChanges = true;
            $changeDetails[] = "🗑️ Deactivated: $name";
        }
        $finalRegistry[$name] = $oldItem;
    } else {
        // Entry still exists -> check if content changed
        $current = $currentEntries[$name];
        if ($current['provider'] !== $oldItem['provider'] || $current['date'] !== $oldItem['date'] || $oldItem['status'] === 'removed') {
            $hasChanges = true;
            if ($oldItem['status'] === 'removed') {
                $changeDetails[] = "♻️ Reactivated: $name";
            } else {
                $changeDetails[] = "✏️ Modified: $name";
            }
        }
    }
}

// Check for brand new entries
foreach ($currentEntries as $name => $item) {
    if (!isset($historyMap[$name])) {
        $hasChanges = true;
        $changeDetails[] = "🆕 New: $name";
    }
}

// --- PHASE 5: Persistence & Output ---
if ($hasChanges) {
    // Sort by name for a clean JSON structure
    ksort($finalRegistry);
    $outputData = array_values($finalRegistry);
    
    file_put_contents($dataFile, json_encode($outputData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    $fullMessage = implode(" | ", $changeDetails);
    echo "ALARM: " . $fullMessage . "\n";

    $outputFile = getenv('GITHUB_OUTPUT');
    if ($outputFile) {
        file_put_contents($outputFile, "details=$fullMessage" . PHP_EOL, FILE_APPEND);
    }
    exit(1);
} else {
    echo "✅ Status Stable (" . count($currentEntries) . " verified services) as of " . date('Y-m-d H:i:s') . "\n";
    exit(0);
}

