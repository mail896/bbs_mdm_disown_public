# DISOWN 2.6: Bestandsaufnahme, Datenfluss und Fehlerpfade

Stand: 2026-10-17, ergänzt nach Incident-Nachprüfung
Status: Block 1, ausschließlich DEV
Produktionsänderungen: keine

## 1. Prüfgrundlage und Grenzen

Für diese Bestandsaufnahme wurden die beiden privaten `PROJECT_STATE`-Dateien aus PROD als verbindlicher Ausgangsstand gelesen. Die vorhandenen gleichnamigen, unversionierten Dateien in DEV bleiben unangetastet. PROD und PUBLIC wurden ausschließlich read-only geprüft.

Repository-Zustand bei Beginn:

- DEV: Branch `dev-environment`, Commit `1242727`; nur die bereits vorhandenen unversionierten Dateien `PROJECT_STATE.md` und `PROJECT_STATE.yaml`.
- PROD: Branch `main`, Commit `8e09ef8`; sauber.
- PUBLIC: Branch `main`; sauber.
- Die untersuchten Workflow-Dateien `jamf.php`, `admin.php`, `auth.php`, `settings.php`, `notify.php` und `migrate_add_new_workflow_columns.php` waren in DEV und PROD inhaltlich identisch.
- Die Mailvorlage unterscheidet sich bereits vor DISOWN 2.6 geringfügig zwischen DEV und PROD. Dieser bestehende Unterschied darf bei einer späteren Übertragung nicht still überschrieben werden.

Nicht gelesen oder ausgegeben wurden Secrets, Token, Kennwörter oder private Schlüssel. Die echte DEV-Datenbankstruktur wurde ausschließlich mit `SHOW COLUMNS` und `SHOW INDEX` geprüft.

## 2. Aktueller Datenfluss

### 2.1 Einzelverarbeitung

1. `admin.php` nimmt den POST `unenroll` nach Admin-Anmeldung, Schreibrolle und CSRF-Prüfung an.
2. Der Browser liefert sowohl Antrags-ID als auch Seriennummer. Die Seriennummer wird aktuell nicht serverseitig gegen die Seriennummer des geladenen Antrags abgeglichen.
3. `jamf_unenroll_by_serial()` in `jamf.php` sucht das aktive Gerät und seine UDID.
4. PROD sendet `POST /api/devices/{UDID}/unenroll` an Jamf School.
5. Die Funktion liefert bei exakt lesbarem `message=DeviceUnenrolled` Erfolg. Sie liefert derzeit aber auch bei jedem anderen HTTP-200-Body Erfolg.
6. `admin.php` setzt bei diesem Erfolg sofort `requests.jamf_unenrolled=1`, `jamf_unenrolled_at=NOW()` und protokolliert `JAMF_UNENROLL_SUCCESS`.
7. Der Bool `jamf_unenrolled` schaltet die ASM-Vorschau und die Ausführung über `asm_release_execute()` frei.
8. Nach erfolgreichem Broker-Lauf wird `asm_manual_done=1` gesetzt.
9. Danach bietet die UI die Abschlussmail an. Beim Mail-POST wird der für ASM/Jamf maßgebliche Zustand nicht erneut serverseitig geprüft; der Antrag wird auch bei ungültigen Maildaten oder vollständigem SMTP-Fehler als `erledigt` markiert und `mail_sent` wird auf den Fehlerwert `2` gesetzt.

### 2.2 Bulk-Verarbeitung

1. `bulk_jamf_unenroll` lädt jede ausgewählte Zeile aus der Datenbank und ruft Jamf je Gerät getrennt auf.
2. Jedes Ergebnis wird je Antrag gespeichert und auditiert. Wegen der zu großzügigen HTTP-200-Auswertung können einzelne Geräte dennoch fälschlich in die Erfolgsliste gelangen.
3. Nur die lokal als erfolgreich gewerteten IDs werden für den nächsten UI-Schritt vorausgewählt. Die folgende Aktion kann aber grundsätzlich mit einer neu übermittelten Auswahl aufgerufen werden.
4. `bulk_asm_release` prüft pro Zeile nur den alten Bool `jamf_unenrolled`, nicht eine verifizierte Post-Condition.
5. `bulk_mail_send` prüft pro Zeile die alten Booleans `jamf_unenrolled` und `asm_manual_done`.
6. Die Schleifen arbeiten zwar pro Gerät, die Zustandsaussage pro Gerät ist jedoch binär und kann „Befehl akzeptiert“ nicht von „Profil entfernt“ unterscheiden.

### 2.3 DEV-Verhalten

In DEV liefert `jamf_unenroll_by_serial()` nach dem Schutz gegen Schulgeräte unmittelbar simulierten Erfolg. Es gibt dort derzeit weder eine simulierte Befehlsantwort noch eine nachgelagerte Statusprüfung. Damit kann DEV die wichtigen Fälle „unerwarteter Body“, „offline/pending“, „weiterhin managed“ und „API-Fehler“ noch nicht realistisch abbilden.

## 3. Belegter primärer Fehlerpfad

Der Fehler liegt in `jamf_unenroll_by_serial()`:

```text
HTTP 200 + message=DeviceUnenrolled -> success=true
HTTP 200 + jeder andere oder unlesbare Body -> ebenfalls success=true
```

Direkt danach interpretiert `admin.php` `success=true` als abgeschlossene Abmeldung. Es gibt keine read-only Post-Condition-Prüfung, kein Polling und keinen Zwischenzustand. Damit werden folgende technisch verschiedene Tatsachen zusammengezogen:

- Jamf hat den API-Aufruf angenommen.
- Das Gerät hat den MDM-Befehl erhalten.
- Das lokale MDM-Profil wurde entfernt.
- Jamf führt das Gerät nachweislich als nicht mehr verwaltet.

ASM/ADE und Mail werden bereits nach der ersten, zu schwachen Aussage freigeschaltet.

## 4. Weitere sicherheits- und korrektheitsrelevante Fehlerpfade

| Bereich | Ist-Verhalten | Risiko / notwendige Korrektur |
|---|---|---|
| Jamf-Antwort | Beliebiges HTTP 200 gilt als Erfolg. | Nur erwartete, lesbare Antwort als `command_accepted` klassifizieren; niemals direkt als Abschluss. |
| Jamf-Lookup | `null` bedeutet zugleich nicht gefunden, HTTP-Fehler, Timeout oder unlesbare Antwort. | Transport-, Protokoll- und fachliche Ergebnisse getrennt und ohne Rohbody klassifizieren. |
| Post-Condition | Nicht vorhanden. | Begrenzte read-only Prüfung von Active, Trash, Command-/Task-Status und Zeitpunkten; fehlende Daten sind kein Erfolg. |
| Active/Trash | Der Admin-Sonderweg kann Trash unmittelbar als erledigten Jamf-Schritt übernehmen. | Trash allein ist kein Nachweis. Seine gespeicherten Felder können nach einem asynchronen Abschluss veraltet sein und dürfen auch nicht allein als Fehlernachweis dienen. |
| Einzel-Unenroll | Gepostete Seriennummer wird nicht gegen die Antrags-ID gebunden. | Seriennummer ausschließlich aus der gesperrt/aktuell geladenen DB-Zeile verwenden. |
| ASM automatisch | Gate ist `jamf_unenrolled=1`. | Ausschließlich `verified_unmanaged` zulassen, unmittelbar serverseitig geprüft. |
| ASM-Notfallabschluss | Admin + CSRF, aber kein Critical Mode, keine ausdrückliche Checkbox, keine Begründung; die POST-Route prüft nicht einmal den Jamf-Bool erneut. | Nur enger Override mit Critical Mode, konkreter Warnung, Bestätigung, Begründung und eigenem Audit. |
| Einzelmail | Der POST prüft Jamf-/ASM-Zustand nicht erneut. | Antrag laden/sperren und `verified_unmanaged` plus bestätigte ASM-Freigabe serverseitig verlangen. |
| Mailfehler | Auch vollständiger Versandfehler setzt den Antrag auf `erledigt`; `mail_sent = 2` bleibt sichtbar und ein erneuter Versuch ist möglich. | Zustellung und fachlichen Workflowabschluss getrennt behandeln; ein nicht mehr vorhandenes Schulkonto blockiert die Freigabe nicht. |
| Mailinhalt | Behauptet Profilentfernung und bezeichnet verbleibende Schulanzeige als wirkungslos. | Nur nach Verifikation senden; vollständiges Löschen und Neueinrichtung ohne Schnellstart, Komplettübertragung oder altes Komplett-Backup erklären. |
| Audit | Nur grobe Erfolg/Fehler-Ereignisse; Fehlertexte können Transportdetails enthalten. | Sichere Klassifikation, HTTP-Status, Zeitpunkte und getrennte Ereignisse speichern; keine Rohantworten/Secrets. |
| Wiederanmeldung | Manuelles read-only Werkzeug sucht nach ASM-freigegebenen Seriennummern, die wieder im aktiven Jamf-Bestand erscheinen. | Persistente Abweichung pro Gerät, read-only Erkennung und System-Audit ohne erfundenen menschlichen Akteur ergänzen. |
| Nebenläufigkeit | Zustandsupdate, externe Aktion und Audit sind nicht als ein kontrollierter Übergang gekapselt. | Übergänge mit aktueller DB-Zeile, zulässigem Ausgangszustand und atomarem lokalem Update absichern. |

## 5. Bestehendes Daten- und Auditmodell

### 5.1 Relevante Felder in `requests`

- Jamf: `jamf_unenrolled`, `jamf_unenrolled_at`, `jamf_unenroll_error`
- ASM/ADE: `asm_disowned`, `asm_disowned_at`, `asm_task_id`, `asm_error`, `asm_manual_done`, `asm_manual_done_at`
- Mail/Abschluss: `mail_sent`, `mail_sent_at`, `mail_sent_to`, `status`, `completed_at`, `completed_by`
- Identität/Offenheit: `serial`, generiertes/eindeutiges `open_serial`

Das Modell besitzt keinen Jamf-Zustand, keinen Zeitpunkt der Befehlsannahme, keinen letzten Prüfzeitpunkt, keine Retry-Planung, keine sichere Antwortklassifikation und keinen Marker für spätere Wiederanmeldung.

### 5.2 `request_audit_log`

Vorhanden sind `request_id`, `action`, `admin_user`, `details`, `created_at` sowie Indizes auf Antrag, Aktion, Admin und Zeitpunkt. Die Struktur ist grundsätzlich für neue Ereignistypen geeignet. Strukturierte, häufig abgefragte Workflowzustände gehören dennoch in `requests`; Audit-Details allein sind kein belastbares Zustandsmodell.

`disown_log_audit_action()` ersetzt einen leeren Akteur derzeit durch den angemeldeten Benutzer oder `unknown`. Für automatische Wiederanmeldungserkennung muss ein expliziter technischer Akteur wie `system:jamf-monitor` übergeben werden, damit kein Mensch erfunden wird.

## 6. Ziel-Zustandsmodell

Verbindliche Werte für `jamf_unenroll_state`:

| Zustand | Bedeutung | ASM/Mail erlaubt |
|---|---|---|
| `not_started` | Kein Jamf-Unenroll angefordert. | Nein |
| `command_accepted` | Jamf hat den Befehl mit erwarteter Antwort angenommen; Post-Condition noch nicht belegt. | Nein |
| `pending_device` | Befehl angenommen, Gerät aber weiter managed, offline oder Statusprüfung vorübergehend nicht beweiskräftig. | Nein |
| `verified_unmanaged` | Eine aktuelle, widerspruchsfreie Evidenzkombination belegt den Abschluss, beispielsweise ein terminal erfolgreicher Command-/Task-Status zusammen mit dem Verschwinden aus Active und einem zeitlich passenden Trash-Übergang. | Ja |
| `failed` | Befehl konnte nicht sicher angefordert werden oder Antwort war unerwartet/fehlerhaft. | Nein |
| `manual_intervention_required` | Retry-Limit erreicht oder widersprüchlicher/mehrdeutiger Zustand erfordert menschliche Prüfung. | Nein, außer engem auditiertem Override |

Wichtige Invarianten:

1. `command_accepted` ist niemals gleichbedeutend mit abgeschlossen.
2. `active` und `trash` beschreiben den Jamf-Ablageort; allein keiner der beiden Werte beweist die Entfernung des Profils.
3. `isManaged` und `lastCheckin` eines Trash-Datensatzes können einen älteren Snapshot darstellen. `isManaged=true` im Trash ist deshalb weder Abschluss- noch Fehlernachweis.
4. `verified_unmanaged` erfordert eine erfolgreiche, lesbare und widerspruchsfreie Evidenzkombination für exakt diese Seriennummer. Ein aktuelles explizites `isManaged=false` außerhalb eines bloßen Trash-Snapshots ist ein direkter Nachweis. Alternativ kann ein terminal erfolgreicher Command-/Task-Status zusammen mit Active-Abwesenheit und einem zeitlich passenden Trash-Übergang den Abschluss belegen.
5. „Nicht gefunden“, leerer Body, Timeout, HTTP-Fehler, Trash allein oder fehlender Command-Status sind kein Erfolg.
6. Nach akzeptiertem Befehl führt ein weiterhin aktives/verwaltetes Gerät zunächst zu `pending_device`; nach begrenzten erfolglosen Prüfungen zu `manual_intervention_required`. Gleiches gilt für Active-Abwesenheit plus Trash mit veralteten oder widersprüchlichen Feldern, solange kein weiterer Nachweis verfügbar ist.
7. Ein späteres `isManaged=true` nach `verified_unmanaged` ändert den historischen Abschluss nicht still, sondern setzt eine separat sichtbare Abweichung und erzeugt ein System-Audit.
8. Eine erneute Registrierung darf weder empfohlen noch automatisch ausgelöst werden. Sie ist kein regulärer Reparaturweg für einen wartenden Befehl.

### 6.1 Vorgesehene migrationssichere Felder

Die endgültige Migration wird in Block 2 festgelegt. Der aktuelle Entwurf ergänzt die bestehenden Spalten additiv:

- `jamf_unenroll_state VARCHAR(32) NOT NULL DEFAULT 'not_started'`
- `jamf_command_id VARCHAR(128) NULL`
- `jamf_command_status VARCHAR(32) NULL`
- `jamf_command_status_checked_at DATETIME NULL`
- `jamf_command_accepted_at DATETIME NULL`
- `jamf_verified_unmanaged_at DATETIME NULL`
- `jamf_last_checked_at DATETIME NULL`
- `jamf_next_check_at DATETIME NULL`
- `jamf_check_attempts INT NOT NULL DEFAULT 0`
- `jamf_last_http_status SMALLINT UNSIGNED NULL`
- `jamf_response_class VARCHAR(64) NULL`
- `jamf_observed_location VARCHAR(16) NULL` für `active`, `trash`, `missing`, `unknown`
- `jamf_observed_is_managed TINYINT(1) NULL`
- `jamf_active_absent_at DATETIME NULL`
- `jamf_trash_observed_at DATETIME NULL`
- `jamf_reenrollment_detected_at DATETIME NULL`

Die Altspalte `jamf_unenrolled` bleibt während der Umstellung lesbar, darf aber nur dann auf `1` gesetzt werden, wenn der neue Zustand `verified_unmanaged` erreicht wurde. Bestehende Zeilen mit `jamf_unenrolled=1` werden nicht rückwirkend als verifiziert erfunden. Sie benötigen einen konservativen Legacy-Zustand beziehungsweise eine erneute read-only Prüfung; die genaue Backfill-Regel wird vor Ausführung der Migration getestet.

## 7. Vorgesehene Übergänge und Post-Condition

```text
not_started
  -> command_accepted       erwartete Jamf-Antwort
  -> failed                 Transport-/HTTP-/Format-/Antwortfehler

command_accepted
  -> verified_unmanaged     aktueller Direktnachweis oder bestätigte terminale Command-Evidenz
  -> pending_device         Gerät offline/weiter active oder Evidenz noch nicht beweiskräftig
  -> manual_intervention_required bei widersprüchlicher Antwort

pending_device
  -> verified_unmanaged     spätere eindeutige Post-Condition
  -> pending_device         Retry noch zulässig
  -> manual_intervention_required nach Retry-/Zeitlimit

verified_unmanaged
  -> Abweichungsflag         spätere read-only Beobachtung isManaged=true
```

Die unmittelbare Prüfung nach dem Schreibaufruf bleibt begrenzt. Längere Wartezeit darf keinen Webrequest blockieren; sie wird über einen sicheren „Erneut prüfen“-Pfad und optional einen späteren Job fortgesetzt. Der Retry-Pfad sendet standardmäßig keinen neuen Unenroll-Befehl, sondern prüft zunächst nur die Post-Condition.

## 8. Audit-Zielbild

Vorgesehene getrennte Aktionen:

- `JAMF_UNENROLL_REQUESTED`
- `JAMF_UNENROLL_COMMAND_ACCEPTED`
- `JAMF_UNENROLL_PENDING`
- `JAMF_UNMANAGED_VERIFIED`
- `JAMF_UNENROLL_FAILED`
- `JAMF_MANUAL_INTERVENTION_REQUIRED`
- `ASM_RELEASED`
- `COMPLETION_MAIL_SENT`
- `JAMF_OVERRIDE_USED`
- `JAMF_REENROLLMENT_DETECTED`

Audit-Details dürfen enthalten: HTTP-Status, interne ungefährliche Antwortklasse, Versuchszähler und Zeitpunkte. Nicht gespeichert werden Rohantworten, Authorization-Header, API-Schlüssel, Token, Kennwörter oder unnötige Geräte-/Personendaten. Die `request_id` stellt bereits den fachlichen Bezug her; Seriennummern müssen nicht in jedem Detailtext wiederholt werden.

## 9. Abgrenzung der folgenden Blöcke

### Block 2: Speicherung

- Idempotente Migration und Backfill-Regeln.
- Zentrale Zustandskonstanten und Übergangsvalidierung.
- Abwärtskompatible Leselogik für alte Booleans.

### Block 3: Jamf und Verifikation

- Testbarer HTTP-Client beziehungsweise injizierbarer Transport.
- Strikte Klassifikation des Unenroll-Bodys.
- Separater read-only Gerätestatus für Active und Trash einschließlich `isManaged`, `lastCheckin` und Änderungszeitpunkt, ohne Trash-Snapshotfelder allein zu bewerten.
- Jamf-Command-/Task-ID und Status auswerten, soweit die API dies zuverlässig bereitstellt.
- Begrenzte unmittelbare Prüfung und sicherer Retry.

### Block 4: Workflow-Gates und Mail

- ASM und Mail ausschließlich nach serverseitig erneut geprüftem `verified_unmanaged`.
- DB-gebundene Seriennummer statt Browserwert.
- Korrigierte Abschlussmail; Versandstatus getrennt vom fachlichen Abschluss.

### Block 5: Pending-/Retry-UI und Bulk

- Pro Gerät sichtbarer Zustand und getrennte Ergebnisse.
- „Erneut prüfen“ ohne implizites erneutes Schreiben.
- ASM-/Mail-Schaltflächen für pending/failed vollständig sperren.

### Block 6: Audit, Wiederanmeldung und Override

- Read-only Abweichungserkennung mit technischem Akteur.
- Optionaler Override nur mit Admin, CSRF, aktivem Critical Mode, konkreter Warnung, expliziter Bestätigung, Begründung und Audit.
- Critical-Mode-Prüfung aus `settings.php` in gemeinsam nutzbare Sicherheitslogik verschieben.

### Block 7: Tests und Dokumentation

- Automatisierte Zustands-/Gating-/Bulk-/Override-Tests für alle vorgegebenen Fälle.
- Smoke Check und Betriebsdokumentation.
- `PROJECT_STATE.md` und `.yaml` erst gezielt aktualisieren, ohne die vorhandenen unversionierten DEV-Dateien zu überschreiben; dafür ist vorab eine bewusste Zusammenführung nötig.

## 10. Akzeptanztest für Block 1

Block 1 ist bestanden, wenn ein Reviewer anhand dieses Dokuments und des aktuellen Codes jeden der folgenden Pfade eindeutig wiederfindet:

1. Beliebiges HTTP 200 kann aktuell `jamf_unenrolled=1` auslösen.
2. Dieser Bool schaltet aktuell ASM und Mail frei.
3. Einzel- und Bulk-Pfade werden getrennt beschrieben.
4. Trash wird nicht als Post-Condition akzeptiert.
5. Alle sechs geforderten Jamf-Zustände besitzen eine eindeutige Bedeutung.
6. Fehlende/fehlerhafte API-Antworten können nicht als `verified_unmanaged` klassifiziert werden.
7. Bestehende Benutzerdateien sowie PROD/PUBLIC bleiben unverändert.
8. Der Incident-Pfad „HTTP 200 → offline → späterer Kontakt → Trash mit möglicherweise veraltetem `isManaged=true`“ bleibt ohne bestätigenden Command-/Task-Nachweis `pending_device` beziehungsweise wird nach dem Retry-Limit `manual_intervention_required`.

Nachvollziehbarer lokaler Test:

```bash
cd /var/www/sicher.bbs-einbeck.de/disown-dev
git status --short
php -l jamf.php
php -l admin.php
rg -n "HTTP 200|verified_unmanaged|pending_device|Trash|Einzelverarbeitung|Bulk-Verarbeitung" docs/DISOWN_2_6_BLOCK1_ANALYSIS.md
```

Erwartung: Neben den zwei bereits vorhandenen unversionierten `PROJECT_STATE`-Dateien ist nur dieses Block-1-Dokument neu; PROD und PUBLIC zeigen weiterhin keinen Git-Diff.
