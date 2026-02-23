const axios = require('axios');
const cheerio = require('cheerio');
const fs = require('fs');

const BFDI_URL = 'https://www.bfdi.bund.de';
const DATA_FILE = './pims_registry.json';

async function monitorBfdiRegistry() {
    try {
        // 1. Daten abrufen mit User-Agent
        const { data } = await axios.get(BFDI_URL, {
            headers: { 'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36' }
        });
        
        const $ = cheerio.load(data);
        let currentProviders = [];
        
        // 2. Gezielte Suche im Content-Bereich und den <p>-Tags
        // Wir nutzen den von dir identifizierten Container
        $('div.content.xlarge-7 p').each((i, el) => {
            const text = $(el).text();
            
            // Regex sucht nach "Name des Dienstes:" und extrahiert den Rest der Zeile
            const match = text.match(/Name des Dienstes:\s*([^\n\r<]+)/);
            
            if (match && match[1]) {
                const name = match[1].trim();
                if (name.length > 1) {
                    currentProviders.push(name);
                }
            }
        });

        // Duplikate entfernen
        currentProviders = [...new Set(currentProviders)];

        // --- STRUKTUR-CHECK ---
        if (currentProviders.length === 0) {
            console.error("DEBUG: Konnte keine Anbieter finden. HTML Struktur prüfen.");
            throw new Error("STRUKTUR_AENDERUNG: Kein Anbieter-Muster in div.content.xlarge-7 gefunden.");
        }

        // --- DATEI-LOGIK ---
        let oldProviders = [];
        if (fs.existsSync(DATA_FILE)) {
            try {
                const rawData = fs.readFileSync(DATA_FILE, 'utf8');
                oldProviders = rawData ? JSON.parse(rawData) : [];
            } catch (e) {
                console.warn("⚠️ Warnung: JSON konnte nicht gelesen werden, erstelle neu.");
            }
        }

        const newEntries = currentProviders.filter(p => !oldProviders.includes(p));
        const missingEntries = oldProviders.filter(p => !currentProviders.includes(p));

        // Speichern und Error-Trigger bei Änderungen
        if (newEntries.length > 0 || missingEntries.length > 0) {
            fs.writeFileSync(DATA_FILE, JSON.stringify(currentProviders, null, 2));
            
            if (newEntries.length > 0) {
                throw new Error(`🚀 NEUE_PIMS_ENTDECKT: ${newEntries.join(', ')}`);
            }
            if (missingEntries.length > 0) {
                throw new Error(`⚠️ STRUKTUR_WARNHINWEIS: Dienste verschwunden: ${missingEntries.join(', ')}`);
            }
        } else {
            console.log('✅ Stand stabil (' + currentProviders.length + ' Anbieter): ' + currentProviders.join(', '));
        }

    } catch (error) {
        // Falls es ein "erwarteter" Error aus der Logik ist, geben wir ihn aus
        // Ansonsten den kompletten Axios/System-Error
        console.error(`❌ Fehler: ${error.message}`);
        throw error;
    }
}

// Start des Skripts
monitorBfdiRegistry();
