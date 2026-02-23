const axios = require('axios');
const cheerio = require('cheerio');
const fs = require('fs');

const BFDI_URL = 'https://www.bfdi.bund.de/DE/Fachthemen/Inhalte/Telefon-Internet/Einwilligungsverwaltung/Einwilligungsverwaltung.html';
const DATA_FILE = './pims_registry.json';

async function monitorBfdiRegistry() {
    try {
        const { data } = await axios.get(BFDI_URL);
        const $ = cheerio.load(data);
        
        // Den Textinhalt extrahieren
        const pageContent = $('#main').text() || $('body').text();
        
        // Regex sucht nach dem Muster
        const namePattern = /Name des Dienstes:\s*([^\n<]+)/g;
        let match;
        let currentProviders = [];

        while ((match = namePattern.exec(pageContent)) !== null) {
            currentProviders.push(match[1].trim());
        }

        currentProviders = [...new Set(currentProviders)];

        let oldProviders = fs.existsSync(DATA_FILE) ? JSON.parse(fs.readFileSync(DATA_FILE)) : [];
        const newEntries = currentProviders.filter(p => !oldProviders.includes(p));

        if (newEntries.length > 0) {
            console.log('🚨 NEUE PIMS GEFUNDEN:', newEntries);
            fs.writeFileSync(DATA_FILE, JSON.stringify(currentProviders, null, 2));
            
            // WICHTIG: Wir werfen den Fehler AUSSERHALB des Catch-Blocks oder reichen ihn durch,
            // damit GitHub Actions den "Failed"-Status erkennt.
            throw new Error(`NEUE_PIMS_ENTDECKT: ${newEntries.join(', ')}`); 
        } else {
            console.log('✅ Stand unverändert: ' + (currentProviders.join(', ') || 'Keine Einträge'));
        }

    } catch (error) {
        // Wenn es unser selbst geworfener PIMS-Alarm ist, werfen wir ihn weiter für GitHub
        if (error.message.includes('NEUE_PIMS_ENTDECKT')) {
            throw error; 
        }
        console.error('❌ Technischer Fehler beim Scan:', error.message);
        // Optional: Auch bei technischen Fehlern (Seite down) benachrichtigen lassen? 
        // Dann hier ebenfalls: throw error;
    }
}

monitorBfdiRegistry();
