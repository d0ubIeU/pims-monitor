const axios = require('axios');
const cheerio = require('cheerio');
const fs = require('fs');

const BFDI_URL = 'https://www.bfdi.bund.de/DE/Fachthemen/Inhalte/Telefon-Internet/Einwilligungsverwaltung/Einwilligungsverwaltung.html';
const DATA_FILE = './pims_registry.json';

async function monitorBfdiRegistry() {
    try {
        // Wir setzen einen User-Agent, damit die BfDI uns nicht als "Bot" blockiert
        const { data } = await axios.get(BFDI_URL, {
            headers: { 'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36' }
        });
        
        const $ = cheerio.load(data);
        
        // Wir nehmen den gesamten Body-Text und entfernen überflüssige Leerzeichen/Umbrüche
        const fullText = $('body').text().replace(/\s+/g, ' ');
        
        // Verfeinerter Regex: Sucht nach "Name des Dienstes:" gefolgt von Text bis zum nächsten Label
        const namePattern = /Name des Dienstes:\s*([^Anbieter|Datum|Name]+)/g;
        let match;
        let currentProviders = [];

        while ((match = namePattern.exec(fullText)) !== null) {
            let foundName = match[1].trim();
            if (foundName.length > 2) {
                currentProviders.push(foundName);
            }
        }

        currentProviders = [...new Set(currentProviders)];

        // --- VERBESSERTER STRUKTUR-CHECK ---
        if (currentProviders.length === 0) {
            // Debug-Ausgabe für das GitHub Log, falls es wieder scheitert
            console.log("DEBUG - Seiteninhalt (Auszug):", fullText.substring(0, 500));
            throw new Error("STRUKTUR_AENDERUNG: Kein Anbieter-Muster im Text gefunden.");
        }

        let oldProviders = fs.existsSync(DATA_FILE) ? JSON.parse(fs.readFileSync(DATA_FILE)) : [];
        
        const newEntries = currentProviders.filter(p => !oldProviders.includes(p));
        const missingEntries = oldProviders.filter(p => !currentProviders.includes(p));

        if (newEntries.length > 0 || missingEntries.length > 0) {
            fs.writeFileSync(DATA_FILE, JSON.stringify(currentProviders, null, 2));
            
            if (newEntries.length > 0) {
                throw new Error(`NEUE_PIMS_ENTDECKT: ${newEntries.join(', ')}`);
            }
            if (missingEntries.length > 0) {
                throw new Error(`STRUKTUR_WARNHINWEIS: ${missingEntries.join(', ')} verschwunden!`);
            }
        } else {
            console.log('✅ Stand stabil: ' + currentProviders.join(', '));
        }

    } catch (error) {
        throw error; // Reicht den Fehler an GitHub Actions für die Mail weiter
    }
}

monitorBfdiRegistry();
