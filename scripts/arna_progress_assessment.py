#!/usr/bin/env python3
"""Read-only ARNA repository assessment; writes Markdown reports only."""
from __future__ import annotations
import datetime as dt
import json
import os
import pathlib
import urllib.error
import urllib.parse
import urllib.request
from zoneinfo import ZoneInfo

ROOT = pathlib.Path(__file__).resolve().parents[1]
SCORECARD = ROOT / "docs/operations/arna-progress-scorecard.json"
OUT = ROOT / "docs/progress"
REPOS = ["akefadaka-git/arna-api", "akefadaka-git/arna-app"]
API = "https://api.github.com"
TOKEN = os.getenv("GITHUB_TOKEN", "")
HEADERS = {"Accept": "application/vnd.github+json", "X-GitHub-Api-Version": "2022-11-28", "User-Agent": "ARNA-nightly-assessment"}
if TOKEN:
    HEADERS["Authorization"] = f"Bearer {TOKEN}"

def gh(path: str):
    req = urllib.request.Request(API + path, headers=HEADERS)
    with urllib.request.urlopen(req, timeout=30) as response:
        return json.loads(response.read().decode("utf-8"))

def get(path: str):
    try:
        return gh(path)
    except Exception as exc:
        return {"_error": str(exc)}

def parse_time(value):
    if not value:
        return None
    try:
        return dt.datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError:
        return None

def pct(scorecard):
    weights = scorecard["overall_planning_weights"]
    phases = scorecard["phases"]
    denom = sum(weights[p["id"]] for p in phases)
    return round(sum(p["completion"] * weights[p["id"]] for p in phases) / denom) if denom else 0

def assess_repo(name, now):
    encoded = urllib.parse.quote(name, safe="/")
    meta = get(f"/repos/{encoded}")
    if "_error" in meta:
        return {"name": name, "error": meta["_error"]}
    prs_raw = get(f"/repos/{encoded}/pulls?state=all&sort=updated&direction=desc&per_page=100")
    commits_raw = get(f"/repos/{encoded}/commits?per_page=30")
    runs_raw = get(f"/repos/{encoded}/actions/runs?per_page=100")
    api_errors = [v["_error"] for v in (prs_raw, commits_raw, runs_raw) if isinstance(v, dict) and v.get("_error")]
    prs = prs_raw if isinstance(prs_raw, list) else []
    commits = commits_raw if isinstance(commits_raw, list) else []
    runs = runs_raw if isinstance(runs_raw, dict) else {}
    run_items = runs.get("workflow_runs", [])
    cutoff30 = now - dt.timedelta(days=30)
    cutoff14 = now - dt.timedelta(days=14)
    merged30 = [p for p in prs if p.get("merged_at") and (parse_time(p["merged_at"]) or now) >= cutoff30]
    open_prs = [p for p in prs if p.get("state") == "open"]
    failed14 = [r for r in run_items if r.get("conclusion") in ("failure", "timed_out", "action_required") and (parse_time(r.get("updated_at")) or now) >= cutoff14]
    cancelled14 = [r for r in run_items if r.get("conclusion") == "cancelled" and (parse_time(r.get("updated_at")) or now) >= cutoff14]
    in_progress = [r for r in run_items if r.get("status") in ("queued", "in_progress")]
    commit_dates = [parse_time(c.get("commit", {}).get("committer", {}).get("date")) for c in commits]
    commit_dates = [x for x in commit_dates if x]
    last_commit = max(commit_dates) if commit_dates else None
    return {
        "name": name, "default_branch": meta.get("default_branch"), "visibility": meta.get("visibility"),
        "open_prs": len(open_prs), "open_pr_titles": [p.get("title", "") for p in open_prs[:12]],
        "merged_prs_30d": len(merged30), "merged_pr_titles_30d": [p.get("title", "") for p in merged30[:12]],
        "failed_workflows_14d": len(failed14), "failed_workflow_names": [r.get("name", "workflow") + " #" + str(r.get("run_number", "")) for r in failed14[:12]],
        "cancelled_workflows_14d": len(cancelled14), "in_progress_workflows": len(in_progress),
        "last_commit": last_commit.isoformat() if last_commit else None,
        "commit_age_days": (now - last_commit).days if last_commit else None,
        "api_errors": api_errors
    }

def bullets(items, empty):
    return "\n".join(f"- {x}" for x in items) if items else f"- {empty}"

def main():
    now = dt.datetime.now(dt.timezone.utc)
    local = now.astimezone(ZoneInfo("Africa/Addis_Ababa"))
    scorecard = json.loads(SCORECARD.read_text(encoding="utf-8"))
    overall = pct(scorecard)
    results = [assess_repo(r, now) for r in REPOS]
    recs = []
    for r in results:
        if r.get("error"):
            recs.append(f"**{r['name']}:** repository data unavailable; resolve access/API error before drawing conclusions.")
            continue
        if r["failed_workflows_14d"]:
            recs.append(f"**{r['name']}:** triage {r['failed_workflows_14d']} failed/action-required workflow run(s) from the last 14 days; inspect exact run logs and repair only with authorization.")
        if r["cancelled_workflows_14d"]:
            recs.append(f"**{r['name']}:** inspect {r['cancelled_workflows_14d']} cancelled workflow run(s); determine whether they represent superseded work or missing verification.")
        if r["open_prs"]:
            recs.append(f"**{r['name']}:** review {r['open_prs']} open pull request(s) against acceptance criteria; do not equate open PR count with completion.")
        if r["commit_age_days"] is not None and r["commit_age_days"] > 7:
            recs.append(f"**{r['name']}:** no recent commit in {r['commit_age_days']} days; confirm whether work is intentionally paused.")
        if not any([r["failed_workflows_14d"], r["cancelled_workflows_14d"], r["open_prs"]]):
            recs.append(f"**{r['name']}:** no immediate issue detected in the sampled PR/workflow metadata; this is not a code-quality or production-readiness certification.")
    if not recs:
        recs = ["Repository evidence could not support specific recommendations; inspect API errors and report UNKNOWN rather than assuming success."]
    lines = [
        f"# ARNA OS — Daily Repository Progress Report ({local.date().isoformat()})", "",
        f"**Generated:** {local.isoformat()}  ",
        f"**Repositories:** {', '.join(REPOS)}  ",
        "**Assessment mode:** read-only repository metadata and workflow evidence; no source-code mutation.", "",
        "## Executive snapshot", "",
        f"- **Weighted planning completion:** {overall}% (Owner-maintained scorecard; not inferred from commit volume).",
        f"- **Current engineering rating baseline:** 5.8/10 (qualitative; unchanged unless separately reviewed).",
        f"- **Strategic potential baseline:** 9.0/10 (aspirational assessment, not delivered capability).",
        "- **Evidence status:** repository metadata is sampled live; code-level acceptance criteria are not automatically certified.", "",
        "## Phase completion", "",
        "| Phase | Scope | Completion |", "|---|---|---:|"
    ]
    for p in scorecard["phases"]:
        lines.append(f"| {p['id']} | {p['name']} | {p['completion']}% |")
    lines += ["", "## Domain completion", "", "| Domain | Completion | Rating |", "|---|---:|---:|"]
    for d in scorecard["domains"]:
        lines.append(f"| {d['name']} | {d['completion']}% | {d['rating']:.1f}/10 |")
    lines += ["", "## Live repository evidence", ""]
    for r in results:
        lines += [f"### {r['name']}", ""]
        if r.get("error"):
            lines += [f"- **UNKNOWN:** {r['error']}", ""]
            continue
        lines += [
            f"- Default branch: `{r.get('default_branch')}`",
            f"- Open PRs: {r['open_prs']}",
            f"- PRs merged in last 30 days: {r['merged_prs_30d']}",
            f"- Failed/action-required workflow runs in last 14 days: {r['failed_workflows_14d']}",
            f"- Cancelled workflow runs in last 14 days: {r['cancelled_workflows_14d']}",
            f"- Currently queued/in-progress workflow runs in sampled page: {r['in_progress_workflows']}",
            f"- Most recent commit in sampled 30 commits: {r['last_commit'] or 'UNKNOWN'}",
            "", "**Open pull requests (sample, up to 12):**",
            bullets(r['open_pr_titles'], "No open PRs found in the sampled page."),
            "", "**PRs merged in last 30 days (sample, up to 12):**",
            bullets(r['merged_pr_titles_30d'], "No merged PRs found in the sampled page."),
            "", "**Failed workflows (sample, up to 12):**",
            bullets(r['failed_workflow_names'], "No failed/action-required runs found in the sampled page."),
            ""
        ]
        if r.get("api_errors"):
            lines += ["**Data limitations:**", bullets(r["api_errors"], "None"), ""]
    lines += ["## Recommendations and enhancement priorities", ""]
    lines += [f"- {x}" for x in recs]
    lines += [
        "- Continue closing domain acceptance gaps before expanding scope: security, tenant isolation, lifecycle, concurrency, clean-install and cross-domain integration.",
        "- Prioritize Finance accounting integrity, Governance authority lifecycle, Calendar reservations/concurrency and Communication approval/publishing controls.",
        "- Improve frontend/API contract tests and production observability; require reproducible evidence for every readiness claim.",
        "- Compare ARNA with Odoo, ERPNext, SAP S/4HANA, Dynamics 365, Salesforce and Microsoft 365/Google Workspace using capability fit, not unsupported numerical competitor scores.",
        "", "## Competitive benchmark", "",
        "| System | Benchmark focus | ARNA assessment |",
        "|---|---|---|"
    ]
    for b in scorecard["benchmarks"]:
        lines.append(f"| {b['system']} | {b['category']} | {b['comparison']} |")
    lines += [
        "", "## Evidence classification and limitations", "",
        "- **VERIFIED:** direct repository/workflow evidence supports the statement.",
        "- **PARTIAL:** evidence covers only part of the capability or acceptance criteria.",
        "- **MISSING:** required implementation/evidence was not found in sampled sources.",
        "- **UNKNOWN:** API/access/sampling limitations prevent a conclusion.",
        "- **NOT RUN:** expected test or workflow did not execute.",
        "- **BLOCKED:** prerequisite or permission prevents assessment.",
        "",
        "The scorecard percentages are intentionally Owner-maintained. This automation does not increase them based on commit, PR or workflow counts. Change percentages only through an evidence-backed, Owner-reviewed scorecard update.",
        "The assessment samples at most 100 PRs, 100 workflow runs and 30 commits per repository per run. It is not a full source-code audit, penetration test, release certification or deployment check.",
        "", "## Next action", "",
        "Review failed/cancelled workflow evidence and the highest-risk open PRs; record any accepted milestone changes in the scorecard through the normal Owner approval process.",
        ""
    ]
    body = "\n".join(lines)
    OUT.mkdir(parents=True, exist_ok=True)
    daily = OUT / "daily" / f"{local.date().isoformat()}.md"
    daily.parent.mkdir(parents=True, exist_ok=True)
    latest = OUT / "latest.md"
    daily.write_text(body, encoding="utf-8")
    latest.write_text(body, encoding="utf-8")
    print(f"Wrote {daily.relative_to(ROOT)} and {latest.relative_to(ROOT)}; weighted scorecard={overall}%")

if __name__ == "__main__":
    main()
