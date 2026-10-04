# CSV-import voor vragen

Gebruik puntkomma's als scheidingsteken. De kolomvolgorde is vast:

`vak;onderwerp;toets;type;vraag;juiste_antwoord;antwoord_b;antwoord_c;antwoord_d;uitleg;actief`

- `type=mc`: `juiste_antwoord` bevat het juiste antwoord; `antwoord_b/c/d` zijn de overige antwoordmogelijkheden.
- `type=open`: `juiste_antwoord` bevat één of meerdere geldige antwoorden, gescheiden door `|`.
- Bij open vragen blijven `antwoord_b/c/d` leeg.
- `actief` is `1` of `0`.
- Vak, onderwerp en toets worden tijdens de import aangemaakt als ze nog niet bestaan.
- Bij open vragen worden hoofdletters genegeerd, leestekens grotendeels genegeerd, accenten genormaliseerd en kleine typefouten beperkt toegestaan.
- CSV-velden mogen door de normale CSV-regels tussen dubbele aanhalingstekens staan wanneer ze een puntkomma of regelafbreking bevatten.

Voor ChatGPT kun je bijvoorbeeld vragen:

> Maak een CSV voor leren.eem64.nl met puntkomma als delimiter. Gebruik exact de kolommen vak, onderwerp, toets, type, vraag, juiste_antwoord, antwoord_b, antwoord_c, antwoord_d, uitleg, actief. Gebruik type=mc voor meerkeuze en type=open voor open vragen. Geef bij open vragen meerdere geldige formuleringen in juiste_antwoord, gescheiden door |. Zet actief op 1.
