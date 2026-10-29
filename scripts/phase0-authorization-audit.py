#!/usr/bin/env python3
"""Bounded Phase 0 effective-route and authorization evidence audit.

Consumes Laravel's runtime route:list --json output and checks selected
public routes plus File, Loan, and Governance authorization declarations.
This is a guardrail/audit, not a substitute for adversarial HTTP tests.
"""
import json
import re
import sys
from pathlib import Path

ROOT = Path.cwd()
ROUTES_FILE = Path(sys.argv[1] if len(sys.argv) > 1 else "build/phase0-route-list.json")
REPORT_FILE = Path(sys.argv[2] if len(sys.argv) > 2 else "build/phase0-authorization-report.json")
findings = []

def finding(check, ok, detail, severity="high"):
    findings.append({"check": check, "status": "PASS" if ok else "FAIL",
                     "severity": severity, "detail": detail})

try:
    routes = json.loads(ROUTES_FILE.read_text(encoding="utf-8"))
    if not isinstance(routes, list):
        raise ValueError("route:list --json output is not a JSON array")
except Exception as exc:
    print(f"Cannot parse runtime route table: {exc}", file=sys.stderr)
    sys.exit(2)

def norm_uri(value):
    return str(value or "").strip("/")

def route_matches(uri):
    wanted = norm_uri(uri)
    return [r for r in routes if norm_uri(r.get("uri")) == wanted]

def route_middleware(route):
    value = route.get("middleware", [])
    if isinstance(value, str):
        return [x.strip() for x in value.split(",") if x.strip()]
    return [str(x) for x in value] if isinstance(value, list) else []

def has_throttle(middleware):
    return any("throttle" in m.lower() or "throttlerequests" in m.lower()
               or "throttlerequestsincrementing" in m.lower() for m in middleware)

def has_sanctum_auth(middleware):
    return any(
        m == "auth:sanctum"
        or ("authenticate" in m.lower() and "sanctum" in m.lower())
        or ("auth" in m.lower() and "sanctum" in m.lower())
        for m in middleware
    )

def has_tenant_membership(middleware):
    return any(m == "tenant.membership" or "tenantmembershipmiddleware" in m.lower()
               for m in middleware)

# Runtime route list: all four public endpoints must actually exist and carry
# an effective throttle middleware. These checks intentionally fail CI if the
# currently reported gaps are still present.
public_endpoints = {
    "POST api/register": "api/register",
    "POST api/password/email": "api/password/email",
    "POST api/password/reset": "api/password/reset",
    "POST api/verify-email/resend": "api/verify-email/resend",
}
for label, uri in public_endpoints.items():
    candidates = [r for r in route_matches(uri)
                  if "POST" in str(r.get("method", "")).upper().split("|")]
    if not candidates:
        finding(f"public-throttle:{label}", False, "Route absent from effective runtime route table.")
        continue
    missing = [r for r in candidates
               if not has_throttle(route_middleware(r))]
    finding(f"public-throttle:{label}", not missing,
            "Effective throttle middleware present." if not missing else
            "Route exists but effective middleware lacks throttle; apply an explicit route limit or document a verified upstream control.")

# Every effective file-management route must inherit Sanctum and tenant membership.
file_routes = [r for r in routes if norm_uri(r.get("uri")).startswith("api/files/")]
finding("file-routes-discovered", bool(file_routes),
        f"Discovered {len(file_routes)} effective api/files routes.")
for route in file_routes:
    uri = norm_uri(route.get("uri"))
    methods = str(route.get("method", ""))
    mw = route_middleware(route)
    missing = []
    if not has_sanctum_auth(mw):
        missing.append("auth:sanctum")
    if not has_tenant_membership(mw):
        missing.append("tenant.membership")
    finding(f"file-route-middleware:{methods} {uri}", not missing,
            "Required authentication and tenant-membership middleware present." if not missing else
            f"Missing effective middleware: {', '.join(missing)}.")

# Extract an individual PHP method approximately; enough to audit direct
# authorization calls without executing business operations.
def read_text(path):
    try:
        return path.read_text(encoding="utf-8")
    except Exception as exc:
        finding(f"source-readable:{path}", False, f"Cannot read source: {exc}")
        return ""

def method_body(source, method):
    match = re.search(r"function\s+" + re.escape(method) + r"\s*\([^)]*\)[^{]*\{", source, re.S)
    if not match:
        return ""
    start = match.end()
    depth = 1
    i = start
    while i < len(source) and depth:
        if source[i] == "{":
            depth += 1
        elif source[i] == "}":
            depth -= 1
        i += 1
    return source[start:i-1] if depth == 0 else source[start:]

file_source = read_text(ROOT / "mases_core/src/Domains/FileManagement/Http/Controllers/FileController.php")
file_permissions = {
    "upload": ["file.upload"],
    "destroy": ["file.delete"],
    "forceDestroy": ["file.force_delete"],
    "restore": ["file.restore"],
    "index": ["file.list"],
    "versions": ["file.view_versions"],
    "latestVersion": ["file.view_versions"],
    "update": ["file.update"],
    "show": ["file.view"],
    "download": ["file.download"],
}
for method, permissions in file_permissions.items():
    body = method_body(file_source, method)
    # show/download permit public files but require permission for non-public files.
    if method in ("show", "download"):
        guarded = "if (!$file->is_public || $file->status !== 'published')" in body and all(p in body for p in permissions)
    else:
        guarded = bool(body) and all(p in body for p in permissions)
    finding(f"file-action-auth:{method}", guarded,
            "Expected action-level permission contract found." if guarded else
            f"Expected permission guard missing for {method}: {', '.join(permissions)}.")

# For each runtime Loan/Governance controller action, require either an explicit
# route permission/role/application middleware or a controller-level policy /
# authorization-service guard in that action. This is a source-contract check.
def controller_path(action):
    if not isinstance(action, str) or "@" not in action:
        return None, None
    class_name, method = action.split("@", 1)
    prefix = "App\\Http\\Controllers\\"
    if class_name.startswith(prefix):
        rel = class_name[len(prefix):].replace("\\", "/")
        return ROOT / "app/Http/Controllers" / (rel + ".php"), method
    prefix = "MaaleArna\\MASES\\Core\\"
    if class_name.startswith(prefix):
        rel = class_name[len(prefix):].replace("\\", "/")
        return ROOT / "mases_core/src" / (rel + ".php"), method
    return None, method

for domain in ("api/loans", "api/governance"):
    domain_routes = [r for r in routes if norm_uri(r.get("uri")).startswith(domain + "/")]
    finding(f"{domain}:routes-discovered", bool(domain_routes),
            f"Discovered {len(domain_routes)} effective routes.")
    for route in domain_routes:
        uri = norm_uri(route.get("uri"))
        methods = str(route.get("method", ""))
        mw = route_middleware(route)
        base_ok = has_sanctum_auth(mw) and has_tenant_membership(mw)
        finding(f"{domain}:middleware:{methods} {uri}", base_ok,
                "Sanctum and tenant membership present." if base_ok else
                "Missing auth:sanctum and/or tenant.membership in effective middleware.")
        action = route.get("action", "")
        path, method = controller_path(action)
        permission_middleware = any(
            m.startswith(("auth.permission:", "auth.role:", "auth.application:", "can:")) or "authorizationmiddleware" in m.lower()
            for m in mw
        )
        if path is None or not method:
            finding(f"{domain}:action:{methods} {uri}", permission_middleware,
                    f"Controller action could not be mapped ({action}); explicit route authorization middleware required.")
            continue
        source = read_text(path)
        body = method_body(source, method) if source else ""
        controller_guard = bool(body) and bool(re.search(
            r"Gate::authorize|authorize\s*\(|requirePermission\s*\(|hasPermission\s*\(|AuthorizationService|->can\s*\(",
            body
        ))
        # Deliberate self-service exception: MyLoanController resolves no
        # caller-selected resource. Require tenant + authenticated-user scoped
        # shareholder lookup and borrower-scoped repository lookup.
        self_service_guard = (
            method == "index"
            and path is not None
            and path.name == "MyLoanController.php"
            and "resolveTenantId()" in body
            and "$request->user()->getKey()" in body
            and "Shareholder::query()" in body
            and "->where('tenant_id', $tenantId)" in body
            and "->where('user_id', $userId)" in body
            and "listForBorrower(" in body
        )
        authorized = permission_middleware or controller_guard or self_service_guard
        finding(f"{domain}:action-auth:{methods} {uri}", authorized,
                "Explicit route authorization middleware or controller guard found." if authorized else
                f"No recognizable route-level permission or controller policy guard found for {action or 'unknown action'}.")

REPORT_FILE.parent.mkdir(parents=True, exist_ok=True)
REPORT_FILE.write_text(json.dumps({
    "route_table_file": str(ROUTES_FILE),
    "runtime_route_count": len(routes),
    "findings": findings,
    "summary": {
        "passed": sum(1 for x in findings if x["status"] == "PASS"),
        "failed": sum(1 for x in findings if x["status"] == "FAIL"),
        "total": len(findings),
    },
}, indent=2) + "\n", encoding="utf-8")

for item in findings:
    print(f"[{item['status']}] {item['check']}: {item['detail']}")
failed = sum(1 for x in findings if x["status"] == "FAIL")
print(f"Phase 0 bounded authorization audit: {len(routes)} runtime routes; "
      f"{len(findings)-failed} passed; {failed} failed.")
sys.exit(1 if failed else 0)
