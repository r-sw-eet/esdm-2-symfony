# esdm-2-symfony

A **code generator** that turns a business-process / domain model — authored as **BPMN**
(in the bundled bpmn.io editor) or as an [ESDM](https://www.esdm.io/) model (Event-Sourced
Domain Modeling — YAML documents describing an event-sourced domain) — into a **real,
runnable application**. This repo is the **PHP codegen** of the ESDM toolchain: it emits
a **Symfony** app that implements the model with **CQRS**, an **event-driven** read side and
**event sourcing**, with a choice of event store per target: **PostgreSQL** with
[patchlevel/event-sourcing](https://event-sourcing.patchlevel.io/) as the runtime, or
**EventSourcingDB** + **MongoDB** via the official
[EventSourcingDB PHP SDK](https://github.com/thenativeweb/eventsourcingdb-client-php),
with ideas borrowed from [Nimbus](https://nimbus.overlap.at/) and
[OpenCQRS](https://github.com/open-cqrs/opencqrs).

> Draw the business process. The ESDM toolchain makes it run.

## Related projects

This is a **standalone** ESDM → Symfony codegen — it depends on no sibling repo. Two related
projects sit around it in the ESDM tooling ecosystem:

| Repo                                                           | Role                                                                                                                                                                                        |
|----------------------------------------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| [esdm-extensions](https://github.com/r-sw-eet/esdm-extensions) | the **spec repo** this codegen implements — proposals 0001 (state machines), 0002 (FEEL rules), 0003 (BPMN→ESDM), 0004 (domain-console contract); owns the `esdm-extensions.io/*` namespace |
| esdm-vue-reader                                                | the stack-agnostic **domain console** — point it at a running generated app; it consumes the 0004 dev contract this codegen emits                                                           |

## Stack

The generator itself is pure PHP; the **emitted** apps run on this stack (linked to upstream
docs — no local doc mirror is kept):

| Layer                | Technology                                                                                                        |
|----------------------|-------------------------------------------------------------------------------------------------------------------|
| Model / input format | [ESDM](https://www.esdm.io/) + [esdm-extensions](https://github.com/r-sw-eet/esdm-extensions) proposals 0001–0004 |
| Generator runtime    | [PHP](https://www.php.net/) 8.2+ · [Symfony Console](https://symfony.com/doc/current/components/console.html)     |
| Generated app        | [Symfony 7](https://symfony.com/) — CQRS, async projections                                                       |
| Event sourcing       | [patchlevel/event-sourcing](https://event-sourcing.patchlevel.io/) *or* the official [EventSourcingDB PHP SDK](https://github.com/thenativeweb/eventsourcingdb-client-php), per target |
| Persistence          | [Doctrine DBAL](https://www.doctrine-project.org/) on [PostgreSQL](https://www.postgresql.org/) *or* [EventSourcingDB](https://www.eventsourcingdb.io/) + [MongoDB](https://www.mongodb.com/), per target |

## How it works

```
BPMN diagram ──bpmn:map──▶ ESDM YAML ──lint──▶ parse ──▶ resolved Model ──map+emit──▶ runnable project
(authoring/)   (optional)   (model/)   (gate)   (framework-agnostic)                   (an adapter's output)
```

The model can be authored two ways — **drawn as BPMN** (then compiled to ESDM with
`esdmgen bpmn:map`, proposal 0003) or **written as ESDM YAML** by hand. From the ESDM model
onward the pipeline is identical:

0. **Author** *(optional)* — draw the business process as BPMN (in the bundled bpmn.io editor or
   any Camunda Modeler) and run `esdmgen bpmn:map` to decompose it into ESDM. See *Authoring from
   BPMN* below. Or skip this and write the ESDM model directly.
1. **Lint** — before anything else, `esdmgen generate` runs the upstream `esdm lint` over
   the model directory and **aborts on any error**. The generator's own parser is lax by
   design; this gate is what guarantees code is only ever produced from a model that is
   valid against the canonical ESDM schema. Warnings print but don't block (use `--strict`
   to escalate them, `--skip-lint` to bypass the gate entirely — not recommended). See
   `src/Lint/`.
2. **Parse** — load the ESDM documents, group by `kind`, build a typed model and resolve
   every cross-reference (`command` → `event`, `event` → `aggregate`, `read-model` →
   events, `query` → `read-model`). See `src/Model/`.
3. **Map + Emit** — a **target adapter** turns that model into code for one concrete stack.
   Adapters are the *only* layer that knows about a framework/db; everything above is
   stack-agnostic. See `src/Adapter/`.

The `esdm` binary is baked into the codegen Docker image (pinned by `ESDM_VERSION` in
`docker/Dockerfile`). For local runs, place it at `tools/esdm` or set `ESDM_BIN`;
see https://www.esdm.io/getting-started/installing-esdm/.

### Adapters (one per framework + db + ES library)

This codegen ships two targets:

| Target | Stack | Output slug |
|---|---|---|
| `symfony-patchlevel-postgres` | Symfony 7 + patchlevel/event-sourcing + PostgreSQL (CQRS, ES, async projections; hash-chained event log) | `generated/symfony` |
| `symfony-eventsourcingdb` | Symfony 7 + EventSourcingDB (event store) + MongoDB (read models); wire-compatible with the esdm-2-nimbus targets — subject `/<aggregate>/<id>`, type `domain.aggregate.event-name`, `{ payload, nimbusMeta }` data envelope — so a store is interchangeable between codegens | `generated/symfony-esdb` |

Every target — across codegens — emits the same HTTP contract (`POST /<context>/<command>`,
`GET /<context>/<query>`), so a client can't tell which backend is behind it. Each target writes
into its own subdirectory — `generated/<stack>/` (e.g. `generated/symfony`) — so multiple stacks
never collide.

New PHP targets (other frameworks, databases, or event stores) plug in by implementing
`Esdm\Generator\Adapter\Adapter` (`name`, `description`, `slug`, `generate`) and registering in
`AdapterRegistry`.

### What the Symfony adapter emits — ESDM kind → Symfony/PHP

| ESDM kind                        | Generated code                                                                                    |
|----------------------------------|---------------------------------------------------------------------------------------------------|
| `aggregate`                      | `#[Aggregate]` root (`BasicAggregateRoot`) with `#[Id]`, factories, `#[Apply]`                    |
| `event`                          | `#[Event]` class, constructor-promoted from `data`                                                |
| `command`                        | command DTO + a method on the aggregate's application service                                     |
| `read-model`                     | `#[Projector]` (builds an `rm_*` table) + a query-side finder                                     |
| `query`                          | a `GET` API endpoint reading the read model                                                       |
| `bounded-context`                | a PSR-4 module (`src/<Context>/…`) + a REST controller                                            |
| `policy`                         | a patchlevel **processor** that reacts to an event and dispatches a command (cross-aggregate EDA) |
| `state-machine` (ext, 0001)      | a `$status` field + apply-time transitions + decide guards on commands (illegal transition → 409) |
| `admits[].when` FEEL (ext, 0002) | the FEEL predicate compiled to a precondition guard in the aggregate (violation → 409)            |
| `feature` (GWT)                  | an executable PHPUnit `AggregateRootTestCase` (given→when→then, incl. rejection) per scenario     |

It also emits the **domain-console contract** (esdm-extensions proposal 0004) — a small
dev-only HTTP surface (`GET /_dev/catalog`, `GET /_dev/bpmn`, `GET /_dev/events`, plus CORS)
that lets the stack-agnostic viewer **esdm-vue-reader** (sibling repo) drive the app:
fire commands, watch them become events, watch the async projections update — for verifying
behaviour before any end-user UI exists. The same viewer serves apps from every codegen; it
has a **Domain console** tab (commands / read models / event stream, with per-row lifecycle
views and a FEEL value picker) and an **Author (BPMN)** tab embedding the **bpmn.io** modeler
loaded with the app's own diagram (proposal 0003).

The non-mechanical bits ESDM does not encode (which command *creates* vs *mutates* vs
*deletes* an aggregate) are derived from a verb heuristic on the document name, overridable
with an `esdm-extensions.io/lifecycle` annotation.

### What the EventSourcingDB adapter emits — the same kinds, a different runtime

The `symfony-eventsourcingdb` target keeps the app shape and HTTP surface identical but swaps
the runtime: an `aggregate` becomes a **pure state fold** (`<Agg>State::apply`) plus a **pure
decider** (`<Agg>::<command>()` — guards in, `DomainEvent`s out); the application service
replays the subject `/<aggregate>/<id>` from EventSourcingDB, runs the decider and appends
with a write precondition (`IsSubjectPristine` on create, `IsSubjectPopulated` on mutate —
violations map to 409). A `read-model` becomes a MongoDB projector (`rm_*` collection,
`revision` = last projected event id) plus a finder; projections and `policy` reactions run in
a long-lived `app:observe` worker that streams the store with `observeEvents`. GWT `feature`s
compile to plain PHPUnit tests over the pure decider.

The **`symfony-patchlevel-postgres`** target additionally hash-chains its event log in-database
(pgcrypto, `BEFORE INSERT` trigger installed by the generated `app:eventstore:hashchain`
command); `app:eventstore:verify` audits the chain in pure SQL and exits non-zero at the first
tampered, deleted or reordered row.

### Everything comes from the model — no manual-code seam

There is deliberately **no** hand-written-code escape hatch in the generated apps. Behavior that
would tempt one is modeled instead, in agreement with ESDM philosophy:

- Reactions ("whenever X happened, do Y") are **policies** — model documents, generated by every
  codegen of the family.
- Multi-step flows with external answers are modeled as processes: the answer comes back as a
  command and becomes an event.
- Integrations that leave the system (brokers, mail, external APIs) consume the **event stream**
  downstream; every state change is already an event, so consumers need nothing from the
  generated app.

This keeps the whole behavioral surface inside the model→code equivalence the codegens are held
to — nothing per-target, nothing written twice, nothing invisible to the model. (A per-aggregate
command/projection hook seam existed briefly and was removed for exactly this reason.)

## Quick start (everything is dockerized)

**From a BPMN diagram** — the headline path: a drawn process becomes a running app.

```sh
# 1. compile the BPMN to ESDM, then generate the Symfony app
docker compose run --rm codegen bpmn:map examples/orders    # authoring/order.bpmn → model/*.yaml
docker compose run --rm codegen generate examples/orders    # ESDM → runnable project

# 2. run it: PostgreSQL + write/query API + async projection worker
cd examples/orders/generated/symfony && docker compose up -d --build

# 3. drive the lifecycle (each command is event-sourced; read side is an async projection)
curl -s -XPOST localhost:8080/orders/place-order -d '{"customerName":"Acme","total":100}'
# or visually: run the esdm-vue-reader viewer and connect it to http://localhost:8080 —
# its Author (BPMN) tab shows the very diagram the app was generated from
```

**From an ESDM model** — for the YAML-authored example apps (todo, manufacturing):

```sh
docker compose run --rm codegen generate examples/todo
cd examples/todo/generated/symfony && docker compose up -d --build
curl -s -XPOST localhost:8080/tasks/add-task -d '{"title":"Buy milk"}'   # write side (event sourced)
curl -s localhost:8080/tasks/list-tasks                                  # read side (projection)
docker compose run --rm --no-deps api vendor/bin/phpunit                 # GWT scenarios as tests
```

For the visual tour, point the **esdm-vue-reader** viewer (sibling repo) at
`http://localhost:8080` — the app serves it the 0004 catalog, event stream and diagram, and the
generated stack allows CORS in dev so the viewer connects from its own origin. You can also run
the generator without Docker (`composer install && php bin/esdmgen generate examples/todo`) — it only
needs pure-PHP dependencies.

## Example apps (`examples/`)

Each app is a self-contained model plus its generated output: the first two are written as ESDM
YAML, the rest are **drawn as BPMN** (under `authoring/`) and compiled with `bpmn:map`. They get
progressively more complex to push the generator further.

| App                      | Demonstrates                                                                                                                                                                                                                                                                                                                                                                               |
|--------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `examples/todo`          | one aggregate; commands/events; **two read models** from one log (active + a `deleted-tasks` archive); GWT scenarios; the 0004 console contract                                                                                                                                                                                                                                            |
| `examples/manufacturing` | RFQ→quote: **two bounded contexts** (intake, quoting), two aggregates, an event-driven **`policy`** (a website request auto-drafts a quote), numeric fields, status lifecycle, GWT                                                                                                                                                                                                         |
| `examples/orders`        | **authored as BPMN** (proposal 0003): `authoring/order.bpmn` → `esdmgen bpmn:map` → ESDM → code. State machine + FEEL guard (`ship-order when paidAmount >= total`) are derived from the flow graph + a condition expression                                                                                                                                                               |
| `examples/commerce`      | a two-pool collaboration (Sales + Warehouse) → **two contexts/aggregates**, two state machines, two FEEL guards, and a **cross-pool message flow → `policy`** (approving an order auto-registers a shipment)                                                                                                                                                                               |
| `examples/factory`       | **the big one** — a five-pool collaboration (Sales · Production · Quality Control · Warehouse · Accounting) → **5 contexts/aggregates**, 5 state machines, FEEL guards, a **quality-gate loop** (fail → rework → re-inspect) and an XOR credit decision, plus a **4-policy chain** threading an order all the way to a settled invoice. One `factory.bpmn`, editable in the **Author** tab |

> Generated code lives in `examples/<app>/generated/` and is disposable — never edit it by
> hand; change the model and regenerate.

## Authoring from BPMN (proposal 0003)

For a non-programmer audience, the ESDM model itself can be *generated* from a **BPMN** diagram
(drawn in any bpmn.io / Camunda Modeler editor) instead of hand-written. `esdmgen bpmn:map`
decomposes the diagram into the three ESDM streams — core, [0001] state machine, [0002] FEEL —
and writes them to `model/`, ready for `generate`:

```sh
docker compose run --rm codegen bpmn:map examples/orders   # examples/orders/authoring/*.bpmn → examples/orders/model/*.yaml
docker compose run --rm codegen generate examples/orders   # ESDM → runnable app (as usual)
```

The lifecycle and guards are read from the **sequence-flow graph**: a command's admissible source
states are the resulting states of its predecessor tasks, final states are those with no
downstream task, and a flow `conditionExpression` becomes a FEEL guard. Each **pool** becomes a
bounded-context + aggregate, and a **message flow across pools** becomes a `policy` (an event in
one context dispatching a command in another). Things BPMN cannot express (aggregate state names,
field types) ride on small `esdm:` extension hints. The diagram is editable in the
esdm-vue-reader viewer's **Author** tab. See `examples/commerce/authoring/commerce.bpmn` (the
two-pool example) and [proposal 0003 in esdm-extensions](https://github.com/r-sw-eet/esdm-extensions/blob/main/proposals/0003-bpmn-to-esdm-mapper.md).

## Layout

```
src/Lint/       esdm lint gate — validates the model before generation
src/Model/      parse ESDM YAML → resolved, framework-agnostic model
src/Feel/       FEEL subset compiler (proposal 0002) — parser, compiler, validator
src/Bpmn/       BPMN → ESDM mapper (proposal 0003) — parser + decomposition
src/Adapter/    target adapters (symfony-patchlevel-postgres, symfony-eventsourcingdb)
src/Console/    the `esdmgen` CLI (generate, targets, bpmn:map)
bin/esdmgen     CLI entrypoint
examples/<name>/    model/ (ESDM input) · authoring/ (optional BPMN) · esdmgen.yaml · generated/<stack>/ (output)
docker/Dockerfile  the generator's own container image
compose.yaml    runs the dockerized generator
```

## Status

All five example apps are generated and **verified end-to-end in Docker**: commands append events
to the PostgreSQL event store, the worker projects them asynchronously into read-model tables, and
the query API reads them back (eventually consistent). The BPMN-authored apps go the whole way from
a drawn diagram — `examples/factory` runs a single order across **five bounded contexts** (sales →
production → quality control → warehouse → accounting), through a **quality-gate loop** and a
**four-policy chain**, ending in a settled invoice; every state guard and FEEL condition was
checked live.

Redis (snapshots) and a message broker (RabbitMQ / Symfony Messenger) are deliberately **not**
required by this target — Postgres alone is the event store, projection store and subscription
cursor. They remain opt-in capabilities for future adapters.

## License

[MIT](LICENSE) © 2026 Ralf Süss
