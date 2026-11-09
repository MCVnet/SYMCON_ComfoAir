# Release-Checkliste 1.0.3

- [x] `library.json`/`module.json` gültiges JSON, URL-Werte als Strings
- [x] Separate Bibliotheks- und Modul-GUIDs geprüft
- [x] Modulname entspricht PHP-Klassenname (ohne Leerzeichen)
- [ ] PHP-Syntaxprüfung im CI-Lauf bestätigen
- [x] Kachel mit `GetVisualizationTile`, `SetVisualizationType`, `requestAction` überprüft (statisch)
- [x] Keine Live-Verbindung/Client Socket im Polling-Modul
- [x] GitHub Actions statische Prüfung angelegt
- [ ] Instanz-Neuanlage auf frischer SymBox prüfen
- [ ] Upgrade einer bestehenden SymBox-Instanz aus Beta 1.9 prüfen
- [ ] Sensor-Aktualisierung über längere Zeit bei 5/30/60 Sekunden prüfen
- [ ] Schreibbefehle inkl. realer Zustandsrückmeldung Q350 prüfen
- [ ] Q450 an echter Hardware prüfen
- [ ] Lizenz- und Bildnutzungsrechte verbindlich klären
- [ ] Abschlussprüfung im Symcon Module Store durchführen

Der Name Version 1.0.3 bedeutet nicht, dass die ausstehenden Punkte erledigt sind. Für einen Stable-Store-Release müssen die offenen Prüfpunkte nachgeholt werden.
