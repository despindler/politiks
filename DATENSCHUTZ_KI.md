# Datenschutztext für die optionale KI-Vorauswahl

Stand: 15. Juli 2026. Dieser Text ist als geprüfter Baustein für die Datenschutzerklärung des Zielhosts gedacht. Vor der Aktivierung sind Betreibername, Kontakt, Rechtsgrundlage, Auftragsbearbeitung und allfällige internationale Datenübermittlung durch die verantwortliche Stelle zu ergänzen und rechtlich zu prüfen.

## Nutzerinformation

### KI-gestützte Vorauswahl von Abstimmungen

Im dritten Schritt der Insight-Erstellung kann freiwillig eine experimentelle KI-Vorauswahl verwendet werden. Der normale Such- und Filterbereich funktioniert ohne diese Funktion. Die KI-Ausgabe ist weder eine offizielle Information noch eine geprüfte Klassifikation. Sie grenzt nur die angezeigte Abstimmungsliste ein, bleibt bearbeitbar und wählt nie automatisch eine Abstimmung als Evidenz aus.

Wenn die Funktion gestartet wird, verarbeitet OpenAI als externer API-Anbieter:

- das vom Nutzer formulierte Auswahlkriterium;
- den gewählten Zeitraum;
- für die lokal vorselektierten öffentlichen Parlamentsdatensätze: technische Abstimmungs-ID, Titel, Geschäfts- und Abstimmungskennung, Datum, Abstimmungstyp, genaue Frage, dokumentierte Bedeutung von Ja und Nein, offizielle Metadaten und geprüfte Klassifikationen; sowie
- ausschliesslich zusammengefasste Angaben zum ausgewählten Mitgliederkreis wie Ja-/Nein-/Enthaltungszahlen und die daraus berechnete Mehrheitsrichtung.

Nicht an OpenAI übermittelt werden Name, E-Mail-Adresse oder Google-Kennung des angemeldeten Nutzers, Namen der ausgewählten Ratsmitglieder, einzelne Stimmen, hochgeladene Bilder, Kampagnenlinks oder -videos, Titel und Aussage des Insights, Erläuterungen, Sichtbarkeit oder andere nicht benötigte Insight-Inhalte. Für die Missbrauchsprävention wird eine nicht direkt identifizierende, mit einem serverseitigen Geheimnis erzeugte Kennung übermittelt.

Die Anwendung setzt bei der Responses API `store=false` und verwendet weder Conversations noch Datei-Uploads, Hintergrundverarbeitung oder externe Tools. OpenAI verwendet API-Daten nach den aktuellen Plattformangaben nicht zum Modelltraining, ausser ein Kunde stimmt einer Datenfreigabe ausdrücklich zu. Standardmässig können jedoch Missbrauchsüberwachungsprotokolle mit Kundeninhalten bis zu 30 Tage aufbewahrt werden; abweichende Zero-Data-Retention- oder Modified-Abuse-Monitoring-Einstellungen stehen nur entsprechend berechtigten und freigeschalteten Kunden zur Verfügung. Massgeblich sind die jeweils aktuellen [OpenAI Data Controls](https://platform.openai.com/docs/models/default-usage-policies-by-endpoint).

Politiks speichert das Klartext-Kriterium nicht im lokalen Betriebsprotokoll. Dort stehen eine zufällige Request-ID, kryptographische Hashes, Status, Laufzeit, Treffer-/Kandidatenzahlen, Tokenzahlen, Cache-Status, Modell und Prompt-Version. Ein nutzer- und Insight-gebundener Ergebniscache kann die strukturierten KI-Gründe und die bereits öffentlichen Kandidatenfelder bis zur konfigurierten Ablaufzeit enthalten; er enthält nur einen Hash des Kriteriums. Das Anwenden oder Verwerfen der Vorauswahl verändert keine parlamentarischen Quelldaten und erzeugt keine Evidenz.

## Freigabecheck für den Betreiber

- [ ] Verantwortliche Stelle, Kontakt, Rechtsgrundlage und Betroffenenrechte ergänzt.
- [ ] OpenAI-Projekt, Vertrags-/Auftragsbearbeitungsbedingungen, Verarbeitungsregion und aktuelle Datenkontrollen geprüft.
- [ ] Der obige Datenumfang mit der deployten Version abgeglichen.
- [ ] Modell, Kosten-/Ratenlimits und Aufbewahrungsfristen dokumentiert.
- [ ] Text am Zielhost öffentlich erreichbar gemacht und intern freigegeben.
- [ ] Erst danach `AI_FILTER_ENABLED=1` gesetzt.
