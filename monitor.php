<?php

/**
 * BfDI PIMS Registry Monitor
 * 
 * This script scrapes the official BfDI website to track registered 
 * Personal Information Management Systems (PIMS).
 */

// Configuration
$bfdiUrl = 'https://www.bfdi.bund.de/DE/Fachthemen/Inhalte/Telefon-Internet/Einwilligungsverwaltung/Einwilligungsverwaltung.html';
$dataFile = './pims_registry.json';

/**
 * PHASE 1: Data Retrieval
 * We use a custom User-Agent to avoid being blocked as a generic bot.
 */
$options = [
    'http' => [
        'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36\r\n"
    ]
];
$context = stream_context_create($options);
$html = @file_get_contents($bfdiUrl, false, $context);

if (!$html) {
    echo "❌ Error: Could not fetch the website. Check URL or connection.\n";
    exit(1);
}

/**
 * PHASE 2: Parsing the HTML
 * We use DOMDocument and XPath for efficient and targeted data extraction.
 */
$dom = new DOMDocument();
// Suppress warnings for malformed HTML common on government sites
@$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
$xpath = new DOMXPath($dom);

$currentProviders = [];

/**
 * TARGETED SEARCH: 
 * Based on the identified structure, we look for <p> tags 
 * inside the main content column that contain "Name des Dienstes:".
 */
$nodes = $xpath->query("//div[contains(@class, 'xlarge-7')]//p[contains(text(), 'Name des Dienstes:')]");

foreach ($nodes as $node) {
    $text = $node->textContent;
    
    // Regex: Matches "Name des Dienstes:" and captures everything until the end of the line/tag
    if (preg_match('/Name des Dienstes:\s*(.*)/', $text, $matches)) {
        $name = trim($matches[1]);
        if (!empty($name)) {
            $currentProviders[] = $name;
        }
    }
}

// Remove potential duplicates and reset array indices
$currentProviders = array_values(array_unique($currentProviders));

/**
 * PHASE 3: Stability Check
 * If no providers are found, the structure of the website likely changed.
 */
if (empty($currentProviders)) {
    echo "❌ Error: No providers found. The website structure might have changed!\n";
    exit(1);
}

/**
 * PHASE 4: Comparison & File Handling
 * We compare the new data with the existing JSON file to detect changes.
 */
$oldProviders = [];
if (file_exists($dataFile)) {
    $content = file_get_contents($dataFile);
    $oldProviders = $content ? json_decode($content, true) : [];
}

// Calculate differences
$newEntries = array_diff($currentProviders, $oldProviders);
$missingEntries = array_diff($oldProviders, $currentProviders);

if (!empty($newEntries) || !empty($missingEntries)) {
    // Update the local JSON file
    file_put_contents($dataFile, json_encode($currentProviders, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    if (!empty($newEntries)) {
        echo "🚀 NEW_PIMS_DETECTED: " . implode(', ', $newEntries) . "\n";
    }
    if (!empty($missingEntries)) {
        echo "⚠️ WARNING: Previously registered services disappeared: " . implode(', ', $missingEntries) . "\n";
    }
} else {
    echo "✅ Status Stable (" . count($currentProviders) . " services): " . implode(', ', $currentProviders) . "\n";
}
