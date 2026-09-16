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

## Detector 1: CPU/RAM level shift

Baseline source: hourly aggregates from the historical window ending before the
fresh-analysis window. Use robust percentiles (`p10`, median, `p90`) and require
multiple days of history before the detector is armed.

Fresh source: 5-minute buckets from raw samples over the recent two hours.

A bucket is anomalous only when it is materially above both the historic upper
band and historic median. Absolute minimum deltas prevent tiny low-load noise
from becoming an observation; robust spread scaling prevents naturally volatile
servers from being treated like stable ones.

An observation requires either:

- sustained behavior: most buckets in the latest ~30 minutes are anomalous; or
- recurrent behavior: enough anomalous buckets occur in separated clusters over
  the recent window.

If the fresh value is already at/above the configured warning threshold, the
observation detector stands down because the normal incident pipeline owns the
problem.

Fingerprint v1 combines detector + metric + coarse value band + coarse UTC
part-of-day. This lets a recurring nightly 20% workload be accepted without
silencing a materially different 80% event. Fingerprint format is internal and
versioned by detector name so later algorithms can coexist safely.

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

The worker evaluates the complete detector set per run and records `seen`
fingerprints plus the `server + metric` pairs for which enough current data was
actually evaluated. A row may auto-resolve only after its own metric was evaluated
and its fingerprint was absent. Missing input because of maintenance, DR downtime
or another observation gap is not evidence that the condition disappeared.

New anomaly fingerprint:

1. insert `active`;
2. enqueue one observation notification;
3. later runs only refresh evidence/`last_seen_at`;
4. when unseen for the resolution grace window, mark `resolved`;
5. recurrence of the same resolved anomaly reopens the row but does not spam a
   second notification for the same learned fingerprint;
6. `accepted_normal` never reopens until the operator explicitly resets it.

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
incidents. Formatting links to `/observations` and includes the evidence needed
to understand the recommendation.

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

An anomaly exposes "Считать это нормальным". A prediction exposes "Обработано".
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
