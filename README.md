# 🛡️ PIMS Registry Monitor (Compliance Tool for German EinwV)

[![Daily BfDI PHP Scraper](https://github.com/d0ubIeU/pims-monitor/actions/workflows/daily_scrape.yml/badge.svg)](https://github.com/d0ubIeU/pims-monitor/actions/workflows/daily_scrape.yml)

This repository provides an automated **Early Warning System (EWS)** to ensure compliance with the **German Ordinance on Consent Management Systems (Einwilligungsverwaltungs-Verordnung - EinwV)**.

## 📋 Legal Context (Germany)
According to **Section 18 of the EinwV** (in conjunction with **Section 25 TDDDG**), providers of digital services in Germany are encouraged to recognize **certified** Personal Information Management Systems (PIMS). 

As a website operator, if you choose to suppress your cookie banner based on a PIMS signal, you are legally required to verify that the signal originates from an **officially recognized provider** (Section 18, Para. 2, No. 2 EinwV). 

## 🔍 Why Web Scraping?
This repository uses web scraping out of technical necessity. According to the German ordinance, website operators must verify the recognition status of a PIMS provider. 

However, as of early 2026, the German authorities (**BfDI** and **BNetzA**) have not yet provided a standardized, machine-readable interface (API) or a central "Trust List" for this purpose. The only official source of truth is a legacy HTML page. Consequently, automated web scraping is currently the only viable method for developers to fulfill their legal "Duty of Care" without manual, inefficient daily checks of government websites.

## ⚠️ Important: Scope & Limitations
**This tool is NOT a real-time verification API.** 
*   **Discovery Only:** It monitors the official registry for *newly* recognized providers.
*   **Manual Integration Required:** It does **not** fetch cryptographic keys or automate the technical handshake. Once a new provider is detected, the operator must manually obtain their documentation and public keys to update their local validation logic (e.g., JWS/JWT verification).
*   **No Centralized API:** Bridges the gap created by the lack of government-provided data structures.

## 🚀 How it Works
A **GitHub Action Workflow** runs automatically every 24 hours (**01:00 UTC**) to perform the following monitoring tasks:

1.  **Automated Scraping:** The PHP script (`monitor.php`) fetches the official [BfDI Registry Page](https://www.bfdi.bund.de/DE/Fachthemen/Inhalte/Telefon-Internet/Einwilligungsverwaltung/Einwilligungsverwaltung.html).
2.  **Entity Extraction:** It identifies entries following the official "Name des Dienstes" format and extracts structured data (Name, Provider, Recognition Date).
3.  **Integrity Check:** The script ensures that previously known providers are still found. If the list is empty, it triggers a "Structure Change" alert (indicating a possible layout change on the government website) and prevents data loss.
4.  **Compliance Alerting:** 
    *   If **new providers** are discovered, existing ones are **removed**, or the **page structure breaks**, the GitHub Action fails (`exit 1`) and creates a **Detailed GitHub Issue**.
    *   GitHub automatically sends an **Email Notification** to the repository owner if configured correctly.
5.  **Audit Log:** The `pims_registry.json` is updated and pushed back to the repository. Every entry contains a `first_detected` and `last_seen` timestamp for historical auditing.

## 🛠 Setup & Installation

1.  **Repository Setup:** Copy (Fork) this repository to your GitHub account.
2.  **Permissions:** Go to `Settings > Actions > General > Workflow permissions`:
    *   Select **"Read and write permissions"** (required for automated JSON updates and Issue creation).
    *   Check **"Allow GitHub Actions to create and approve pull requests"**.
3.  **Initial Baseline:** Manually trigger the workflow under the `Actions` tab to create your initial provider list.

### 🔔 Notification Setup
To ensure you receive the Alert Emails immediately:
1. Go to your GitHub **Settings > Notifications**.
2. Under **Custom notifications**, ensure **"Include your own updates"** is enabled (to receive mails for issues created by your bot token).
3. Ensure you are **Watching** this repository for "All Activity" or at least "Issues".

## 📂 Repository Structure
*   `monitor.php`: The scraper core (PHP using DOMDocument & XPath).
*   `pims_registry.json`: The "Source of Truth" containing all detected providers.
*   `.github/workflows/daily-scrape.yml`: Automated schedule and alerting configuration.

### Data Format (pims_registry.json)
The data is stored in a structured audit-trail format:
```json
{
  "name": "Example PIMS Service",
  "provider": "Tech Solutions GmbH",
  "date": "17. October 2025",
  "status": "Verified",
  "first_detected": "2026-02-23T10:00:00+00:00",
  "last_seen": "2026-02-24T01:00:05+00:00"
}
```

*Note: If a provider is removed from the official website, the status changes to `removed` and a `removed_at` timestamp is added, while all original dates are preserved.*

## ⚖️ Legal Disclaimer & License

### 1. Official Status & Purpose
This tool is a private Open-Source initiative and is **not** an official service of the Federal Commissioner for Data Protection and Freedom of Information (BfDI). It serves as a technical aid for fulfilling the **Duty of Care (Sorgfaltspflicht)** under **Section 18 Para. 2 EinwV** by providing a machine-readable mirror of the official registry.

### 2. Limitation of Liability (No Guarantee)
- **Data Accuracy:** The data is provided "as is". While we strive for correctness, the maintainers do not guarantee the accuracy, completeness, or real-time synchronization with the official BfDI registry.
- **Responsibility:** Use of this data for automated consent decisions is at the user's own risk. Website operators remain solely responsible for ensuring their implementation complies with TDDDG, GDPR, and EinwV. 
- **No Liability:** To the extent permitted by law, the author shall not be liable for any damages (e.g., legal fines, business loss) resulting from the use of or reliance on this data.

### 3. Technical Compliance & Copyright
Automated requests are performed at a low frequency (once daily) to respect the server load of the German Federal Authorities. According to **Section 5 of the German Copyright Act (UrhG)**, official works such as registries and announcements are not subject to copyright protection.

**License:** This project is licensed under the [Mozilla Public License 2.0 (MPL 2.0)](https://www.mozilla.org/MPL/2.0/).

**Author:** d0ubIeU  
**Repository:** [https://github.com/d0ubIeU/pims-monitor](https://github.com/d0ubIeU/pims-monitor)

## 🚨 Action Plan on Alert
When you receive a change notification or "Workflow failed" email:

1. **Check the Issue:** Open the newly created GitHub Issue to see exactly which provider was added, modified, or removed.
2. **New Provider?** Visit the provider's website, download their technical documentation, and update your website's consent validation logic (Public Keys/JWS).
3. **Structural Warning?** If the Alert says "Website structure might have changed", check if the BfDI changed the HTML layout and update the XPath logic in `monitor.php`.
