---
layout: dev
meta:
  title: halt — a tiny CLI for stuck deploys
  description: A demo CLI tool landing rendered by WhimCMS. Same blocks as every other theme, restyled developer.
---

::: hero-overlay
eyebrow: halt v0.4.2
title: A tiny CLI for <em>stuck</em> deploys.
lede: Inspects your CI graph in three seconds, finds the blocker, and tells you who to ping. Zero config, no daemon, no telemetry.
ctaPrimary: $ install
ctaPrimaryHref: #install
ctaSecondary: View on GitHub
ctaSecondaryHref: #docs
image: /assets/images/placeholder/demos/dev/hero.jpg
imageAlt: Stylized terminal window in dark space
focusX: 0.5
focusY: 0.5
:::

::: code-snippet
language: bash
caption: One binary, no dependencies, available for macOS / Linux / Windows.
---
```
# Homebrew
brew install halt

# Or just curl the binary
curl -sSL https://halt.dev/install.sh | sh
```
:::

::: feature-grid
eyebrow: Why halt
title: Built for the engineer who got paged.
lede: Drop-in for any CI that exposes a status API. Reads your graph, finds the cycle, and stops asking you to scroll through 8000 lines of green ticks.
items:
  - icon: bolt
    title: Sub-second analysis
    body: Pulls the last 200 jobs in parallel, builds the dependency graph in memory, surfaces the longest-running blocker.
  - icon: code
    title: Reads your config
    body: Speaks GitHub Actions, GitLab CI, CircleCI, and Buildkite YAML out of the box. No annotation step required.
  - icon: terminal
    title: Made for the terminal
    body: Colour-aware, paginated, and pipeable. Every output is grep-friendly. JSON mode for scripts.
  - icon: lock
    title: No telemetry, ever
    body: One binary, no daemon, no phone-home. Local-only by design — the source is 1400 lines of Go.
:::

::: steps
eyebrow: Quickstart
title: Three commands and you are unstuck.
items:
  - title: Install the binary
    body: Use the package manager you already trust, or the one-line installer. The binary is 4 MB.
  - title: Authenticate once
    body: Run `halt auth` and paste the token your CI gave you. Stored in the OS keychain, never on disk.
  - title: Run halt
    body: From any repo. The first run scans the last 50 jobs and caches the graph for instant subsequent calls.
:::

::: pillars
eyebrow: How it stays small
title: Constraints we honour.
items:
  - title: One binary
    body: Static Go binary. No runtime dependencies, no install scripts to debug.
  - title: One config
    body: A single ~/.haltrc file. Every flag has a sensible default. Most users never open the file.
  - title: One mode
    body: There is no "enterprise edition." The free CLI and the paid CLI are the same binary.
:::

::: contact
eyebrow: Get in touch
title: Bug, feature, or pricing question?
lede: A real WhimCMS contact form — captcha, CSRF, rate-limit, honeypot. We triage every message within a business day.
directHeading: Direct
:::

::: end-cta
title: Stop scrolling through job logs.
body: Install the CLI, run it once, and let it tell you which job is blocking the rest. Free for solo developers, $5 a month for teams.
cta:
  label: $ install halt
  href: #install
:::
