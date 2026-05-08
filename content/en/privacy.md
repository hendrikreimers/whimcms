---
layout: default
meta:
  title: Privacy — WhimCMS
  description: What this site stores, what it does not, and how to reach the operator.
---

::: sub-hero
eyebrow: Legal
title: Privacy
lede: This is the WhimCMS demo install — substitute the text below with policy that matches your real deployment.
:::

::: legal-sections
items:
  - heading: What this site stores
    body: Server access logs are kept by the host according to its log-retention policy. The contact form does not write submitter data to disk by default. Outgoing mail can be audited via config/mail.php; the audit log is off by default.
  - heading: Cookies
    body: This site does not set cookies on first page load. The browser stores no persistent identifiers from this domain.
  - heading: Third-party services
    body: Web fonts are requested from fonts.googleapis.com and fonts.gstatic.com. To avoid this, self-host the fonts under /fonts and shrink the Content-Security-Policy back to 'self'.
  - heading: Contact-form submissions
    body: Submissions are sent by email to the operator address configured in config/mail.php. They are not retained on the server beyond the temporary mail queue. Replies come from the operator's email account.
  - heading: Your rights
    body: Contact the operator via the contact form on the home page to request information, correction, or deletion of any data the operator holds about you.
:::
