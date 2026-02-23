<?php

$bfdiUrl = 'https://www.bfdi.bund.de/DE/Fachthemen/Inhalte/Telefon-Internet/Einwilligungsverwaltung/Einwilligungsverwaltung.html';
$dataFile = './pims_registry.json';

// 1. HTML laden mit User-Agent
$options = [
    'http' => [
        'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\n"
    ]
];
$context = stream_context_create($options);
$html = @file_get_contents($bfdiUrl, false, $context);

if (!$html) {
    die("Fehler: Webseite konnte nicht geladen werden.\n");
}

// 2. DOM laden
$dom = new DOMDocument();
@$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
$xpath = new DOMXPath($dom);

$currentProviders = [];

// Suche in <p> Tags nach "Name des Dienstes:"
$nodes = $xpath->query("//p[contains(text(), 'Name des Dienstes:')]");

foreach ($nodes as $node) {
    $text = $node->textContent;
    if (preg_match('/Name des Dienstes:\s*(.*)/', $text, $matches)) {
        $name = trim($matches[1]);
        if (!empty($name)) {
            $currentProviders[] = $name;
        }
    }
}

$currentProviders = array_unique($currentProviders);

if (empty($currentProviders)) {
    echo "❌ Keine Anbieter gefunden. Struktur prüfen!\n";
    exit(1);
}

// 3. Datei-Logik
$oldProviders = file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) : [];
$newEntries = array_diff($currentProviders, $oldProviders);
$missingEntries = array_diff($oldProviders, $currentProviders);

if (!empty($newEntries) || !empty($missingEntries)) {
    file_put_contents($dataFile, json_encode(array_values($currentProviders), JSON_PRETTY_PRINT));
    
    if (!empty($newEntries)) {
        echo "🚀 NEUE_PIMS_ENTDECKT: " . implode(', ', $newEntries) . "\n";
    }
    if (!empty($missingEntries)) {
        echo "⚠️ VERSCHWUNDEN: " . implode(', ', $missingEntries) . "\n";
    }
} else {
    echo "✅ Stand stabil: " . implode(', ', $currentProviders) . "\n";
}
