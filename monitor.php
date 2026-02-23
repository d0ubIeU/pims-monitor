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
 * PHASE 2: Parsing the HTML (Improved Robustness)
 */
$dom = new DOMDocument();
// Using LIBXML_NOERROR to ignore HTML5 warnings
@$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_NOERROR | LIBXML_NOWARNING);
$xpath = new DOMXPath($dom);

$currentProviders = [];

/**
 * IMPROVED SEARCH:
 * We look for any text node containing "Name des Dienstes:" 
 * within the content area to be less dependent on exact <p> structures.
 */
$query = "//*[contains(text(), 'Name des Dienstes:')]";
$nodes = $xpath->query($query);

foreach ($nodes as $node) {
    $text = $node->textContent;
    
    // Improved Regex: 
    // 1. Look for "Name des Dienstes:"
    // 2. Capture everything after the colon until the end of the string
    if (preg_match('/Name des Dienstes:\s*(.+)/u', $text, $matches)) {
        $name = trim($matches[1]);
        
        // Filter out placeholders or very short strings
        if (!empty($name) && strlen($name) > 2) {
            $currentProviders[] = $name;
        }
    }
}

// Fallback: If still empty, let's try a broader search in the whole document
if (empty($currentProviders)) {
    // This catches cases where the text might be split across multiple nodes
    foreach ($dom->getElementsByTagName('p') as $p) {
        $text = $p->textContent;
        if (strpos($text, 'Name des Dienstes:') !== false) {
            $parts = explode('Name des Dienstes:', $text);
            if (isset($parts[1])) {
                $name = trim($parts[1]);
                if (!empty($name)) $currentProviders[] = $name;
            }
        }
    }
}

$currentProviders = array_values(array_unique($currentProviders));

/**
 * PHASE 3: Stability Check
 */
if (empty($currentProviders)) {
    // Log a snippet of the HTML for debugging in GitHub Actions logs
    echo "DEBUG: HTML Structure might have changed. Snippet:\n";
    echo substr(strip_tags($html), 0, 500) . "...\n";
    echo "❌ Error: No providers found.\n";
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
