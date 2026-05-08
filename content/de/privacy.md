---
layout: default
meta:
  title: Datenschutz — WhimCMS
  description: Was diese Seite speichert, was nicht, und wie du den Betreiber erreichst.
---

::: sub-hero
eyebrow: Rechtliches
title: Datenschutz
lede: Dies ist die WhimCMS-Demo-Installation — die Texte unten durch eine Erklärung ersetzen, die zur tatsächlichen Installation passt.
:::

::: legal-sections
items:
  - heading: Was diese Seite speichert
    body: Server-Zugriffslogs werden vom Hoster gemäß dessen Aufbewahrungsfrist gehalten. Das Kontaktformular schreibt standardmäßig keine Absender-Daten auf die Platte. Mail-Versand lässt sich über config/mail.php auditieren; der Audit-Log ist standardmäßig aus.
  - heading: Cookies
    body: Diese Seite setzt beim ersten Aufruf keine Cookies. Es werden keine persistenten Identifier dieser Domain im Browser abgelegt.
  - heading: Drittanbieter-Dienste
    body: Webfonts werden von fonts.googleapis.com und fonts.gstatic.com geladen. Um das zu vermeiden, Fonts unter /fonts selbst hosten und die Content-Security-Policy zurück auf 'self' verkleinern.
  - heading: Kontaktformular-Übermittlungen
    body: Übermittlungen werden per E-Mail an die in config/mail.php konfigurierte Betreiber-Adresse gesendet. Sie werden auf dem Server nicht über die temporäre Mail-Queue hinaus aufbewahrt. Antworten erfolgen aus dem E-Mail-Konto des Betreibers.
  - heading: Deine Rechte
    body: Über das Kontaktformular auf der Startseite kannst du Auskunft, Berichtigung oder Löschung der vom Betreiber zu dir gespeicherten Daten verlangen.
:::
