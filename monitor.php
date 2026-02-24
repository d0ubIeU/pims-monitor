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
$options = ['http' => ['header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36\r\n"]];
$context = stream_context_create($options);
$html = @file_get_contents($bfdiUrl, false, $context);

if (!$html) exit(1);

// Load existing data
$oldRegistry = file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) : [];
if (!is_array($oldRegistry)) $oldRegistry = [];

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
        $date = trim($matches[3]);
        
        $currentEntries[$name] = [
            'name'           => $name,
            'provider'       => $provider,
            'date'           => $date,
            'status'         => 'Verified',
            'first_detected' => $historyMap[$name]['first_detected'] ?? date('c'),
            'last_seen'      => date('c') // Always update timestamp
        ];
    }
}

if (empty($currentEntries)) exit(1);

// --- PHASE 3: Comparison Logic (Content only) ---
$hasAlertableChanges = false;
$changeDetails = [];

// Check for removed or modified providers
foreach ($historyMap as $name => $oldItem) {
    if ($oldItem['status'] === 'Verified' && !isset($currentEntries[$name])) {
        $hasAlertableChanges = true;
        $changeDetails[] = "🗑️ Removed: $name";
    } elseif (isset($currentEntries[$name]) && $currentEntries[$name]['provider'] !== $oldItem['provider']) {
        $hasAlertableChanges = true;
        $changeDetails[] = "✏️ Modified: $name";
    }
}

// Check for new providers
foreach ($currentEntries as $name => $item) {
    if (!isset($historyMap[$name])) {
        $hasAlertableChanges = true;
        $changeDetails[] = "🆕 New: $name";
    }
}

// --- PHASE 4: Persistence ---
$finalRegistry = $currentEntries;
foreach ($historyMap as $name => $oldItem) {
    if (!isset($currentEntries[$name])) {
        $oldItem['status'] = 'removed';
        $oldItem['removed_at'] = $oldItem['removed_at'] ?? date('c');
        $finalRegistry[$name] = $oldItem;
    }
}

ksort($finalRegistry);
file_put_contents($dataFile, json_encode(array_values($finalRegistry), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// --- PHASE 5: Exit Signal ---
if ($hasAlertableChanges) {
    $fullMessage = implode(" | ", $changeDetails);
    $outputFile = getenv('GITHUB_OUTPUT');
    if ($outputFile) {
        file_put_contents($outputFile, "details=$fullMessage" . PHP_EOL, FILE_APPEND);
    }
    exit(1); // Trigger Alert in Workflow
}

exit(0);
