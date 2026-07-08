# Examples

Self-contained example apps for this generator — one directory per model. Each
`examples/<app>/` holds the **source**: `model/*.esdm.yaml` (+ `*.statemachine.yaml`
and FEEL guards), an optional `authoring/*.bpmn`, and an `esdmgen.yaml` targeting
`symfony-patchlevel-postgres`. The models are codegen-neutral — keep them in sync with
the equivalent apps in any other ESDM codegen.

Generate an app's runnable Symfony + patchlevel/event-sourcing + PostgreSQL output into
its own **gitignored** `generated/symfony/` subdir:

```sh
# dockerized generator — recommended (local PHP lacks pdo_pgsql)
docker compose run --rm codegen generate examples/todo

# or the generator directly, if you have PHP + composer
php bin/esdmgen generate examples/todo
```

Apps authored as BPMN (proposal 0003) map to ESDM first, then generate:

```sh
docker compose run --rm codegen bpmn:map  examples/orders   # authoring/*.bpmn → model/*.yaml
docker compose run --rm codegen generate  examples/orders   # ESDM → runnable app
```

Or generate every app at once with the batch driver:

```sh
composer examples            # generate all → examples/<app>/generated/symfony/
composer smoke               # smoke gate: temp dir, fail loudly, write nothing
scripts/examples.sh [--check]  # same, without composer
```

Run a generated app — it brings its own PostgreSQL + api + worker stack:

```sh
cd examples/todo/generated/symfony && docker compose up -d --build
```

`generated/` is disposable — never edit it by hand; change the model and regenerate.
