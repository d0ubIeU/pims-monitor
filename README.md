# 🛡️ PIMS Registry Monitor (Compliance Tool for German EinwV)

This repository provides an automated **Early Warning System (EWS)** to ensure compliance with the **German Ordinance on Consent Management Systems (Einwilligungsverwaltungs-Verordnung - EinwV)**.

## 📋 Legal Context (Germany)
According to **Section 18 of the EinwV** (in conjunction with **Section 25 TDDDG**), providers of digital services in Germany are encouraged to recognize **certified** Personal Information Management Systems (PIMS). 

As a website operator, if you choose to suppress your cookie banner based on a PIMS signal, you are legally required to verify that the signal originates from an **officially recognized provider** (Section 18, Para. 2, No. 2 EinwV). 

## 🔍 Why Web Scraping?
This repository uses web scraping out of technical necessity. According to the German ordinance, website operators must verify the recognition status of a PIMS provider. 

However, as of early 2026, the German authorities (**BfDI** and **BNetzA**) have not yet provided a standardized, machine-readable interface (API) or a central "Trust List" (similar to SSL/TLS certificate authorities) for this purpose. The only official source of truth is a legacy HTML page. Consequently, automated web scraping is currently the only viable method for developers to fulfill their legal "Duty of Care" without manual, inefficient daily checks of government websites.

## ⚠️ Important: Scope & Limitations
**This tool is NOT a real-time verification API.** 
*   **Discovery Only:** It monitors the official registry for *newly* recognized providers.
*   **Manual Integration Required:** It does **not** fetch cryptographic keys or automate the technical handshake. Once a new provider is detected, the operator must manually obtain their documentation and public keys to update their local validation logic (e.g., JWS/JWT verification).
*   **No Centralized API:** Bridges the gap created by the lack of government-provided data structures.

## 🚀 How it Works
A **GitHub Action Workflow** runs automatically every 24 hours to perform the following monitoring tasks:

1.  **Automated Scraping:** The Node.js script (`index.js`) fetches the official [BfDI Registry Page](https://www.bfdi.bund.de).
2.  **Entity Extraction:** It identifies entries following the official "Name des Dienstes: [Name]" format.
3.  **Integrity Check:** The script ensures that previously known providers (e.g., *Consenter*) are still found. If the list is empty, it triggers a "Structure Change" alert (indicating a possible layout change on the government website).
4.  **Compliance Alerting:** 
    *   If **new providers** are discovered or the **page structure breaks**, the GitHub Action fails (`exit 1`).
    *   GitHub automatically sends an **Email Notification** to the repository owner.
5.  **Audit Log:** The `pims_registry.json` is updated and pushed back to the repository, serving as a historical record of when which provider was first officially recognized.

## 🛠 Setup & Installation

1.  **Repository Setup:** Copy this repository to your GitHub account.
2.  **Permissions:** Go to `Settings > Actions > General > Workflow permissions` and select **"Read and write permissions"** (required for the automated JSON update).
3.  **Initial Baseline:** Manually trigger the workflow under the `Actions` tab to create your initial provider list.

## 📂 Repository Structure
*   `index.js`: The scraper core (Node.js using Axios & Cheerio).
*   `pims_registry.json`: The "Source of Truth" containing all currently detected providers.
*   `.github/workflows/monitor.yml`: Automated schedule configuration.

## ⚖️ Legal Disclaimer
This tool is a technical aid for fulfilling the **Duty of Care (Sorgfaltspflicht)** under **Section 18 Para. 2 EinwV**. Automated requests are performed at a low frequency (once daily) to respect the server load of the German Federal Authorities. According to **Section 5 of the German Copyright Act (UrhG)**, official works such as registries and announcements are not subject to copyright protection.

## 🚨 Action Plan on Alert
When you receive a "Workflow failed" email:
1.  **New Provider Identified?** Visit the provider's website, download their technical documentation, and update your website's consent logic with their specific public keys/signatures.
2.  **Structural Warning?** Check if the BfDI changed the URL or HTML layout and update the regex in `index.js` to restore monitoring functionality.
