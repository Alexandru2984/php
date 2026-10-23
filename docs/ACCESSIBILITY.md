# Responsive and accessibility verification

## Automated gate

`npm run test:browser` copies only the public/runtime files (never `.env`) to a
read-only temporary application tree, then starts it with `APP_ENV=testing`, a fresh
temporary SQLite database and a temporary session directory. It does not read
production configuration, messages or credentials. One Chromium worker verifies:

- no document/body horizontal overflow at 320, 375, 768, 1024 and 1440 CSS pixels;
- at least 44px height for primary mobile controls, including search and disclosure
  targets;
- skip-link destination, visible keyboard focus and a usable focus sequence;
- persisted light/dark selection, character counter, posting and indexed search;
- keyboard-accessible opt-in draft controls, 24-hour expiry, deletion and text-only
  preview with hostile-markup regression coverage;
- no browser console, uncaught page or failed network errors in those flows;
- WCAG 2.0/2.1/2.2 A/AA rules detectable by Axe in light and dark modes; and
- the reduced-motion override for animated/transitioning content.

The gate found low-contrast secondary text and a voice-control/accessibility-name
mismatch on permanent links during rollout. Both were fixed; no rule was disabled and
no violation snapshot or exception list is used.

Automated checks cannot prove full WCAG conformance. Before declaring Stage 6 complete,
record a manual pass for browser zoom at 200%, high contrast/forced colours, keyboard
operation of every disclosure and form, and at least one named screen reader on desktop
and mobile. Lighthouse performance/accessibility budgets also remain separate work.

## CI and supply-chain boundary

Playwright and the Axe adapter are exact-version development dependencies in
`package-lock.json`; they are absent from immutable production releases at runtime.
CI uses Node 24.16.0, disables npm lifecycle scripts and package-manager caching,
runs `npm audit`, and downloads the Chromium revision selected by the locked Playwright
package. Checkout, Node setup and dependency review actions are pinned to full commit
SHAs. Pull requests are reviewed for moderate-or-higher vulnerabilities in runtime,
development and unknown scopes, while Dependabot proposes npm and action updates.

The job has only `contents: read`, receives no application secrets and writes only to
the disposable GitHub runner. A compromised browser-test dependency can affect that
ephemeral runner and CI results, but cannot reach production data or deploy. Detection
is a failing audit/dependency-review/browser job; rollback is removal or reversion of
the browser-test commit without changing the PHP runtime. The repository operator owns
dependency review, manual sign-off and enabling both CI jobs as required branch checks.

## Local commands

```bash
npm ci --ignore-scripts --no-audit --no-fund
./node_modules/.bin/playwright install chromium
npm audit --audit-level=high
npm run test:browser
```
