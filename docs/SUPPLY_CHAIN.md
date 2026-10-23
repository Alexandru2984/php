# CI secret and supply-chain gate

`scripts/security-scan.sh` is a dependency-free blocking check over Git-tracked files.
It rejects private/data container filenames, high-confidence credential signatures,
external GitHub Actions not pinned to a full commit SHA, non-exact npm versions,
non-HTTPS package resolutions, missing SHA-512 integrity/license metadata and packages
that declare install scripts. Findings report only a filename and rule; candidate
credential values are never copied into CI logs.

The scanner runs before application tests and has adversarial smoke coverage proving
that a fake GitHub token, mutable Action reference and tracked `.env` all fail. The
token test also proves the candidate itself is absent from scanner output. Browser
dependencies are installed separately with lifecycle scripts disabled, audited, and
reviewed on pull requests.

The production analytics tracker is an explicit vendored exception rather than a
package-manager dependency. `assets/analytics.js` is copied from the operator's
self-hosted MIT-licensed Umami instance, its upstream notice is preserved in
`THIRD_PARTY_NOTICES.md`, and its SHA-256 digest is asserted by unit, HTTP and live
health checks. The analytics origin is allowed by `connect-src` only, not
`script-src`. Updating the asset requires reviewing the upstream diff, license,
collection payload, pinned digest and CSP before commit.

This is defense in depth, not a replacement for GitHub secret scanning or review.
Regex checks can miss unknown credential formats and cannot revoke a leaked value.
If a candidate ever enters Git history, rotate/revoke it first, preserve evidence and
follow the incident runbook; rewriting history alone is not containment.

## Risk boundary

- **Assets/data:** tracked source, workflow references and package metadata only; no
  live `.env`, database, backup, provider credential or visitor data is opened.
- **Access/blast radius:** the script is read-only and runs with the same secretless
  `contents: read` CI token. A false positive blocks delivery but cannot mutate prod.
- **Failure/rollback:** failures name the file and rule. Revert the offending dependency
  or scanner change; never suppress a real credential signature to make CI green.
- **Detection/ownership:** CI owns continuous detection; the repository operator owns
  triage, credential revocation and enabling GitHub-native secret scanning plus branch
  protection. Dependabot/dependency-review remain the vulnerability update path.
