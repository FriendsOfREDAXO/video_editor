# Changelog

## 1.0.0 – in Arbeit

Neuentwicklung für REDAXO 6, mit Ideen aus dem AddOn ffmpeg für REDAXO 5.

- Panel in der Medien-Detailansicht: Vorschau, Analyse (Auflösung, Codecs, Datenrate, Faststart) und Bewertung der
  Web-Tauglichkeit mit Empfehlungen
- Optimieren mit Vorlagen: Web 1080p/720p, Mobil 540p, Archiv, WebM (VP9), sofort abspielbar, Ton entfernen,
  Lautstärke angleichen
- Schnitt-Editor: Zeitleiste mit Vorschaubildern, teilen, Abschnitte entfernen, Ränder ziehen, Ergebnis abspielen,
  Tastatur; schnell (ohne Neukodierung, ab Schlüsselbild – mit Anzeige) oder bildgenau
- Poster bildgenau wählen; ein neues Poster ersetzt die Datei des bisherigen
- Untertitel und Kapitel: Editor mit Live-Vorschau, Import VTT/SRT (gesäubert), alle verschieben; beim Schneiden
  mitgeschnitten; Auslieferung als text/vtt (`video_editor_track`), Vorschau im Panel
- `VideoEditor::html()` (fertiges `<video>`, auch als Hintergrundvideo) und `VideoEditor::tracks()`
- Video im Editor über eigenen Endpunkt mit Range-Anfragen (Springen auch ohne Range-Support des Webservers)
- Ergebnis als verknüpfte Fassung oder anstelle des Originals
- Aufträge im Hintergrund (`video:work`, Cronjob `video_editor_work`) mit Fortschritt, Abbrechen, erneut Versuchen
- Rechte: ansehen (Medienkategorie), `video_editor[]`, `video_editor[replace]`
- Schnittstelle `VideoEditor` (Poster, Web-Fassungen, Bearbeiten-Adresse) – genutzt von fluid, wenn vorhanden
- Pfade per Umgebungsvariable (`FFMPEG_BIN`, `FFPROBE_BIN`, `PHP_CLI`) oder Konfiguration
- Unit-Tests für Untertitel, Integrationstests für die Rechte
