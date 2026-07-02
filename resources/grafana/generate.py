#!/usr/bin/env python3
"""Regenerates the bundled Statamic Grafana dashboard (dashboards/*.json).

Run after changing panels:  python3 resources/grafana/generate.py

One dashboard joining cboxdk/laravel-telemetry's Nightwatch-style suite:
it carries the same "telemetry" tag, so it appears as a tab in every
suite dashboard (and they in it). Helpers and visual language are copied
from the core suite's generator — keep them in sync when the suite's
conventions change.

Visual language:
  green = healthy/hit · orange = warn/miss/invalidate · red = error/flush
  smooth gradient lines, soft-zero axes, shared crosshair, section rows.
"""

import json
import os

OUT = os.path.join(os.path.dirname(__file__), "dashboards")
PROM = {"type": "prometheus", "uid": "prometheus"}
TEMPO = {"type": "tempo", "uid": "tempo"}

REQ = "http_server_request_duration_milliseconds"
SC = "statamic_static_cache_operations_total"
SVC = 'service_name=~"$service"'

_id = 0


def nid():
    global _id
    _id += 1
    return _id


def target(expr, legend="__auto", instant=False, fmt=None):
    t = {"refId": "Q" + str(nid()), "expr": expr, "legendFormat": legend, "datasource": PROM}
    if instant:
        t.update({"instant": True, "range": False})
    if fmt:
        t["format"] = fmt
    return t


def color_over(mapping, regex=False):
    """Fixed semantic colors per series (by legend name or regex)."""
    return [{
        "matcher": {"id": "byRegexp" if regex else "byName", "options": key},
        "properties": [{"id": "color", "value": {"mode": "fixed", "fixedColor": color}}],
    } for key, color in mapping.items()]


def ok_at(steps):
    return {"mode": "absolute", "steps": [{"color": "green", "value": None}, *steps]}


def warn_at(value, color="red"):
    return ok_at([{"color": color, "value": value}])


def stat(title, expr, x, y, w=4, unit="short", thresholds=None, bg=False, decimals=1, description=None, zero=False):
    if zero:
        expr = f"({expr}) or vector(0)"
    panel = {
        "id": nid(), "type": "stat", "title": title, "datasource": PROM,
        "gridPos": {"h": 4, "w": w, "x": x, "y": y},
        "targets": [target(expr)],
        "fieldConfig": {"defaults": {
            "unit": unit, "decimals": decimals,
            "thresholds": thresholds or {"mode": "absolute", "steps": [{"color": "text", "value": None}]},
        }, "overrides": []},
        "options": {
            "reduceOptions": {"calcs": ["lastNotNull"]},
            "colorMode": "background" if bg else "value",
            "graphMode": "area", "justifyMode": "auto", "textMode": "auto",
        },
    }
    if description:
        panel["description"] = description
    return panel


def timeseries(title, targets, x, y, w=12, h=8, unit="short", stacked=False,
               colors=None, regex_colors=None, legend="list", threshold_line=None,
               description=None):
    overrides = []
    if colors:
        overrides += color_over(colors)
    if regex_colors:
        overrides += color_over(regex_colors, regex=True)

    defaults = {
        "unit": unit, "min": 0,
        "custom": {
            "drawStyle": "line", "lineInterpolation": "smooth", "lineWidth": 2,
            "fillOpacity": 18, "gradientMode": "opacity", "showPoints": "never",
            "spanNulls": True, "axisSoftMin": 0,
            "stacking": {"mode": "normal" if stacked else "none"},
        },
    }

    if threshold_line is not None:
        defaults["custom"]["thresholdsStyle"] = {"mode": "dashed"}
        defaults["thresholds"] = warn_at(threshold_line)

    panel = {
        "id": nid(), "type": "timeseries", "title": title, "datasource": PROM,
        "gridPos": {"h": h, "w": w, "x": x, "y": y}, "targets": targets,
        "fieldConfig": {"defaults": defaults, "overrides": overrides},
        "options": {
            "legend": ({"displayMode": "table", "placement": "bottom", "calcs": ["mean", "max"]}
                       if legend == "table" else {"displayMode": "list", "placement": "bottom"}),
            "tooltip": {"mode": "multi", "sort": "desc"},
        },
    }
    if description:
        panel["description"] = description
    return panel


def table(title, expr, x, y, w=12, h=8, unit="short", description=None, decimals=0):
    panel = {
        "id": nid(), "type": "table", "title": title, "datasource": PROM,
        "gridPos": {"h": h, "w": w, "x": x, "y": y},
        "targets": [target(expr, instant=True, fmt="table")],
        "transformations": [{"id": "organize", "options": {"excludeByName": {"Time": True}}}],
        "fieldConfig": {"defaults": {"unit": unit, "decimals": decimals,
                                     "custom": {"align": "auto", "filterable": False}}, "overrides": []},
        "options": {"sortBy": [{"displayName": "Value", "desc": True}],
                    "cellHeight": "md", "footer": {"show": False}},
    }
    if description:
        panel["description"] = description
    return panel


def traces(title, query, x, y, w=24, h=9, description=None):
    panel = {
        "id": nid(), "type": "table", "title": title, "datasource": TEMPO,
        "gridPos": {"h": h, "w": w, "x": x, "y": y},
        "targets": [{"refId": "A", "datasource": TEMPO, "queryType": "traceql",
                     "query": query, "limit": 20, "spss": 3, "tableType": "traces"}],
        "fieldConfig": {"defaults": {"custom": {"filterable": False}}, "overrides": []},
        "options": {"cellHeight": "sm"},
    }
    if description:
        panel["description"] = description
    return panel


def row(title, y):
    return {"id": nid(), "type": "row", "title": title, "collapsed": False,
            "gridPos": {"h": 1, "w": 24, "x": 0, "y": y}, "panels": []}


def qvar(name, metric, label):
    return {"name": name, "type": "query", "datasource": PROM,
            "query": {"query": f"label_values({metric}, {label})", "refId": name},
            "includeAll": True, "allValue": ".*", "multi": False, "refresh": 2,
            "current": {"text": "All", "value": "$__all"}}


def dashboard(uid, title, panels, variables=None):
    return {
        "uid": uid, "title": title, "tags": ["telemetry", "cboxdk", "statamic"],
        "timezone": "browser", "schemaVersion": 39, "refresh": "30s",
        "graphTooltip": 1,  # shared crosshair across panels
        "time": {"from": "now-1h", "to": "now"},
        "templating": {"list": [qvar("service", f"{REQ}_count", "service_name"), *(variables or [])]},
        # Join the core suite's Nightwatch nav: every telemetry-tagged
        # dashboard as a tab.
        "links": [{"type": "dashboards", "tags": ["telemetry"], "asDropdown": False,
                   "includeVars": True, "keepTime": True, "title": ""}],
        "panels": panels, "editable": True,
    }


OUTCOME_COLORS = {"hit": "green", "miss": "orange", "write": "blue",
                  "invalidate": "orange", "flush": "red"}

statamic = dashboard("cbox-tel-statamic", "Statamic", [
    row("Static cache", 0),
    stat("Hit ratio",
         f'100 * sum(rate({SC}{{{SVC},operation="hit"}}[10m]))'
         f' / sum(rate({SC}{{{SVC},operation=~"hit|miss"}}[10m]))',
         0, 1, unit="percent", decimals=0,
         thresholds=ok_at([{"color": "orange", "value": 0}, {"color": "green", "value": 70}]),
         description="Static cache hits vs misses over 10m. Full-measure hits served by the web server never reach PHP and are not counted."),
    stat("Hits / min", f'sum(rate({SC}{{{SVC},operation="hit"}}[5m])) * 60', 4, 1, decimals=0, zero=True),
    stat("Writes / min", f'sum(rate({SC}{{{SVC},operation="write"}}[5m])) * 60', 8, 1, decimals=0, zero=True),
    stat("Invalidations / h", f'sum(increase({SC}{{{SVC},operation="invalidate"}}[1h]))', 12, 1, decimals=0, zero=True,
         thresholds=warn_at(100, "orange")),
    stat("Flushes (24h)", f'sum(increase({SC}{{{SVC},operation="flush"}}[24h]))', 16, 1, decimals=0, zero=True,
         thresholds=warn_at(1, "orange"),
         description="A full flush empties the static cache — every page re-renders. More than an occasional one is worth investigating."),
    stat("Stache warms (24h)", f'sum(increase(statamic_stache_warms_total{{{SVC}}}[24h]))', 20, 1, decimals=0, zero=True),
    timeseries("Static cache: hits, misses, writes", [
        target(f'sum(rate({SC}{{{SVC},operation="hit"}}[$__rate_interval])) * 60', 'hit'),
        target(f'sum(rate({SC}{{{SVC},operation="miss"}}[$__rate_interval])) * 60', 'miss'),
        target(f'sum(rate({SC}{{{SVC},operation="write"}}[$__rate_interval])) * 60', 'write'),
    ], 0, 5, unit="opm", colors=OUTCOME_COLORS),
    timeseries("Static cache: invalidations & flushes", [
        target(f'sum(rate({SC}{{{SVC},operation="invalidate"}}[$__rate_interval])) * 60', 'invalidate'),
        target(f'sum(rate({SC}{{{SVC},operation="flush"}}[$__rate_interval])) * 60', 'flush'),
    ], 12, 5, unit="opm", colors=OUTCOME_COLORS,
        description="Spikes here explain miss/write spikes on the left — saves fan out invalidations by rule."),

    row("Stache", 13),
    timeseries("Stache cache traffic by key group", [
        target(f'sum by (key_group) (rate(cache_operations_total{{{SVC},key_group=~"stache.*"}}[$__rate_interval])) * 60',
               '{{key_group}}'),
    ], 0, 14, unit="opm",
        description="Requires the core cache instrumentation (telemetry.instrument.cache). Key groups come from this addon's classifier: stache.index, stache.item, stache.meta."),
    timeseries("Stache warm duration", [
        target(f'histogram_quantile(0.95, sum by (le) (rate(statamic_stache_warm_duration_milliseconds_bucket{{{SVC}}}[$__rate_interval])))', 'p95'),
        target(f'sum(rate(statamic_stache_warm_duration_milliseconds_sum{{{SVC}}}[$__rate_interval]))'
               f' / sum(rate(statamic_stache_warm_duration_milliseconds_count{{{SVC}}}[$__rate_interval]))', 'avg'),
    ], 12, 14, unit="ms", colors={"p95": "orange", "avg": "green"},
        description="Full warms only (stache:warm / warm after clear). A growing warm time tracks content volume."),

    row("Content & editors", 22),
    timeseries("Content changes", [
        target(f'sum by (type, action) (rate(statamic_content_changes_total{{{SVC}}}[$__rate_interval])) * 60',
               '{{type}} {{action}}'),
    ], 0, 23, w=8, unit="opm", stacked=True, legend="table"),
    timeseries("Form submissions", [
        target(f'sum by (form) (rate(statamic_forms_submissions_total{{{SVC}}}[$__rate_interval])) * 60', '{{form}}'),
    ], 8, 23, w=8, unit="opm"),
    timeseries("Glide generations", [
        target(f'sum by (preset) (rate(statamic_glide_generations_total{{{SVC}}}[$__rate_interval])) * 60', '{{preset}}'),
    ], 16, 23, w=8, unit="opm",
        description="Sustained generation traffic means the Glide cache is being missed — presets bound the label; ad-hoc params group under 'custom'."),

    row("Inventory (opt-in gauges)", 31),
    table("Entries by collection",
          f'sum by (collection) (statamic_entries_count{{{SVC}}})',
          0, 32, w=8,
          description="Requires statamic-telemetry.gauges.enabled — evaluated at scrape time."),
    table("Assets by container",
          f'sum by (container) (statamic_assets_count{{{SVC}}})',
          8, 32, w=8),
    stat("Users", f'sum(statamic_users_count{{{SVC}}})', 16, 32, w=8, decimals=0),

    row("Traces", 40),
    traces("Recent content requests",
           '{resource.service.name=~"$service" && name=~"GET (entry|term):.*"}',
           0, 41, h=9,
           description="Root spans named by this addon — entry:{collection}.{blueprint} / term:{taxonomy}."),
    traces("Slow uncached pages",
           '{resource.service.name=~"$service" && span.statamic.static_cache="miss" && duration > 500ms}',
           0, 50, h=9,
           description="Static cache misses that took over 500ms to render — the pages that hurt when the cache is cold."),
])


os.makedirs(OUT, exist_ok=True)

with open(os.path.join(OUT, "telemetry-statamic.json"), "w") as fh:
    json.dump(statamic, fh, indent=2)
    fh.write("\n")

print("wrote dashboards/telemetry-statamic.json")
