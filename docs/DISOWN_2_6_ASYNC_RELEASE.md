# DISOWN 2.6: Apple-Freigabe und Jamf-Nacharbeit

Stand: 20. Oktober 2026. Diese Regeln ersetzen die frühere harte Kopplung aus Block 1/3.

## Ablauf und Sicherheitsgrenzen

1. Berechtigter Admin klickt **Freigeben**. Seriennummernbindung, CSRF-, Schreibrechte- und Schulgeräteprüfungen bleiben bestehen.
2. Genau ein Jamf-Unenroll-Befehl: Nur HTTP 200, lesbares JSON und exakt `message=DeviceUnenrolled` gelten als angenommen. Explizit fehlgeschlagene/abgebrochene Commands bleiben Fehler.
3. Wie bisher bis zu vier kurze read-only Nachprüfungen mit 750 ms Abstand (zuzüglich Netzwerklaufzeit). Bei schneller Bestätigung stoppt die Schleife; kein zweiter Unenroll-Befehl.
4. `verified_unmanaged` **oder** eine dauerhaft gespeicherte gültige Befehlsannahme mit eindeutig gelesener Zuordnung erlauben die bereits vom Admin autorisierte Apple-Freigabe. Bloße Gerätestille blockiert nicht. API-Fehler, mehrdeutige Seriennummern, Active/Trash-Widersprüche und Legacy-Bools ohne Annahmenachweis geben Apple nicht automatisch frei.
5. Die Apple-/Broker-Prüfungen bleiben pro gespeicherter Seriennummer bestehen. Fehlende Broker-Nachkontrolle ist ein Fehler; gewöhnliche Dry-runs setzen keinen lokalen ASM-Erfolg. Bereits tatsächlich bei Apple freigegebene Geräte können sicher erneut bestätigt werden.
6. **Mail bleibt manuell:** Vorschau, Empfänger/Text prüfen, **Mail senden**. Bulk-Mail erfordert ebenfalls die ausdrückliche Mail-Aktion. Mailfehler bleiben sichtbar, halten aber den fachlichen Abschluss nicht offen.
7. Jamf-Erfolg wird nie erfunden: `jamf_unenrolled=1` und `jamf_verified_unmanaged_at` werden weiterhin ausschließlich bei belegter Verifikation gesetzt. Hauptabschluss, Profilbestätigung und Bestandsbereinigung sind getrennt.

Bereits angenommene, früher durch das Klicklimit als manuell eingestufte Anträge können über **Freigabe fortsetzen** weiterlaufen. Weder Deployment noch Hintergrundjob lösen dafür Apple-Freigaben aus. Erneute Registrierung ist kein Reparaturweg.

## Mail und GUI

Es gibt genau eine Standard-Mailvorlage für alle Geräte, unabhängig vom Jamf-Status. Sie enthält den vom Betreiber vorgegebenen deutschen Wortlaut, Anrede, Gerät und Seriennummer als Variablen sowie die englische Fassung. Sie beschreibt die Jamf-Abmeldung als veranlasst und die Apple-Freigabe als abgeschlossen, ohne eine Profilentfernung zu behaupten.

Der eine Vorlageneditor speichert in `app_settings` unter dem jeweiligen Deployment-Schlüssel `release_mail_template_dev` bzw. `release_mail_template_prod`. `templates/mail_release.txt` ist der einzige Datei-Fallback; der eingebaute Notfalltext entspricht ihm. Einzelvorschau und Bulk verwenden dieselbe Standardvorlage. Die frühere zweite Vorlage, deren Statusumschaltung und die zusätzlichen Einstellungsschlüssel wurden entfernt. Versand bleibt ausdrücklich manuell.

**Jamf-Nacharbeit** zeigt Seriennummern und konkrete Handlungs-/Prüffälle unabhängig vom Offen-/Erledigt-Filter. Die normale Karenzzeit wird nicht als Nacharbeit gezählt. Manuelles Trashen wird automatisch erkannt; kein weiterer Bestätigungsklick. Klärfall-Notizen bleiben dezent über die Seriennummer erreichbar.

## Hintergrundjob

`scripts/check_jamf_cleanup.php` liest Active und Trash und aktualisiert nur lokale Beobachtungen/Audits. **Kein Unenroll, kein Trash/Delete, kein Apple-Release, keine Mail.** Umfang: bestätigte Apple-Freigaben mit gültiger Jamf-Befehlsannahme oder Verifikation, einschließlich erledigter Anträge und Mailfehler. Historische Importe werden ausgeschlossen.

| Beobachtung | Nacharbeitsstatus |
| --- | --- |
| Noch aktiv, weniger als 15 Minuten seit Apple-Freigabe | `waiting` – kurze Karenzzeit |
| Danach weiterhin aktiv | `manual_trash_required` – in Jamf manuell trashen |
| Active autoritativ leer, exakte Seriennummer im Trash | `trashed` – Bestandsbereinigung erledigt |
| Lookup-Ausfall oder mehrdeutige Seriennummer | `lookup_error` – kein Erfolg und keine Trash-Empfehlung |
| Active und Trash gleichzeitig | `conflict` – manuell prüfen |
| In beiden erfolgreichen Abfragen nicht gefunden | `not_found` – separat prüfen |

**Trash beweist keine Profilentfernung.** Alte Trash-Felder (`isManaged=true`, alter `lastCheckin`) verhindern aber nicht die Bestandsbereinigung. Frische korrelierte Evidenz kann später eine echte Verifikation liefern. Ein alter Check-in beweist keinen Reset; Trash ist keine garantierte dauerhafte Sperre gegen Backup-Wiederherstellung/Registrierung.

Der Job läuft alle fünf Minuten. Wartende und manuell zu trashende Geräte werden nach fünf Minuten wieder geprüft, API-Fehler nach 15 Minuten, weitere Prüffälle nach 60 Minuten, bereinigte Geräte täglich. Statuswechsel werden auditiert; unveränderte Polls erzeugen keine neuen Audit-Einträge. Der vorhandene Wiederanmeldungsjob bleibt zusätzlich bestehen.

Ein nicht blockierender Prozess-Lock verhindert Überschneidungen. Standardlimit 25 Geräte; 45-Sekunden-Startbudget und zwei GETs mit je höchstens fünf Sekunden begrenzen die Netzwerklaufzeit auf rund 55 Sekunden inklusive laufendem Gerät. Lokale Datenbanksperren haben ein kurzes separates Wartezeitlimit.

## Installation / Upgrade

Zuerst DEV testen; vor PROD Code und Datenbank sichern. Migration ist additiv und wiederholbar, ohne Antragsfreigaben oder Erfolgs-Backfill:

```sh
php migrations/20261017_add_jamf_unenroll_state.php
php migrations/20261020_add_jamf_cleanup.php
php scripts/check_jamf_cleanup.php --dry-run --limit=25
```

Danach unter dem passenden Runtime-Benutzer installieren (Benutzer-Crontab; `/etc/cron.d` benötigt zusätzlich die Benutzerspalte):

```cron
*/5 * * * * /usr/bin/php /path/to/disown/scripts/check_jamf_cleanup.php --limit=25 >> /path/to/logs/jamf-cleanup.log 2>&1
```

DEV verwendet DEV-Pfad/Datenbank; FAKE0-Demos werden lokal simuliert. Es gibt keine externen DEV-Schreibzugriffe. `--dry-run` liest nur und verändert auch keine lokale Datenbank.

## Tests und Rollback

```sh
php tests/test_disown_26_block2.php
php tests/test_disown_26_block3.php
php tests/test_disown_26_workflow_store.php
php tests/test_disown_async_cleanup.php
php tests/test_admin_case_ui.php
node tests/test_admin_async_ui.js
php scripts/smoke_check.php
```

Tests verwenden verbindungsgebundene temporäre Tabellen und injizierte Jamf-/Apple-Antworten. Abgedeckt: schnelle Bestätigung ohne zweiten POST, Offline-Befehlsannahme, Apple-Fehler, manuelle Mail, Bulk-Isolation, Zeitpunkte, späterer Trash mit veralteten Feldern, echte spätere Verifikation, Lookup-Ausfall und Seriennummernwechsel.

Rollback: neuen Cron deaktivieren und gesicherten Code zurückspielen. Additive Spalten können bleiben. Tatsächliche Apple-Freigaben sind dadurch nicht rückgängig gemacht und dürfen nicht durch einen DB-Rollback verborgen werden. Mailvorlagen/Audits erhalten.

## Herstellergrundlagen

- [Jamf: Offline-Geräte und direkte Apple-Freigabe](https://support.jamf.com/en/articles/14808823-troubleshooting-disown-devices-in-jamf-school)
- [Jamf: Trash, wiederkehrende Lizenz und verbleibendes Profil](https://learn.jamf.com/r/en-US/jamf-school-documentation/Removing_a_License_from_a_Single_Device)
- [Apple: Geräte aus Apple School Manager freigeben](https://support.apple.com/guide/apple-school-manager/release-devices-axmec4d28461/web)
- [Apple: Wiederherstellung verwalteter Geräte](https://support.apple.com/en-euro/guide/deployment/depd44f04xc4/1/web/1.0)
