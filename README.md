# BBS Einbeck iPad-Management

[English version](README.en.md)

![Adminportal](images/Demo-iPad-Managemnt-BBS-01.png)

Webanwendung fuer die Rueckgabe und Freigabe ehemaliger Schueler-iPads der BBS Einbeck. DISOWN 2.6 prüft Jamf kurz nach; ein korrekt angenommener, noch wartender Befehl blockiert die autorisierte Apple-Freigabe nicht. Mail bleibt manuell. Ein read-only Hintergrundjob zeigt verbleibende Jamf-Nacharbeit an.

Aktuelle Regeln, Migration, Cron und Tests: [Apple-Freigabe und Jamf-Nacharbeit](docs/DISOWN_2_6_ASYNC_RELEASE.md).

## Stand

- Version: `2.6`
- Datum: `20. Oktober 2026`
- Produktionspfad: `/var/www/sicher.bbs-einbeck.de/disown`
- Entwicklungszweig: `/var/www/sicher.bbs-einbeck.de/disown-dev`
- Public-Demo: neutralisierte Variante ohne Zugangsdaten und ohne lokale Projekt-State-Dateien

## Funktionsumfang

- Self-Service-Webclip fuer Rueckgabeantraege mit verpflichtender privater E-Mail-Adresse (Formular- und Serverprüfung).
- Schutzseite fuer schulische Leih-/Koffergeraete, damit diese nicht versehentlich freigegeben werden.
- Adminportal mit beruhigter Auto-Suche, Statusfiltern, Bulk-Verarbeitung, Kennzahlen, Jahresverlauf und Jamf-Lizenztrend.
- Admin-Sonderfreigabe fuer defekte Geraete per Seriennummer, mit bewusster Einzelfall-Pruefung und ohne Bulk/WebClip-Bypass.
- Release-Broker-Status inklusive ADE-Token-Ablauf im Admin-Dashboard.
- Zustandsbasierter Jamf-Unenroll mit strenger Antwortprüfung, read-only Post-Condition, Pending-/Retry-Zustand und Wiederanmeldungserkennung.
- Automatische ASM/ADE-Freigabe per Apple School Manager Public API und lokalem NanoDEP Release Broker.
- Abschlussmail an schulische und private E-Mail-Adresse; der Prozess wird auch bei Teilfehlern abgeschlossen und der Mailstatus wird rot markiert.
- Die im Adminportal bearbeitbare Abschlussmail-Vorlage liegt deploymentfest und getrennt für DEV/PROD in `app_settings`; die Repository-Datei bleibt nur der Standard-Fallback.
- Klärfall-Notizen zu einzelnen Geraeten, lokal in der Datenbank.
- KUK-Geraeteuebersicht fuer Kolleginnen-/Kollegen-iPads aus Jamf School.
- ADE-Aufnahmen mit Abgleich zwischen ASM/ADE und Jamf School.
- Audit-Log mit Dashboard, Export und protokollierten Bulk-Seriennummernlisten.
- DEV-Modus mit Demo-Daten und Dry-Run fuer Jamf, ASM/ADE und Mail.

## Screenshots

![Adminportal](images/disown-admin-portal.png)

![Adminportal Klärfälle](images/disown-admin-cases.png)

![ADE-Aufnahmen](images/disown-ade.png)

![KUK-Geraete](images/disown-kuk.png)

![Audit-Log](images/disown-audit-log.png)

![Einstellungen und Systemwerkzeuge](images/disown-settings.png)

## Mobile Screenshots

<p>
  <img src="images/disown-admin-mobile.png" alt="Adminportal mobil" width="240">
  <img src="images/disown-ade-mobile.png" alt="ADE-Aufnahmen mobil" width="240">
  <img src="images/disown-kuk-mobile.png" alt="KUK-Geraete mobil" width="240">
  <img src="images/disown-settings-mobile.png" alt="Einstellungen mobil" width="240">
</p>

## Standardablauf

1. Schuelerin oder Schueler stellt den Antrag ueber den Webclip und gibt eine erreichbare private E-Mail-Adresse an. Der Achtung-Hinweis fordert ausdrücklich dazu auf, die Anweisungen der nachfolgenden Freigabe-E-Mail zu befolgen. Nur auf dieser Antragsseite entfällt die Autoren-/Versionsfußzeile; die Adminportale behalten sie.
2. Admin klickt `Freigeben`: Jamf-Unenroll und bis zu vier kurze read-only Nachprüfungen.
3. Bei Verifikation oder korrekt angenommenem Befehl mit eindeutiger Zuordnung folgt die autorisierte Apple-/Broker-Freigabe genau dieses Geräts; Gerätestille allein blockiert nicht.
4. Der lokale NanoDEP Release Broker fuehrt den ADE/DEP-Disown aus.
5. Das System kontrolliert, dass das Geraet danach fuer den Broker nicht mehr erreichbar ist.
6. Admin prüft die passende Mailvorschau und bestätigt den Versand ausdrücklich manuell.
7. Der Antrag ist erledigt; Teilerfolge und Fehler stehen im Audit-Log.

Die alte Spalte `asm_manual_done` bleibt aus Kompatibilitaetsgruenden bestehen, bedeutet seit Version 2.0 aber: ASM/ADE-Freigabe wurde erledigt, in der Regel automatisch.

## Admin-Sonderfreigabe

Der seltene Sonderweg fuer defekte Geraete liegt im Adminportal unten vor dem Footer. Admins pruefen zuerst die Seriennummer gegen Jamf, kontrollieren Name und E-Mail-Adressen und legen danach nur einen lokalen Antrag an. Jamf-Unenroll, ASM/ADE-Freigabe und Mail laufen anschliessend ueber die normale Tabellenzeile.

Nur dieser Admin-Sonderweg darf den Schulgeraete-Blocker bewusst ueberspringen. WebClip, normale Antraege und Bulk bleiben gegen schulische Leih-/Koffergeraete geschuetzt.

## Bulk-Workflow

Der Bulk-Ablauf bewertet jedes Gerät getrennt:

1. Antraege markieren.
2. `Auswahl freigeben`: kurze Jamf-Nachprüfung und danach Apple-Freigabe für verifizierte oder korrekt angenommene Fälle.
3. Echte Fehler und unklare Zuordnungen bleiben pro Gerät gesperrt; bloß wartende Geräte nicht. Der Hintergrundjob meldet nach bestätigter Apple-Freigabe verbleibenden manuellen Trash-Bedarf.
4. `ASM/ADE fuer Auswahl` bleibt nur als gezielte Wiederholung für zuvor fehlgeschlagene ASM-Schritte verfügbar.
5. `Mail fuer Auswahl` sendet die vorbereiteten Abschlussmails für erfolgreich abgeschlossene Geräte.

Die Auswahl bleibt bis zum Mail-Schritt erhalten. Erst nach dem Mailversand wird automatisch abgewählt. Das verhindert, dass Admins nach dem Jamf-Schritt Seriennummern oder Empfaenger vergessen.

## Sicherheitsmodell in Version 2.6

- HTTP `200` allein genügt nicht: Nur die erwartete, gültige Jamf-Antwort bestätigt die Befehlsannahme.
- Ein aktiver Datensatz mit `isManaged=false` gilt als direkt verifiziert.
- Ein Wechsel aus dem aktiven Bestand in den Trash gilt nicht allein als Erfolg, weil `lastCheckin` und `isManaged` dort veraltet sein koennen.
- Trash allein bestätigt keine Profilentfernung. Frische korrelierte Evidenz kann Jamf verifizieren; bloßes Warten bleibt `pending`, unabhängig von der Klickzahl. Bestandsbereinigung wird separat erfasst.
- Bis zu vier kurze read-only Nachprüfungen direkt nach der Befehlsannahme erkennen schnelle asynchrone Abschlüsse ohne zusätzlichen Admin-Klick und ohne zweiten Unenroll-Befehl.
- Erneute Registrierung ist weder automatischer noch empfohlener Reparaturweg.
- ASM/ADE darf nach Verifikation oder korrekt angenommener Abmeldung mit eindeutiger Zuordnung fortgesetzt werden. Echte Fehler bleiben pro Gerät gesperrt; Mail bleibt manuell.
- Fehler beim Versand an nicht mehr vorhandene schulische Adressen bleiben sichtbar, stoppen den bereits abgeschlossenen Geraeteprozess aber nicht.

Die technische Entscheidungslogik und der Incident-Testfall sind in [docs/DISOWN_2_6_BLOCK3_JAMF_VERIFICATION.md](docs/DISOWN_2_6_BLOCK3_JAMF_VERIFICATION.md) beschrieben.

## ASM/ADE Release Broker

Seit Version 2.0 nutzt DISOWN zwei getrennte Apple-Wege:

- Apple School Manager Public API: weist ein einzelnes Geraet dem Release-Broker-MDM-Dienst zu.
- Apple ADE/DEP API ueber NanoDEP: fuehrt danach `disown` fuer dieses Geraet aus.

Wichtig:

- Die Freigabe aus der Apple-Organisation ist irreversibel.
- Der Broker lauscht nur lokal auf `127.0.0.1:9001`.
- Secrets liegen ausserhalb des Webroots unter `/srv/disown-protected/asm-release-broker` und `/etc/disown`.
- DEV fuehrt nur Dry-Runs aus.

Die Beispielkonfiguration liegt unter:

- `config/asm-release-broker.example.conf`

Der Installationshelfer fuer den lokalen NanoDEP-Dienst liegt unter:

- `tools/install-nanodep-service.sh`

## Installation und Betrieb

Siehe:

- [Deutsch: docs/INSTALL.md](docs/INSTALL.md)
- [English: docs/INSTALL.en.md](docs/INSTALL.en.md)
- [Secrets und Runtime-Dateien: docs/SECRETS.md](docs/SECRETS.md)

Kurzfassung:

1. PHP/MySQL/Apache bereitstellen.
2. Datenbank und Tabellen anlegen.
3. Jamf-School-API konfigurieren.
4. Apple School Manager API Account konfigurieren.
5. Release Broker in ASM anlegen, Release erlauben und Token fuer NanoDEP importieren.
6. `tools/install-nanodep-service.sh` ausfuehren.
7. DEV Dry-Run und ein einzelnes PROD-Testgeraet pruefen.

### Upgrade von 2.4 auf 2.6

Nach Code- und Datenbank-Backup die idempotente Zustandsmigration einmal ausfuehren:

```bash
php migrations/20261017_add_jamf_unenroll_state.php
php migrations/20261020_add_jamf_cleanup.php
```

Danach den read-only Wiederanmeldungs-Check als Cronjob einrichten und die in [docs/RELEASE.md](docs/RELEASE.md) aufgefuehrten 2.6-Tests in DEV ausfuehren. Die Migration klassifiziert alte Zeilen konservativ und darf zur Kontrolle erneut gestartet werden.

## Wichtige Dateien

- `index.php` - Webclip und Antragserfassung.
- `admin.php` - Adminportal, Bulk-Workflow, Mail, Dashboard.
- `asm_release.php` - ASM/ADE-Freigabe ueber Release Broker.
- `jamf.php` - Jamf-School-Anbindung und Geraeteschutz.
- `ade.php` - ADE-Aufnahmen.
- `kuk/index.php` - KUK-Geraete.
- `audit_log.php` - Audit-Log und Auswertungen.
- `settings.php` - Betriebs-, Mail-, Job-, Sicherheits- und System-Einstellungen.
- `site.php` - zentrale Versions-, Release- und Footer-Metadaten fuer alle Seiten.
- `config/asm-release-broker.example.conf` - Beispiel fuer Broker-Konfiguration.
- `tools/install-nanodep-service.sh` - systemd-Installation des NanoDEP-Servers.
- `tools/install-nanodep-monitoring.sh` - Healthcheck-Timer und Logrotation fuer NanoDEP.
- `tools/install-settings-root-helper.sh` - Installation des eng begrenzten Root-Helpers fuer Critical Mode.
- `docs/SECRETS.md` - Ablageplan fuer Runtime-Secrets, Broker-Token und Logs.
- `docs/RELEASE.md` - Release-Checkliste mit Backup-, Smoke- und Public-Pruefung.
- `scripts/smoke_check.php` - read-only Smoke-Check fuer Deployments.

## Sicherheit

- Keine Secrets im Repository.
- Runtime-Konfiguration unter `/etc/disown`.
- Geschuetzte Token/Keys unter `/srv/disown-protected`.
- Public-Repository ist neutralisiert.
- Schreibende Aktionen sind Admin-only.
- KUK-Seite ist read-only gegen Jamf.
- Alle kritischen Aktionen werden auditiert.
- Critical Mode ist zeitlich begrenzt; der Root-Helper erlaubt nur fest definierte Kommandos.

## Historie

- `2.6`: Verifizierbarer Jamf-Zustandsautomat mit Pending-/Retry-/Manual-Status, asynchronem Abschluss, sicherem Bulk-Gating, automatischer ASM/ADE-Fortsetzung nur nach Verifikation, nicht-blockierendem Mailfehler und read-only Wiederanmeldungserkennung.
- `2.4`: Einstellungsportal mit E-Mail-Push-, SMTP-, Job-, Sicherheits- und Systembereichen; Critical Mode schützt Root-Helper-Aktionen mit separatem Kennwort, kurzer Laufzeit und Audit-Log.
- `2.4` Nachpflege: Vollständig unbearbeitete Fehlanträge können ausschließlich unter Einstellungen → System → Werkzeuge mit Critical Mode, Antrag-ID und Bestätigung gelöscht werden; die Löschung bleibt im Audit-Log dokumentiert.
- `2.4` Nachpflege 2026-08-21: Reine E-Mail-Push-Ereignisse gelten bei der Antragslöschung nicht mehr als Bearbeitung. Workflowstatus, Fehler, Klärfälle und alle nicht ausdrücklich tolerierten Auditaktionen sperren die Löschung weiterhin.
- `2.4` Nachpflege: Backup-Status und neue Code-/DB-Sicherungen sind über Critical Mode und den fest begrenzten Root-Helper im Einstellungsportal verfügbar.
- `2.3`: Schaltbarer E-Mail-Push fuer neue WebClip-Antraege; Adminportal speichert den Ein/Aus-Zustand in `app_settings`, Empfaenger bleiben in `/etc/disown/notify.conf`.
- `2.2`: Visuelles Release mit polierter Admin-Tabelle, ausgewaehlten Zeilen, deutlicheren Aktionen und vereinheitlichtem Look fuer Admin, ADE-Aufnahmen, Audit-Log und KUK.
- `2.1`: UI-Struktur modernisiert; Admin-, ADE-, Audit- und KUK-Styles/Skripte liegen in `assets/`, Suche reagiert ruhiger und behaelt nach Auto-Aktualisierung den Fokus.
- `2.0`: automatische ASM/ADE-Freigabe ueber Release Broker, NanoDEP-Dienst, verbesserter Bulk-Workflow, Mail an beide Adressen mit Teilerfolg, neue Doku und Screenshots.
- `2.0` Nachpflege 2026-07-10: Admin-Sonderfreigabe fuer defekte Geraete, ADE-Token-Hinweis, Mail-Dialog-Korrektur und Wartehinweis fuer Einzelaktionen.
- `1.9`: UI-Vereinheitlichung, Monatsuebersicht, Klärfaelle, Jamf-Lizenzindikator.
- `1.8`: KUK-Geraete, ADE-Aufnahmen, Audit-Dashboard.
