const axios = require('axios');
const cheerio = require('cheerio');
const fs = require('fs');

const BFDI_URL = 'https://www.bfdi.bund.de/DE/Fachthemen/Inhalte/Telefon-Internet/Einwilligungsverwaltung/Einwilligungsverwaltung.html';
const DATA_FILE = './pims_registry.json';

async function monitorBfdiRegistry() {
    try {
        const { data } = await axios.get(BFDI_URL, {
            headers: { 'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36' }
        });
        
        const $ = cheerio.load(data);
        
        // Wir suchen jetzt gezielt nach dem Text "Name des Dienstes:"
        // Da die BfDI <strong> nutzt, suchen wir in allen relevanten Tags
        let currentProviders = [];
        
        $('*').each((i, el) => {
            const text = $(el).text();
            // Regex sucht nach "Name des Dienstes:" gefolgt von beliebigem Text bis zum Zeilenende oder Tag-Ende
            const match = text.match(/Name des Dienstes:\s*([^\n\r<]+)/);
            if (match && match[1]) {
                const name = match[1].trim();
                if (name.length > 2 && name !== "Consenter") { // "Consenter" filtern wir für den Test mal nicht aus
                     currentProviders.push(name);
                } else if (name === "Consenter") {
                     currentProviders.push(name);
                }
            }
        });

        currentProviders = [...new Set(currentProviders)];

        // --- STRUKTUR-CHECK ---
        if (currentProviders.length === 0) {
            // Wenn gar nichts gefunden wird, loggen wir zur Sicherheit das HTML-Snippet
            console.log("DEBUG - HTML Snippet:", $('body').html().substring(0, 1000));
            throw new Error("STRUKTUR_AENDERUNG: Kein Anbieter-Muster gefunden.");
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
        throw error;
    }
}

monitorBfdiRegistry();
