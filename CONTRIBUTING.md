# Contributing

Thanks for considering a contribution!

## Local setup

The package depends on `cboxdk/laravel-telemetry`, resolved from Packagist.

```bash
composer install
composer check   # pint --test, phpstan, pest
```

**Co-developing both packages?** To edit `../laravel-telemetry` live and
have this package's own test suite see the changes, add a local path
repository — but do **not** commit it (a missing path dir is a hard error
on CI and fresh clones):

```bash
composer config repositories.local path ../laravel-telemetry   # then: git checkout composer.json before committing
```

The `statamic-telemetry-demo` app already symlinks both packages via its
own path repositories, so end-to-end changes are live there without any of
this.

## Process

1. Fork and branch from `main`.
2. Make your change, with tests — new behaviour needs a test, a bug fix
   needs a regression test.
3. Keep `composer check` green.
4. Update the docs (`docs/`, `README.md`, `llms.txt`) in the same PR when
   you change the metric/attribute surface or a config toggle.
5. Open a PR describing **why**, not just what.

## Ground rules

- **Telemetry never throws into the app.** Event listeners extend
  `GuardedListener`; resolver bodies stay side-effect-free and cheap.
- **Metric labels stay bounded.** Ids, slugs and paths go on span
  attributes, never on a metric label. If a label's value comes from
  content or user input, it needs a bounded classification.
- **New instrumentation is a config toggle**, defaulted to match its cost —
  cheap and universal → on; verbose or per-node → off.
- Regenerate the Grafana dashboard with
  `php resources/grafana/generate.php` when you add a metric worth a
  panel.
