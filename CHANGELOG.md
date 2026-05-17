# Changelog

All notable changes to `gkbs-core` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Initial package skeleton
- Singleton-Loader with version_compare for plugin coexistence
- League\Container wrapper as PSR-11 service container
- PSR-3 Logger interface with Monolog default implementation
- Migrations-Runner with version-pinned, idempotent migrations
- Module-Loader with ServiceProvider pattern
- Compliance-Gate-Runner skeleton
- `GKBS\Core\Pricing\Calculator` — stateless Mobilfunk-Kostenrechner. Aus `gksimcheck-business-suite/includes/class-calculator.php` portiert, modernisiert (strict types, PSR-4-Namespace, UTF-8-Umlaute). Berechnet Effektivpreis aus Listenpreis × Rabatt × Zahlmonate − Startguthaben ÷ Nutzmonate. Liefert Rechenweg, kombinierte Summen bei gleicher Laufzeit, Erklärungstexte und Plausibilitäts-Warnungen. Keine WP-Abhängigkeit, von beiden Distributions-Plugins nutzbar.

### Changed
- `AuditLogger::record()` emits the mirror log line at `debug` instead of `info`. Audit-Trail in `wp_mbs_audit_log` is unaffected — the change only stops the DB-LogHandler (default threshold `info`) from duplicating each audit entry into `wp_mbs_logs`. Reduces noise during bulk operations like `tariff:sync` by ~80%.

## [0.1.0-alpha.0] - 2026-05-06

- Initial alpha release
