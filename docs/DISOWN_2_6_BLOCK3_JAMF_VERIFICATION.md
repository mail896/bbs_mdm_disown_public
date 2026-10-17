# DISOWN 2.6: Jamf-Befehlsannahme und Post-Condition

Stand: 2026-10-17
Status: In Version 2.6 implementiert und veröffentlicht

## Verifizierte Jamf-Grundlage

Die offizielle Jamf-School-Dokumentation beschreibt „Unmanage“ als asynchronen Vorgang: Das Gerät wird als unmanaged markiert, das MDM-Profil aber erst beim nächsten Check-in automatisch entfernt.

- https://learn.jamf.com/r/en-US/jamf-school-documentation/Unenrolling_a_Device_Using_Jamf_School
- https://learn.jamf.com/r/en-US/jamf-school-documentation/Quick_Action_Commands_Reference

Der Task Manager verarbeitet dokumentiert nur bestimmte Hintergrundaktionen. Ein öffentlicher, dokumentierter Jamf-School-API-Endpunkt zum Status genau des hier verwendeten Unmanage-Commands konnte nicht belegt werden. DISOWN erfindet deshalb keinen Task-Endpunkt. Eine von Jamf gelieferte Command-/Task-ID oder ein Status kann sicher gespeichert werden; ohne dokumentierte Statusabfrage bleibt die Post-Condition maßgeblich.

## Implementierte Regeln

1. Nur HTTP 200 mit lesbarem JSON und exakt `message=DeviceUnenrolled` gilt als `command_accepted`.
2. Ein anderer oder unlesbarer HTTP-200-Body ist `failed`.
3. Transportfehler und andere HTTP-Status werden ohne Rohbody und ohne Transportdetails klassifiziert.
4. Active und Trash werden getrennt read-only abgefragt. Ein API-Fehler bleibt von einer autoritativen leeren Liste unterscheidbar.
5. Ein aktuelles `isManaged=false` beim exakten Active-Datensatz kann direkt verifizieren.
6. Trash allein verifiziert nichts. `isManaged` und `lastCheckin` aus Trash bleiben nur Diagnosewerte.
7. Active-Abwesenheit, zeitlich passender Trash-Übergang und ein separat belegter terminal erfolgreicher Command-Status können gemeinsam verifizieren.
8. Ohne diesen Nachweis bleibt der Vorgang `pending_device`; nach fünf ergebnislosen Prüfungen folgt `manual_intervention_required`.
9. „Erneut prüfen“ ist read-only und sendet keinen zweiten Unenroll-Befehl.
10. Nur `verified_unmanaged` setzt den kompatiblen Alt-Bool `jamf_unenrolled=1`.
11. Auch `manual_intervention_required` bleibt read-only erneut prüfbar. Ein späterer eindeutiger `isManaged=false`-Nachweis kann den Vorgang fortsetzen; ohne Nachweis bleibt er manuell gesperrt.
12. Ein stündlicher read-only Lauf kann abgeschlossene Seriennummern erneut prüfen. Wird ein exakter Active-Datensatz später wieder mit `isManaged=true` beobachtet, setzt DISOWN ausschließlich das persistente Abweichungsflag `jamf_reenrollment_detected_at` und schreibt `JAMF_REENROLLMENT_DETECTED` mit dem technischen Akteur `system:jamf-readonly-check`. Es erfolgt keine automatische Löschung, Freigabe oder Wiederherstellung.

## DEV-Sicherheit

Ohne injizierten Testtransport führt DEV keinen Jamf-Unenroll-Schreibzugriff aus. Es simuliert ausschließlich eine angenommene, ausstehende Anforderung. Die automatisierten Kernprüfungen injizieren sämtliche HTTP-Antworten und verwenden für Persistenztests nur verbindungsgebundene temporäre Tabellen. Die zusätzlichen GUI-Demo-Fixtures werden nur geladen, wenn der Checkout eindeutig als DEV erkannt wird.

## Automatisierter Test

```bash
cd /path/to/disown-dev
php tests/test_disown_26_block2.php
php tests/test_disown_26_block3.php
php tests/test_disown_26_workflow_store.php
php scripts/smoke_check.php
```

Abgedeckt sind insbesondere:

- exaktes `DeviceUnenrolled` plus `isManaged=false`;
- unerwarteter und unlesbarer HTTP-200-Body;
- Timeout und HTTP-Fehler;
- Gerät bleibt aktiv/managed;
- bereits unmanaged;
- bereits im Trash ohne Nachweis;
- Incident `HTTP 200 → offline → späterer Kontakt → Trash mit stale isManaged=true`;
- derselbe Incident mit zusätzlichem terminalen Command-Nachweis;
- Retry-Limit und manuelle Verifizierung;
- Pending-Persistenz setzt weder Erfolgs-Bool noch Fehlertext;
- Verifikation setzt als einziger Zustand den Erfolgs-Bool.
- unzulässige Rückwärtsübergänge aus `verified_unmanaged` werden abgewiesen;
- spätere aktive Verwaltung wird separat als Wiederanmeldung klassifiziert.
