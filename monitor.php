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

// --- PHASE 2: Parsing & Structuring ---
$dom = new DOMDocument();
// Using LIBXML_NOERROR to ignore HTML5 specific warnings
@$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_NOERROR | LIBXML_NOWARNING);
$xpath = new DOMXPath($dom);

$currentProviders = [];

/**
 * SPECIFIC SEARCH FOR <p> TAGS:
 * We search for paragraphs that contain the "Name des Dienstes" label.
 */
$nodes = $xpath->query("//p[strong[contains(text(), 'Name des Dienstes:')]]");

foreach ($nodes as $node) {
    // Get text content and replace all whitespace/newlines/nbsp with a single space
    $text = $node->textContent;
    $text = preg_replace('/[\t\n\r\0\x0B\xA0]+/u', ' ', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text);
    
    /**
     * Regex Pattern:
     * Adjusted to be extremely flexible with spaces and labels.
     */
    $pattern = '/Name des Dienstes:\s*(.*?)\s*Anbieter:\s*(.*?)\s*Datum der Anerkennung:\s*(.*)/i';
    
    if (preg_match($pattern, $text, $matches)) {
        $currentProviders[] = [
            'name'       => trim($matches[1]),
            'provider'   => trim($matches[2]),
            'date'       => trim($matches[3]),
            'last_check' => date('c')
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
    echo "DEBUG: Extraction failed. Sample of first paragraph found:\n";
    $sample = $xpath->query("//p[strong]")->item(0);
    if ($sample) echo "Sample: " . trim($sample->textContent) . "\n";
    
    echo "❌ Error: No providers found at " . date('Y-m-d H:i:s') . ".\n";
    exit(1);
}

// --- PHASE 4: Comparison & Persistence ---
$oldProviders = file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) : [];
$oldNames = array_column($oldProviders, 'name');
$currentNames = array_column($currentProviders, 'name');

$newEntries = array_diff($currentNames, $oldNames);
$missingEntries = array_diff($oldNames, $currentNames);

if (!empty($newEntries) || !empty($missingEntries)) {
    file_put_contents($dataFile, json_encode($currentProviders, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    if (!empty($newEntries)) {
        echo "🚀 NEW_PIMS_DETECTED: " . implode(', ', $newEntries) . "\n";
    }
    if (!empty($missingEntries)) {
        echo "⚠️ WARNING: Services removed: " . implode(', ', $missingEntries) . "\n";
    }
} else {
    echo "✅ Status Stable (" . count($currentProviders) . " services) as of " . date('Y-m-d H:i:s') . "\n";
}
