# Installation und Betrieb

Diese Anleitung beschreibt die Voraussetzungen fuer den Betrieb der DISOWN-Plattform ab Version 2.0.

## 1. Voraussetzungen

- Debian/Apache mit PHP 8.x.
- MariaDB oder MySQL.
- PHP-Erweiterungen: `mysqli`, `curl`, `json`, `openssl`.
- Schreibzugriff des Webserver-Users auf benoetigte Upload-/Log-/Runtime-Pfade.
- Jamf-School-API-Zugang fuer Geraetelesen und Jamf-Unenroll.
- Apple School Manager API Account fuer Geraetelesen und MDM-Service-Zuweisung.
- Apple School Manager Device Management Service fuer den Release Broker mit aktivierter Option zum Freigeben von Geraeten.
- NanoDEP `v0.7.0` oder kompatibel fuer ADE/DEP-API-Zugriff.
- `systemd`, `curl`, `openssl`, `jq` fuer Installation und Diagnose.

## 2. Verzeichnisse

Empfohlene Struktur:

```text
/var/www/sicher.bbs-einbeck.de/disown          # PROD
/var/www/sicher.bbs-einbeck.de/disown-dev      # DEV
/srv/protected/disown                          # geschuetzte App-Daten, falls benoetigt
/srv/protected/asm-release-broker              # NanoDEP Token, Key, DB
/etc/disown                                    # Runtime-Konfiguration
```

`/srv/protected` und `/etc/disown` duerfen nicht ueber den Webserver erreichbar sein.

## 3. Anwendungskonfiguration

Die produktive Konfiguration liegt ausserhalb des Repositories. Typische Dateien:

```text
/etc/disown/db.conf
/etc/disown/jamf.conf
/etc/disown/asm-api.conf
/etc/disown/asm-release-broker.conf
```

Die Public- und Beispielkonfigurationen enthalten nur Platzhalter. Secrets niemals committen.

## 4. ASM/ADE Release Broker

Version 2.0 automatisiert die Apple-Freigabe in zwei Schritten:

1. Apple School Manager Public API weist genau ein Geraet dem Release-Broker-MDM-Dienst zu.
2. NanoDEP fuehrt fuer diesen Broker den ADE/DEP-Disown aus.

### 4.1 Release Broker in ASM anlegen

1. In Apple School Manager einen neuen Device Management Service anlegen, z. B. `asm-release-broker`.
2. Ein eigenes Zertifikat/Public Key hochladen.
3. `Allow this device management service to release devices` aktivieren.
4. Service Token herunterladen.
5. Token und Private Key unter `/srv/protected/asm-release-broker` ablegen.

Beispielhafte Dateien:

```text
/srv/protected/asm-release-broker/asm-release-broker.key
/srv/protected/asm-release-broker/asm-release-broker-public.pem
/srv/protected/asm-release-broker/asm-release-broker-token.p7m
/srv/protected/asm-release-broker/nanodep-api.key
/srv/protected/asm-release-broker/nanodep-db/
```

Die Rechte sollten restriktiv sein:

```bash
chmod 700 /srv/protected/asm-release-broker
chmod 600 /srv/protected/asm-release-broker/*.key
chmod 600 /srv/protected/asm-release-broker/nanodep-api.key
```

### 4.2 NanoDEP vorbereiten

NanoDEP kann lokal bereitgestellt werden, z. B. unter:

```text
/tmp/nanodep-test/nanodep-linux-amd64-v0.7.0
```

Der Token muss einmal in die NanoDEP-Storage importiert werden. Je nach Tokenformat kann die von ASM geladene `.p7m` direkt durch NanoDEP verarbeitet werden. Wenn ASM eine S/MIME-Datei mit Headern liefert, darf `openssl smime -decrypt` ohne `-inform DER` verwendet werden.

Nach dem Import muss ein Account-Test funktionieren:

```bash
curl -sS http://127.0.0.1:9001/version
```

### 4.3 systemd-Dienst installieren

Das Repository enthaelt den Installer:

```bash
sudo /var/www/sicher.bbs-einbeck.de/disown/tools/install-nanodep-service.sh
```

Der Dienst:

- heisst `disown-nanodep.service`
- laeuft als `www-data`
- lauscht nur auf `127.0.0.1:9001`
- nutzt Storage `/srv/protected/asm-release-broker/nanodep-db`
- liest den API-Key aus `/srv/protected/asm-release-broker/nanodep-api.key`

Pruefung:

```bash
systemctl status disown-nanodep.service --no-pager
curl -sS http://127.0.0.1:9001/version
```

Das Admin-Dashboard zeigt den Release-Broker-Status und den bekannten ADE-Token-Ablauf an. Der Ablaufwert kann aus der Runtime-Konfiguration oder einer geschuetzten Token-Datei gelesen werden; Secrets und Token-Dateien bleiben ausserhalb des Repositories.

### 4.4 Healthcheck und Logrotation

Optional, fuer PROD empfohlen:

```bash
sudo /var/www/sicher.bbs-einbeck.de/disown/tools/install-nanodep-monitoring.sh
```

Der Installer richtet ein:

- `disown-nanodep-health.timer` alle 5 Minuten.
- `/usr/local/sbin/disown-nanodep-health`.
- `/var/log/disown/nanodep-health.log`.
- `/etc/logrotate.d/disown-nanodep`.

Pruefung:

```bash
systemctl status disown-nanodep-health.timer --no-pager
tail -n 50 /var/log/disown/nanodep-health.log
journalctl -u disown-nanodep.service -n 50 --no-pager
```

### 4.5 App-Konfiguration

Vorlage:

```text
config/asm-release-broker.example.conf
```

Nach `/etc/disown/asm-release-broker.conf` kopieren und anpassen:

```bash
ASM_JAMF_MDM_SERVER_ID="..."
ASM_BROKER_MDM_SERVER_ID="..."
ASM_BROKER_DEP_BASE_URL="http://127.0.0.1:9001"
ASM_BROKER_DEP_NAME="asm-release-broker"
ASM_BROKER_DEP_API_KEY_FILE="/srv/protected/asm-release-broker/nanodep-api.key"
```

Die MDM-Server-IDs stammen aus der Apple School Manager API (`mdmServers`). Das Tool gibt keine Geraetemassen an den Broker weiter, sondern nur die konkrete Seriennummer des aktuell bearbeiteten Antrags.

## 5. Datenbank

Die Anwendung nutzt eine relationale Datenbank fuer:

- Antraege.
- Audit-Log.
- KUK-Geraete und lokale Owner-Historie.
- Klärfaelle.
- Workflow- und Mailstatus.

Vor Produktivbetrieb immer ein DB-Backup erstellen.

## 6. Tests

Empfohlene Reihenfolge:

1. DEV aufrufen und mit Demo-Daten testen.
2. Jamf-Unenroll im DEV als Dry-Run pruefen.
3. ASM/ADE im DEV als Dry-Run pruefen.
4. NanoDEP-Dienst lokal pruefen.
5. In PROD genau ein Testgeraet verwenden.
6. Kontrollieren:
   - Jamf-Unenroll erfolgreich.
   - ASM/ADE-Freigabe erfolgreich.
   - ASM zeigt `Date Removed from Organization`.
   - Audit-Log enthaelt die Schritte.
   - Mailstatus ist gruen oder bei Teilfehlern rot.

## 7. Betrieb

- Vor groesseren Aenderungen Code- und DB-Backup erstellen.
- NanoDEP-Dienst in Monitoring aufnehmen.
- Broker-Token und ASM-API-Zugang regelmaessig auf Ablauf pruefen.
- Public-Repo nur neutralisiert aktualisieren.
- `PROJECT_STATE.*` nicht in Public veroeffentlichen.

### 7.1 Admin-Sonderfreigabe fuer Defektgeraete

Der Admin-Sonderweg ist fuer einzelne defekte Geraete gedacht, die nicht ueber den normalen WebClip-Antrag laufen koennen. Der Ablauf:

1. Seriennummer unten im Adminportal pruefen.
2. Jamf-Daten kontrollieren und E-Mail-Adressen bei Bedarf korrigieren.
3. Lokalen Antrag anlegen.
4. Die Tabellenzeile wie gewohnt ueber Jamf, ASM/ADE und Mail abarbeiten.

Nur dieser Einzelfallweg ueberspringt den Schulgeraete-Blocker bewusst. Bulk und WebClip behalten den Schutz gegen schulische Leih-/Koffergeraete.

## 8. Rollback

Bei Problemen:

1. Webanwendung aus Backup zurueckspielen.
2. DB-Backup einspielen.
3. `systemctl stop disown-nanodep.service`, falls der Broker isoliert werden soll.
4. Im Audit-Log betroffene Antraege pruefen.

Bereits per ADE/DEP freigegebene Geraete koennen nicht automatisch in die Organisation zurueckgeholt werden. Sie muessen ueber Apple Configurator oder ASM-Prozess neu aufgenommen werden.
