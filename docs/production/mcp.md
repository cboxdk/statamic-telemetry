---
title: Optional MCP integration
description: Observe MCP v4 actions without granting access or changing write outcomes
weight: 33
---

# Optional MCP integration

The adapter requires separately installed `cboxdk/statamic-mcp` v4 and
`cboxdk/laravel-telemetry` 3.9.3 or newer. Enable it with
`STATAMIC_TELEMETRY_MCP_ENABLED=true`. It is disabled by default and does not
register MCP tools, read permissions or approval channels. Missing MCP leaves
ordinary Statamic telemetry working; an older SDK leaves the adapter inactive
and emits a configuration warning.

## Signals

| Signal | Meaning | Labels / attributes added by this adapter |
|---|---|---|
| `statamic.mcp.execute` span | Actual handler execution | `mcp.action`, `mcp.action_version`, `mcp.transport`, `mcp.outcome`, `mcp.effects` |
| `statamic.mcp.denied` span | Refused attempt | Same metadata |
| `statamic.mcp.replayed` span | Previously stored result returned without another write | Same metadata |
| `statamic.mcp.calls` counter | Terminal attempts, including denial and replay | action, transport, outcome, effects |
| `statamic.mcp.duration` histogram | Attempt duration in seconds | action, transport, outcome, effects |
| `statamic.mcp.grants` counter | Issuance, revocation or refresh replay detected | event, transport |

Action labels come from registered action identifiers, never content handles or
URLs. Operation, execution, grant, user and client identifiers do not become
metric labels. Arguments, results, credentials and exception messages are not
projected from MCP events. Native content-audit, ambient context and SDK root
hooks keep their independent settings; this adapter does not redact signals
produced by those other components.

HTTP execution spans keep the active request parent. Native Statamic content
spans become children of the action. Each outer stdio call gets an independent
trace and clears its context afterwards. Replay produces no new content-write
span. Event delivery is not exactly-once: the adapter keeps a bounded, in-memory
window of 1024 event IDs to suppress immediate duplicate delivery.

## Long-running stdio servers

`statamic-telemetry.mcp.flush_stdio` defaults to `true`: after each completed
outer call, the configured telemetry manager is flushed. Use the SDK's spool
exporter when network latency should not delay a tool response. Export failure
must not change the CMS result; delivery can still fail according to the
exporter's own retention and retry policy.

The adapter excludes `mcp:start` from native command profiling so the whole
server lifetime is not treated as one native profile. Keep application logging
and exporters off stdout: stdout carries MCP protocol frames. The adapter itself
writes no protocol output. Arbitrary host exporters and loggers remain the
operator's responsibility.

## Audit admission

MCP's registered writes require durable audit admission before their handlers run.
This is independent of telemetry and its enable flag. A failed audit admission
produces a denied attempt with no handler effects. If terminal audit fails after a
write, MCP's retained operation remains authoritative; telemetry must not encourage
a fresh write to compensate for a missing observation. Current output filtering
can fail after persistence, so inspect both `outcome` and `effects`: a failed attempt
may still report committed effects.

## Development status

The MCP v4 branch currently emits execution events from its registered-action
executor. Its legacy public routers have not yet been migrated; installing this
adapter does not instrument those calls through the new execution contract.
The development integration suite exercises actual native entry events, prepared
writes and replay, disabled/missing MCP, redaction failure and a throwing exporter.
The final v4 release must complete that migration before claiming full coverage.
