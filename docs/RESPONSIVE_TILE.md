# Responsive HTML-Kacheln

Basis: MCVnet/SYMCON-ComfoAir, Commit `f4d5fe4` (v1.0.0 in library.json).
Bibliotheks- und Modul-ID, PHP-Code, Sensorzuordnung, Steuerbefehle bleiben unverändert.
Ab Version 1.0.2 ist die Mini-Kachel über die Einstellung `Kachelansicht` wählbar.
Für das Update das vollständige Modulpaket verwenden.

## Verhalten

- Ab 900 px: Messwerte und Gerätebild nebeneinander, drei Steuerungsspalten.
- 480–899 px: Messwerte ohne Gerätebild, zwei Steuerungsspalten.
- Unter 480 px: zwei Messwertspalten, zwei Statusspalten, eine Steuerungsspalte.
- Unter 280 px: Messwerte untereinander.
- Unter 480 px Höhe: Gerätebild entfällt auch auf breiten Kacheln.
- Die Breakpoints beziehen sich auf den Viewport der HTML-SDK-Kachel.
  Einbettung auf einer breiten Seite wird im Browsertest mit geprüft.
- Kleine Kacheln scrollen vertikal. Alle Messwerte und Bedienelemente bleiben
  erreichbar; es gibt keine feste Inhaltshöhe und kein Abschneiden durch
  `overflow:hidden`. Die Steuerung kann per Tastatur oder Touch eingeklappt werden.
- Hell-/Dunkelmodus weiterhin über die vorhandene Symcon-Einstellung `TileTheme`.
  Die eingebetteten Bilder bleiben erhalten; keine externen Assets oder Bibliotheken.
- Bedienelemente mindestens 44 px hoch, sichtbarer Tastaturfokus, Status-/Fehlerausgabe
  mit ARIA-Rollen. Beschriftungen und lange Fehlertexte dürfen umbrechen.

Zusätzlich korrigiert: Die Buttons „Messwerte lesen“ und „Betriebszustände lesen“
werden nach einem abgeschlossenen oder fehlgeschlagenen Aufruf wieder freigegeben.

## Prüfung

```sh
python3 tests/validate.py
python3 tests/test_tile.py
php -l ZehnderQ350/module.php
```

Der Browsertest benötigt Python 3 und Google Chrome/Chromium. Optional legt
`CHROME_BIN` den Browserpfad fest. Keine zusätzlichen Python-/npm-Pakete erforderlich.
GitHub Actions führt Strukturprüfung, PHP-Syntaxprüfung und Browserprüfung aus.

Lokal bestanden: Strukturprüfung und 24 Browser-Layoutfälle in Chrome
(180×240, 240×240, 280×320, 320×480, 375×667, 430×932, 600×400,
768×1024, 900×700, 1024×768, 1440×900, 1920×1080; jeweils hell und dunkel).
Geprüft werden horizontale Überläufe, Textüberläufe, Touch-Zielgrößen,
alle acht Sensorfelder, beide Themes, alle vorhandenen Action-Payloads inklusive
Boost-Boolean, JSON-/Objekt-Updates, aktive und deaktivierte Steuerung,
fehlende Sensorwerte, fehlerhafte Nachrichten, fehlgeschlagene Aktionen und Drawer.

Die PHP-Änderung wählt anhand der neuen Einstellung die HTML-Vorlage. Lokal ist kein PHP installiert;
die PHP-Syntaxprüfung ist daher lokal nicht ausgeführt, sondern für CI eingerichtet.
Die neue CI-Konfiguration wurde noch nicht auf GitHub ausgeführt.

HTML-SDK-Schnittstellen anhand der offiziellen Dokumentation abgeglichen:
https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/html-sdk/

Noch erforderlich: Abnahme in der tatsächlichen IP-Symcon-Visualisierung ab 7.1,
Hardwaretest am Q350/Q450 und Kontrolle auf Safari/iOS. Der Chrome-Test simuliert
die SDK-Brücke; er ersetzt keine Verbindung mit einem Lüftungsgerät.
