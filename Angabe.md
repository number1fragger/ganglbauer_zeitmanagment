# Projektangabe: Zeitmanagment Website

**Auftraggeber:** Ganglbauer Landtechnik
**Auftragnehmer:** Lichtl Kevin & Zajmi Resul
**Thema:** App zur Arbeitseinteilung und Terminplanung in der Werkstaette

---

## 1. Ausgangssituation

In der Werkstaette werden Kundentermine derzeit ohne zentrale Uebersicht
geplant. Um zu wissen, wann ein Arbeiter wieder frei ist, muss jeder
Arbeiter einzeln gefragt werden, wie lange er noch Arbeit hat. Das kostet
Zeit und erschwert eine vorausschauende Planung.

## 2. Ziel

Die App soll bei der Arbeitseinteilung unterstuetzen und helfen,
Kundentermine zu organisieren, den Zeitaufwand zu planen und jederzeit
den Ueberblick zu behalten.

Beim Oeffnen der App soll sofort ersichtlich sein:

- wie lange jeder Arbeiter noch ausgelastet ist
- wann welcher Arbeiter wieder eine neue Arbeit benoetigt

> Beispiel: "Arbeiter X und Y benoetigen am Montag frueh wieder eine neue
> Arbeit." Der Kunde kann gezielt fuer diesen Zeitpunkt bestellt werden.

---

## 3. Funktionale Anforderungen

### 3.1 Arbeitsverwaltung

- **F1 - Arbeiten anlegen:** Neue Arbeiten erfassen und die geplante
  Arbeitszeit festlegen.
- **F2 - Prioritaeten vergeben:** Jeder Arbeit kann eine Prioritaet
  zugewiesen werden.
- **F3 - Beginn und Ende festlegen:** Arbeitsbeginn und Arbeitsende
  koennen definiert werden.
- **F4 - Erledigte Arbeiten abhaken:** Abgeschlossene Arbeiten koennen
  als erledigt markiert werden.

### 3.2 Zeitmanagement

- **F5 - Arbeitszeit erhoehen:** Die geplante Zeit kann nachtraeglich
  erweitert werden (z. B. "dauert 2 Stunden laenger").
- **F6 - Warnung bei Zeitueberschreitung:** Die App meldet, sobald die
  festgelegte Arbeitszeit ueberschritten wird.

### 3.3 Arbeitsanforderung durch den Arbeiter

- **F7 - Button "Brauche Arbeit":** Der Arbeiter kann melden, wann er
  eine neue Arbeit benoetigt, z. B. "morgen frueh" oder
  "uebermorgen Nachmittag".
- **F8 - Vorlaufzeit:** Die Anforderung ist fruehestens einen Tag vorher
  moeglich, damit genug Zeit bleibt, einen Kunden in die Werkstatt zu holen.

### 3.4 Uebersicht

- **F9 - Uebersicht Arbeitsende:** Anzeige, wann die geplanten Arbeiten
  der einzelnen Arbeiter voraussichtlich enden.

---

## 4. Uebersicht und Auswertung

- **A1 - Soll/Ist-Vergleich:** Gegenueberstellung von geplanter und
  tatsaechlich benoetigter Zeit pro Arbeiter.

---

## 5. Zusammenfassung

- **Planung:** Arbeiten mit Zeit, Prioritaet, Beginn und Ende anlegen
- **Kontrolle:** Zeitanpassungen und Warnungen bei Ueberschreitung
- **Kommunikation:** Arbeiter melden selbst, wann sie neue Arbeit brauchen
- **Ueberblick:** Auf einen Blick sehen, wer wann wieder frei ist
- **Auswertung:** Soll/Ist-Vergleich fuer bessere kuenftige Planung