# Zehnder ComfoAir Q350/Q450 Native – Version 1.0.4

Ein natives IP-Symcon-Modul (SymBox/Windows/Linux) zur lokalen Kommunikation mit **Zehnder ComfoAir Q350/Q450** über die **ComfoConnect LAN C**. Keine Cloud, kein Docker, kein separater Python-Dienst.

> **Freigabestatus:** GitHub-Release-Kandidat 1.0.4. Die Sensordaten wurden auf einer Q350-Anlage im Projekt erfolgreich ausgelesen. Steuerbefehle und Q450-Kompatibilität sind noch nicht vollständig mit echter Hardware validiert. Der Release ist **kein nachgewiesen produktionsreifer Stable-Release**.

## Voraussetzungen

- IP-Symcon **7.1 oder neuer** (HTML-SDK für Kachel)
- Zehnder ComfoAir Q350 oder Q450 mit ComfoConnect LAN C im lokalen Netzwerk
- Netzwerkzugriff auf das Gateway (TCP/UDP Port **56747**)
- Ein öffentliches GitHub-Repository für die Installation per Modulverwaltung

## Installation in Symcon

1. In **Kerninstanzen → Modules/Modulverwaltung** ein separates Repository mit dem entpackten Inhalt dieses ZIPs hinzufügen.
2. Unter **Instanz hinzufügen → Zehnder** `Zehnder ComfoAir Q350/Q450 Native` auswählen.
3. Gateway-IP eintragen, Port **56747** bestätigen, Gateway suchen, Registrierung/Verbindung testen.
4. Das Abfrageintervall konfigurieren (0 = aus; positiver Wert mind. 5 Sekunden). Das Intervall ist eine Timer-Auslösezeit, keine garantierte Messwertfrequenz: der zeitlich begrenzte TCP-Abruf kann länger dauern.
5. Kachel im Visualisierungs-Editor platzieren, unter `Kachel-Design` Hell oder Dunkel und unter `Kachelansicht` Standard oder Mini wählen.
6. **Steuerbefehle freigeben** erst nach erfolgreicher Sensor- und Rückmeldungsprüfung aktivieren.

## Funktionen

- Temperaturen Außen-/Zu-/Ab-/Fortluft
- Zu-/Abluftvolumenstrom, Leistungsaufnahme, Filter-Restlaufzeit
- Automatische zyklische Sensorabfrage mit Diagnosedaten
- Lüftungsstufen 0–3, Automatik/Manuell, Boost, Bypass, Temperaturprofil (Steuerung: hardwareseitig noch zu verifizieren)
- HTML-Kachel in hellem und dunklem Design

**Wichtig:** Bedienvariablen zeigen zuletzt *angeforderte*, nicht garantiert bestätigte Istzustände. Bei Problemen Steuerbefehle deaktivieren und Anlage am Originalbediengerät bedienen.

## Neuinstallation und bestehende Instanzen

Diese Ausgabe hat eigene Bibliotheks- und Modul-GUIDs. Einstellungen und Verknüpfungen bestehender Instanzen müssen bei einem Wechsel manuell übertragen werden.

## Veröffentlichung

Eine GitHub-Release-Erstellung oder Store-Einreichung erfolgt nicht automatisch beim Upload. Siehe `docs/RELEASE_CHECKLIST.md` und `docs/STORE.md`.

## Rechtliches

Inoffizielles Community-Projekt, keine Zugehörigkeit zu oder Freigabe durch Zehnder. Das eingebettete Anlagenmotiv ist ein generiertes Visualisierungsbild und kein offizielles Produktfoto. **Die Lizenzfreigabe ist vor einer öffentlichen Open-Source-Veröffentlichung durch den Rechteinhaber zu klären.** Siehe `docs/LICENSING.md`.

## Responsive Kachelvisualisierung

Die überarbeitete HTML-Kachel passt Messwerte und Steuerung an kleine Kacheln,
Smartphones, Tablets und Desktop an. Details zu Breakpoints, Austausch und Tests:
[Responsive Kachel](docs/RESPONSIVE_TILE.md).

Die **Mini-Kachel** ist bis zu 272 px breit. Außenluft, Zuluft und Abluft bleiben
sichtbar. Die übrigen Messwerte und alle Steueroptionen liegen unter
**Details & Steuerung**.
