# gkbs-core

Shared core for two WordPress plugin distributions:

- **gksimcheck-business-suite** — Affiliate distribution (gksimcheck.de)
- **mb-business-suite** — Mobilfunkboerse direct-sales distribution

Provides:

- Singleton-Loader (Coexistence-Pattern, version_compare)
- PSR-11 Service-Container (league/container wrapper)
- PSR-3 Logger (Monolog-backed, file + DB sinks)
- Migrations-Runner (idempotent, version-pinned)
- Module-Loader (ServiceProvider-based)
- Compliance-Gate-Runner (continuous validation)
- Health-Check-Layer

## Requirements

- PHP `^8.2`
- WordPress `^6.4`

## Installation

Via Composer (path repository in distribution `composer.json`):

```json
{
    "repositories": [
        { "type": "path", "url": "../gkbs-core" }
    ],
    "require": {
        "gkbs/core": "^0.1.0-alpha"
    }
}
```

For production: tag the package, distributions pull tagged versions.

## License

Proprietary. Code ownership: Michael Gorbunow (private project).
