---
layout: default
meta:
  title: WhimCMS — minimales, sicherheitsorientiertes PHP-CMS
  description: Server-gerendert, dateibasiert, kein Build-Step. Eine Codebasis, die du an einem Nachmittag liest und überall hostest, wo PHP läuft.
---

::: hero
title: Ein minimales, sicherheitsorientiertes PHP-CMS.
eyebrow: Offene Demo
lede: Server-gerendert. Dateibasiert. Kein Build-Step. Eine Codebasis, die du an einem Nachmittag durchliest und überall hostest, wo PHP läuft.
image: /assets/images/placeholder/core/hero.jpg
align: start
focusX: 0.5
focusY: 0.5
ctaPrimary: Themes ansehen
ctaPrimaryHref: #themes
ctaSecondary: Architektur
ctaSecondaryHref: #architecture
:::

::: stat-row
items:
  - value: 0
    label: Plugins
  - value: 0
    label: Datenbank
  - value: 0
    label: Build-Steps
  - value: 0
    label: Composer-Deps
:::

::: feature-grid
items:
  - icon: layers
    title: Block-basierte Inhalte
    body: Seiten sind ein Stapel typisierter Blöcke. Jeder Block deklariert sein Schema per {@ block @}-Annotation im Partial — Tippfehler scheitern laut beim Parsen.
  - icon: shield
    title: Standardmäßig gehärtet
    body: Sechs Schichten sichern jedes POST — CSRF, Proof-of-Work-Captcha, Sliding Rate-Limit, IP-Blocklist, per-Installation generierter Honeypot, Pflicht-Consent.
  - icon: bolt
    title: Fokus-bewusste Bilder
    body: {% image: '/assets/foto.jpg', width: 768, focusX: 0.5 %} in jedem Template liefert einen fokus-bewussten Crop, cached ihn auf Disk und liefert ihn unter /img-c/<hash> mit immutable Headern aus. Read-only-Endpoint.
  - icon: globe
    title: Mehrsprachiges Routing
    body: Pro Sprache eigene URL-Segmente. Automatisches hreflang und ein funktionierender Sprach-Switcher ohne Dead-Links.
  - icon: gauge
    title: HMAC-signierter Cache
    body: Page-Renders cachen auf Disk als HMAC-signiertes JSON. Eine gefälschte Cache-Datei wird verworfen, bevor ihre Bytes irgendeinen Parser erreichen — Schreib-Primitiven anderswo eskalieren nicht zu Code-Ausführung.
  - icon: lock
    title: Datenschutzfreundlich
    body: E-Mail-Verschleierung, standardmäßig keine Drittanbieter-Skripte, keine Cookies beim ersten Seitenaufruf.
id: features
eyebrow: Wie es zusammenkommt
title: Eine schlanke Block-Bibliothek — derselbe Pool versorgt jedes Theme.
lede: Autoren komponieren Blöcke in Markdown. Themes stylen sie in CSS um. Das HTML bleibt dasselbe.
:::

::: code-snippet
language: markdown
caption: Für Redakteure — eine Seite ist ein Stapel typisierter Blöcke in einer .md-Datei.
---
```
::: hero
eyebrow: Offene Demo
title: Weniger Plattform, mehr Website.
ctaPrimary: Themes ansehen
ctaPrimaryHref: #themes

::: pillars
eyebrow: Warum
items:
  - title: Kleine Angriffsfläche
    body: Kein Admin-UI, keine Datenbank, kein Composer.
  - title: Markdown-Inhalte
    body: Eine .md-Datei pro Seite. Push, Reload, fertig.
```
:::

::: code-snippet
language: html
caption: Für Entwickler — ein neuer Block-Typ ist eine HTML-Datei mit {@ block @}-Header. Kein Registry-Edit, keine Config-Änderung.
---
```
{@ block
  required: items
  optional: id eyebrow title lede
@}
<section class="block block-pillars">
  <ul>
    {% for: attrs.items, as: 'item' %}
    <li>
      <h3>{{ item.title }}</h3>
      <p>{{ item.body }}</p>
    </li>
    {% endfor %}
  </ul>
</section>
```
:::

::: steps
items:
  - title: Alles hochladen
    body: Per SFTP auf einen beliebigen PHP-8.1+-Host. _htaccess_production in .htaccess umbenennen.
  - title: Einen Config-Wert setzen
    body: In config/seo.php den canonical_hosts auf deine Domain setzen. Ohne diesen Wert verweigert der Boot den Start — Host-Header-Poisoning von Canonical-, OG- und Sitemap-URLs ist damit strukturell unmöglich.
  - title: content/ editieren und neu laden
    body: Eine Markdown-Datei pro Seite. Push, Browser neu laden. Der Content-Cache invalidiert über mtime — kein Flush-Button, kein Admin-UI.
eyebrow: Für Betreiber
title: In drei Schritten online.
lede: Kein Build, kein Composer, keine Datenbank. „Deploy" heißt: Dateien hochladen.
:::

::: theme-showcase
items:
  - name: Business
    tagline: SaaS-artige Produkt-Landingpage
    href: ^/en/demos/business
    image: /assets/images/placeholder/demos/business/hero.jpg
    imageAlt: Business-Demo Vorschau
  - name: Personal
    tagline: Kreativ-Portfolio
    href: ^/en/demos/personal
    image: /assets/images/placeholder/demos/personal/hero.jpg
    imageAlt: Personal-Demo Vorschau
  - name: Trainer
    tagline: Functional-Fitness-Coach
    href: ^/en/demos/trainer
    image: /assets/images/placeholder/demos/trainer/hero.jpg
    imageAlt: Trainer-Demo Vorschau
  - name: Dev
    tagline: Developer-Tool / CLI
    href: ^/en/demos/dev
    image: /assets/images/placeholder/demos/dev/hero.jpg
    imageAlt: Dev-Demo Vorschau
id: themes
eyebrow: Mitgelieferte Themes
title: Ein CMS, viele Gesichter.
lede: Jede Demo ist dieselbe WhimCMS-Installation mit einer anderen Layout-Datei und einem anderen Stylesheet-Bundle. Die Block-Partials darunter sind identisch.
:::

::: prose
id: architecture
eyebrow: Für Auditoren
title: Drei Subsysteme, keine Überraschungen.
lede: Eine Content-Engine, eine Template-Engine, ein kleiner Kernel, der beide verbindet. Die gesamte Codebasis liest sich an einem Nachmittag.
---
Die **Content-Engine** parst jede `.md`-Seite in typisierte `Block`-Objekte. Es gibt keine zentrale Block-Registry — jedes Block-Partial deklariert sein eigenes Schema per `{@ block @}`-Annotation am Anfang der Datei, eingesammelt beim Boot. Ein neuer Block-Typ ist eine neue HTML-Datei; mehr ändert sich nirgendwo.

Die **Template-Engine** ist ein kleiner Token-Stream-Renderer mit acht eingebauten Direktiven (`if`, `for`, `include`, `image`, `blocks`, …). Ausgabe ist standardmäßig HTML-escaped; Roh-Ausgabe ist ein expliziter, audit-getrackter Opt-in. Die `{% image %}`-Direktive ist der einzige Pfad, der jemals in den Bilder-Cache schreibt — ihr read-only `/img-c/`-Endpoint kann keine Cache-Writes über URL-Manipulation auslösen.

Das Kontaktformular ist die einzige Schreibfläche, gesichert durch sechs unabhängige Schichten, dokumentiert über **acht Audit-Pässe**, zuletzt ergänzt durch einen externen Pentest mit OWASP ZAP und Semgrep. Cache-Dateien sind HMAC-signiert — ein gefälschter Cache kann nicht zu Code-Ausführung eskalieren. Der Boot verweigert den Start ohne canonical-host-Allowlist, sodass Host-Header-Poisoning von Canonical-, OG- und Sitemap-URLs strukturell unmöglich ist.
:::

::: prose
id: admin
eyebrow: Optionaler Admin
title: Eine Companion-App, kein Plugin.
lede: WhimCMS funktioniert als reines Datei-CMS — content/ öffnen, pushen, neu laden. Wenn das nicht reicht, liegt WhimAdmin im selben Repository als optionales Admin-Panel, von derselben Hand geschrieben, unter derselben Audit-Disziplin geprüft, mit derselben Zero-Runtime-Deps-Regel.
---
„Plugin" heißt in der CMS-Welt: Drittanbieter-Code mit eigener Abhängigkeitskette und eigener Sicherheitslage, zur Laufzeit über einen Hook-Bus oder Marketplace geladen. WhimCMS hat nichts davon — und WhimAdmin bringt nichts davon mit. Es ist ein zusätzliches Verzeichnis, handgeprüft, an einem Nachmittag lesbar, optional deploybar, löschbar oder behaltbar. Die Aussage „0 Plugins" bleibt auch mit WhimAdmin im Repo wahr.
:::

::: feature-grid
items:
  - icon: shield
    title: Zwei-Faktor-Login
    body: Passwort (Argon2id) plus 6-stelliger OTP per Mail. Sessions binden an IP + UA, rotieren beim Auth-Upgrade und timeouten unabhängig auf Idle- und Absolut-Uhr.
  - icon: layers
    title: Versions-Historie pro Seite
    body: Jeder Save snapshotted den Vorzustand. Frühere Version per Klick zurückrollen. History wird per Sweeper nach konfigurierbarer Aufbewahrungsfrist gekürzt, der Speicher bleibt beschränkt.
  - icon: bolt
    title: Soft-Recycler für Seiten und Assets
    body: Löschen ist nie destruktiv. Seiten landen in content/.recycler/, Assets in assets/.recycler/. Beide sind web-deny'd. Auto-Sweep räumt vergessene Einträge auf.
  - icon: globe
    title: Routen- und Sprachen-Editor
    body: URL-Segmente pro Sprache und die supported-langs-Liste sind über die UI editierbar. Der Writer schickt jede Änderung vor dem Rename durch `require` — ein Serialisierer-Bug kann keine kaputte routes.php auf die Disk bringen.
  - icon: gauge
    title: Asset-Browser mit Content-Sniffing
    body: Upload über die UI mit 10 MB Limit. Extension-Allowlist; getimagesize prüft, dass die Bytes wirklich zum behaupteten Format passen. SVG ist bewusst ausgeschlossen (inline-Script-Vektor).
  - icon: lock
    title: First-Run-Setup ohne Bootstrap-Mail
    body: 32-Byte-Token beim ersten Request, HMAC-gespeichert, plaintext gespiegelt in eine deny-all-Sidecar-Datei, die der Operator per SFTP liest. Keine Bootstrap-Mail, kein Admin-Enumeration-Pfad, single-use.
id: admin-features
eyebrow: WhimAdmin
title: Wenn Datei-Edits nicht reichen.
lede: Gleicher Code-Stil, gleiche Audit-Disziplin, gleiche Zero-Deps-Regel. Optional, löschbar, als reines Add-on deploybar.
:::

::: prose
eyebrow: Ehrliche Offenlegung
title: Wie das hier wirklich gebaut wurde.
lede: Diese Codebasis wurde über mehrere Sessions vibe-codet, mit einem LLM (Claude) als primärem Autor. Drei Filter haben sie produktionsreif gehalten.
---
**Domänen-bewusste Code-Review beim Diff.** Der Betreiber hat den Code beim Entstehen gelesen — nicht nur das Laufzeitverhalten geprüft. Mehrere echte Bugs wurden so beim direkten Lesen oder beim Site-Aufrufen visuell erkannt.

**Vorab gesetzte Constraints, die gehalten wurden.** „Keine externen Abhängigkeiten" stand seit Session null und diente als Ablehnungskriterium, sobald neuer Code eine Library vorschlug. Ohne diesen expliziten Anker driftet ein LLM zu populären Dependencies — sein Trainingsset belohnt das.

**Getrennte Audit-Sessions, kein Inline-Self-Review.** Acht Audit-Pässe liefen in eigenen Sessions mit adversarischem Framing — der jüngste kombinierte manuellen Code-Review mit einem externen Pentest via OWASP ZAP und Semgrep. Bauen und Auditieren sind unterschiedliche kognitive Modi; ein LLM mitten im Bau wechselt nicht zuverlässig in den Auditor-Modus. Getrennt gelaufen, fanden die Audits Issues, die das Bauen übersehen hatte.

Was das zeigt: vibe-coded Entwicklung kann für ein Projekt dieser Größe ein gehärtetes, produktionsreifes Ergebnis erreichen — mit einem domänen-bewussten Menschen im Loop und disziplinierter Audit-Trennung. Was es **nicht** zeigt: dass LLM-getriebene Entwicklung standardmäßig sicher ist.
:::

::: contact
eyebrow: Kontakt
title: Fragen, Ideen oder ein Projekt?
lede: Dieses Formular ist voll verdrahtet — Captcha, CSRF, Rate-Limit, Honeypot. Schreib eine echte Nachricht.
directHeading: Oder direkt
:::

::: end-cta
title: Wähl ein Theme. Mach es zu deinem.
cta:
  label: Mitgelieferte Themes ansehen
  href: #themes
body: Jede Demo ist eine Layout-Datei, ein Stylesheet, eine Markdown-Seite. Beim Deploy deiner eigenen Site die Demos einfach entfernen.
:::
