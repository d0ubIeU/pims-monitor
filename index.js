const axios = require('axios');
const cheerio = require('cheerio');
const fs = require('fs');

const BFDI_URL = 'https://www.bfdi.bund.de';
const DATA_FILE = './pims_registry.json';

async function monitorBfdiRegistry() {
    try {
        const { data } = await axios.get(BFDI_URL);
        const $ = cheerio.load(data);
        const pageContent = $('#main').text() || $('body').text();
        
        const namePattern = /Name des Dienstes:\s*([^\n<]+)/g;
        let match;
        let currentProviders = [];

        while ((match = namePattern.exec(pageContent)) !== null) {
            currentProviders.push(match[1].trim());
        }
        currentProviders = [...new Set(currentProviders)];

        // --- INTEGRITÄTS-CHECK ---
        // Wenn die Liste plötzlich leer ist, hat sich das HTML-Layout geändert!
        if (currentProviders.length === 0) {
            throw new Error("STRUKTUR_AENDERUNG: Keine Anbieter gefunden. Pfad/Regex prüfen!");
        }

        let oldProviders = fs.existsSync(DATA_FILE) ? JSON.parse(fs.readFileSync(DATA_FILE)) : [];
        
        // Check 1: Neue Einträge?
        const newEntries = currentProviders.filter(p => !oldProviders.includes(p));
        // Check 2: Sind bekannte Einträge verschwunden? (Indiz für Layout-Änderung)
        const missingEntries = oldProviders.filter(p => !currentProviders.includes(p));

        if (newEntries.length > 0 || missingEntries.length > 0) {
            fs.writeFileSync(DATA_FILE, JSON.stringify(currentProviders, null, 2));
            
            if (newEntries.length > 0) {
                throw new Error(`NEUE_PIMS_ENTDECKT: ${newEntries.join(', ')}`);
            }
            if (missingEntries.length > 0) {
                throw new Error(`STRUKTUR_WARNHINWEIS: Bekannte Anbieter (${missingEntries.join(', ')}) nicht mehr gefunden!`);
            }
        } else {
            console.log('✅ Alles stabil. Gelistet: ' + currentProviders.join(', '));
        }

    } catch (error) {
        // Wir lassen den Fehler absichtlich durchgehen, damit GitHub die Mail schickt
        throw error;
    }
}

monitorBfdiRegistry();
