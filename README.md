# Stratos Core API

A phpVMS 7 module that adds the API surface the [Stratos desktop client](https://skyvexsoftware.com) needs — pilot identity, reference data, and flight ops. Required by every Stratos integration; everything else (logbook, screenshots, future plugins) builds on top.

## Install

You'll need a working phpVMS 7 install.

1. Download **`module.zip`** from the [latest release](https://github.com/skyvexsoftware/stratos-core-api/releases/latest).
2. In your phpVMS admin: **Admin → Modules → Add New Module**, upload the zip.
3. Back on the Modules list, **enable** `StratosCore`.
4. From a shell:
   ```bash
   php artisan module:migrate StratosCore
   php artisan optimize:clear
   ```

That's it — your phpVMS now exposes the Stratos API at `/api/stratos`. Pilots running the Stratos desktop client just point it at your VA's URL and log in.

## What it adds

The module mounts these endpoints under `/api/stratos`. All require a Bearer token (the pilot's `users.api_key`) except the handshake and the credentials login.

```
GET    /                          handshake
POST   /pilot/login               credentials login
GET    /pilot/verify              pilot profile
GET    /pilot/statistics          flight hours, average landing rate, etc.
GET    /data/aircraft             fleet list
GET    /data/airports             airport directory
GET    /data/announcements        VA announcements feed
GET    /flights/bookings          pilot's current bids
GET    /flights/search            schedule search
POST   /flights/start             begin tracking a booked flight
POST   /flights/update            position / phase update
POST   /flights/complete          file PIREP
POST   /flights/cancel            cancel active flight
POST   /flights/unbook            drop a bid
```

All endpoints are pure phpVMS-native: pilot rows in `users`, flight history in `acars` (FLIGHT_PATH rows), PIREPs in `pireps`, custom metadata in `pirep_field_values`. The module owns zero tables of its own.

---

## Building your own module on top

This module is the base for the wider Stratos ecosystem. Logbook, screenshots, and any custom Stratos-aware phpVMS module can:

- Import `Modules\StratosCore\Http\Middleware\StratosAuth` for the same Bearer-token auth
- Reuse `Modules\StratosCore\Actions\LandingAnalysisMapper` and `PirepDistanceCalculation` for normalising and recomputing flight data
- Add their own endpoints under different prefixes (e.g. a logbook module would mount `/api/stratos/logbook`)

Declare this module as a dependency in your `module.json`:

```json
{
  "requires": ["skyvexsoftware/stratos-core-api"]
}
```

If you're building a Stratos integration that doesn't fit as a phpVMS module — e.g. a custom theme widget, a dashboard plugin, or an external service — query the same endpoints the desktop client uses. The Bearer-token model is the same.

## Local development

```bash
# clone + dependencies
git clone https://github.com/skyvexsoftware/stratos-core-api
cd stratos-core-api
composer install

# run the test suite
./vendor/bin/pest
```

The tests use [Pest](https://pestphp.com/) + [Orchestra Testbench](https://packages.tools/testbench) and target Laravel 10 (the version phpVMS 7 ships). Stubs at `tests/stubs/` cover phpVMS-host classes so the suite runs in isolation. CI runs on PHP 8.2, 8.3, and 8.4.

For end-to-end testing against a real phpVMS install, symlink the repo into your phpVMS `modules/` directory:

```bash
ln -s "$(pwd)" /path/to/phpvms/modules/StratosCore
cd /path/to/phpvms && php artisan module:enable StratosCore
```

After that any edits in this repo are live in your phpVMS install — no rebuild needed.

## Releasing

Tag a release with `v*` (e.g. `v0.1.0`). The release workflow builds `module.zip` and attaches it to the GitHub release. Operators install it via the **Admin → Modules → Add New Module** flow above.

## License

MIT
