# MirvMon v0.7.0 — proactive observations design

## Problem

Threshold alerts answer "the configured boundary has already been crossed". They
cannot answer a different operational question: "this server still fits inside
the allowed limits, but it no longer behaves like itself". MirvMon already keeps
raw metrics plus hourly/daily aggregates, so v0.7.0 adds a server-side analytical
layer without changing the native-agent protocol.

Observations are deliberately not incidents. They are advisory, explainable and
operator-trainable. The first release solves two concrete jobs:

- notice sustained or recurrent upward CPU/RAM level shifts below warning;
- forecast disk growth early enough to clean or extend storage before an alert.

## Domain model

`observations` is a separate table and read model. A row identifies one detector
fingerprint for one server. It keeps the current evidence, lifecycle timestamps,
notification cycle and operator feedback.

Kinds:

- `anomaly`: unusual behavior relative to a server-local baseline;
- `prediction`: a trend expected to require maintenance soon.

Statuses:

- `active`: currently deserves attention;
- `handled`: operator handled a prediction; it stays quiet until the condition
  disappears and rearms;
- `accepted_normal`: operator explicitly taught MirvMon that this anomaly
  fingerprint is normal;
- `resolved`: detector condition disappeared.

The accepted-normal row itself is the durable learned pattern. There is no hidden
opaque model that an operator cannot inspect or undo.

## Detector 1: CPU/RAM contextual level shift

v0.7.3 replaces the original all-hours `level_shift_v1` baseline with
`level_shift_v2`. The historical source is up to 56 days of hourly aggregates,
interpreted in `APP_TIMEZONE`. The detector chooses the most specific mature
context available:

1. same ISO weekday and local hour, with an hour-distance window of ±1;
2. same weekday/weekend type and local hour ±1;
3. same local hour-of-day ±1.

A global all-hours median is deliberately not an anomaly-producing fallback. If
none of the contextual profiles has enough coverage, the detector returns
`insufficient_data` and learns from more history instead of inventing a normal
level.

Each chosen profile preserves within-hour variability: its lower envelope is the
p10 of hourly minima, median is computed from hourly averages, and upper envelope
is the p90 of hourly maxima. Fresh raw samples are aggregated into 5-minute
buckets over the recent two hours. This avoids treating a normal recurring
25–70% workload as a narrow ~45% profile merely because history was averaged. Triggering still
requires a material shift above the contextual upper distribution and either
sustained or recently recurrent evidence.

Lifecycle is explicitly hysteretic and separate from triggering:

- `triggered`: enough recent buckets exceed the trigger boundary;
- `elevated`: the strict trigger is not currently met, but recovery is not proven;
- `incident_owned`: the configured warning threshold has been reached, so the
  normal incident pipeline owns urgency while the observation episode stays open;
- `clear`: twelve consecutive 5-minute buckets (one hour) are below the recovery
  boundary;
- `insufficient_data`: the current analysis cannot prove either trigger or
  recovery.

Recovery is evaluated against the current contextual recovery boundary, but only
after twelve consecutive 5-minute buckets satisfy it. Therefore a scheduled
night→day profile change can legitimately end a night anomaly after one stable
hour, while a context boundary can never close an episode instantly.

`accepted_normal` remains explicit and reversible. A v2 accepted pattern is
matched only in a comparable weekday/hour context and bounded value range. Older
v1 accepted fingerprints are retained as compatibility hints, so operator
feedback from v0.7.0–v0.7.2 is not silently discarded.

## Detector 2: disk growth forecast

Source: hourly `disk_used_*` history plus the latest current value.

Before fitting a trend, v0.7.1 coalesces mount aliases that are strong evidence of the same filesystem: reported `disk_total_gb_*`, current usage and at least one day of overlapping hourly history must match within tight tolerances. `disk_used_root` is preferred as the canonical metric; otherwise the stable metric-name order is used. Different filesystem sizes or diverging histories are never collapsed.

Before fitting a trend, find the latest material downward step (a cleanup or
storage expansion) and discard the older segment. The remaining segment must
cover enough time and points. Fit an ordinary least-squares line to percentage
used versus elapsed days and calculate R².

Create a prediction only when:

- current usage is still below warning threshold;
- slope is positive and operationally meaningful;
- the fit has acceptable confidence;
- warning threshold and/or 100% is forecast inside the proactive horizon.

Persist slope, R², segment duration and both forecast times in observation
`details` so the UI and notification can explain the conclusion.

## Lifecycle and deduplication

The worker evaluates the complete detector set per run and records the observation
IDs actually seen plus the `server + metric` pairs for which enough current data
was evaluated. A row may auto-resolve only after its own metric was evaluated and
the row was absent. Missing input because of maintenance, DR downtime or another
observation gap is not evidence that the condition disappeared.

Anomaly episode semantics (v0.7.2 identity, v0.7.3 contextual recovery):

1. the fingerprint describes a behavior pattern (day-part/value band), not the
   identity of the current episode;
2. while an `active` or `handled` anomaly exists for the same
   `server + metric + detector`, later bands refresh that row and never enqueue a
   second notification;
3. `handled` means the operator reviewed this episode; it stays quiet while the
   episode is `triggered`, `elevated` or `incident_owned`;
4. `level_shift_v2` recovery is explicit `clear` after one hour below the current
   contextual recovery boundary; generic 20-minute candidate absence never resolves v2;
5. after recovery, a later independent episode creates a new row and may notify
   again, even when its pattern fingerprint matches an older resolved episode;
6. `accepted_normal` is separate from acknowledgement: v2 limits suppression to
   comparable weekday/hour context and bounded load range, so different context
   can still surface;
7. migration 025 collapses old band duplicates; migration 026 retires open v1
   episodes while preserving accepted-normal v1 feedback as compatibility hints.

Prediction fingerprint:

1. insert/reopen as `active`, incrementing `notification_cycle` on a genuinely
   new cycle;
2. enqueue once using observation id + cycle as the outbox deduplication key;
3. `handled` hides the current task and does not reopen while the detector still
   sees the same condition;
4. once the condition disappears it becomes `resolved` and rearms;
5. the next independent fill cycle can notify again.

## Notifications

Reuse the existing server recipient resolution and transport outbox. Add an
observation-specific enqueue entry point that permits `alert_id = NULL` and does
not create a fake alert. Maintenance suppresses delivery the same way it does for
incidents. Formatting includes the evidence needed to understand the recommendation. With `PUBLIC_BASE_URL` configured, server-bound notifications include a direct server-detail link; observation messages additionally link to `/observations#observation-{id}`.

## Runtime

`bin/observation-worker` runs inside the existing `app` container under
supervisord, takes the shared DR maintenance lock, reconnects after transient DB
loss, records a worker heartbeat, and executes on a several-minute cadence. No
third Compose service is introduced.

The data path is bulk-oriented: detector input is loaded in a small number of
queries, never one query per server. This matters because MirvMon already has a
1000-server dashboard performance target.

## Operator UI

`/observations` has three operational views:

- Active — current advisory work;
- History — resolved/handled cycles;
- Normal behavior — fingerprints explicitly accepted by operators.

An active anomaly exposes both "Проверено" (acknowledge only this episode) and "Считать это нормальным" (learn this pattern). A prediction exposes "Обработано".
Accepted-normal rows expose "Снова анализировать". All mutations require
operator capability and CSRF protection, and successful operator feedback is recorded
in the append-only audit log with the observation identity and state transition.

## Safety / non-goals

v0.7.0 does not change thresholds, restart services, delete files, call an LLM,
or infer root cause from process names. A false positive can annoy an operator;
an automatic remediation can damage a server, so remediation is deliberately out
of scope.

## Delivery slices

1. Data/state-machine foundation and pure analyzer tests.
2. Timescale bulk reads + worker + heartbeat/DR integration.
3. Outbox notification support.
4. UI/actions/learned-normal management.
5. Full integration/performance/docs/release gate.
