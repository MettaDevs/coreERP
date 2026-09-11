"""Validate app.yaml against the Control Plane catalog contract.

Mirrors apps/core/app/Http/Requests/Provider/AppCatalogRequest.php, including the
four Dynamics 365 layers (entry point -> permission -> privilege -> duty) and the rule that
privilege codes must never collide with permission codes. Manifest keys mirror the endpoint payload
exactly (snake_case), so CI forwards them without renaming.

Usage: python check-manifest.py ../app.yaml
"""

import re
import sys

import yaml

ENTRY_POINT_TYPES = {"form", "menu_item", "api", "report", "action"}
ACCESS_LEVELS = {"read", "update", "create", "correct", "delete", "invoke"}
SCOPES = {"tenant", "legal_entity", "operating_unit"}
CODE_RE = re.compile(r"^[a-z0-9][a-z0-9._-]*$")

path = sys.argv[1] if len(sys.argv) > 1 else "../app.yaml"
with open(path, encoding="utf-8") as handle:
    manifest = yaml.safe_load(handle)

app_id = manifest["id"]
security = manifest["security"]
problems = []


def codes(layer, key="code"):
    return [str(item[key]) for item in security.get(layer, [])]


def check_layer(layer, label):
    values = codes(layer)
    if len(values) != len(set(values)):
        problems.append(f"{label}: duplicate codes")
    for code in values:
        if not CODE_RE.match(code):
            problems.append(f"{label}: code fails regex: {code}")
        if not code.startswith(app_id + "."):
            problems.append(f"{label}: code missing app prefix: {code}")
        if len(code) > 160:
            problems.append(f"{label}: code longer than 160: {code}")
    for item in security.get(layer, []):
        if len(str(item["name"])) > 150:
            problems.append(f"{label}: name longer than 150: {item['name']}")
    return values


entry_points = check_layer("entry_points", "entry point")
permissions = check_layer("permissions", "permission")
privileges = check_layer("privileges", "privilege")
duties = check_layer("duties", "duty")

for item in security.get("entry_points", []):
    if item["type"] not in ENTRY_POINT_TYPES:
        problems.append(f"entry point {item['code']}: invalid type {item['type']}")

for item in security.get("permissions", []):
    if item["access"] not in ACCESS_LEVELS:
        problems.append(f"permission {item['code']}: invalid access {item['access']}")
    if item["entry_point"] not in entry_points:
        problems.append(f"permission {item['code']}: entry_point not declared: {item['entry_point']}")

collisions = set(permissions) & set(privileges)
if collisions:
    problems.append(f"privilege codes collide with permission codes: {sorted(collisions)}")

for item in security.get("privileges", []):
    unknown = set(item["permissions"]) - set(permissions)
    if unknown:
        problems.append(f"privilege {item['code']}: undeclared permissions {sorted(unknown)}")

for item in security.get("duties", []):
    unknown = set(item["privileges"]) - set(privileges)
    if unknown:
        problems.append(f"duty {item['code']}: undeclared privileges {sorted(unknown)}")

references = [str(r["code"]) for r in manifest.get("number_sequences", {}).get("references", [])]
if len(references) != len(set(references)):
    problems.append("number sequence: duplicate reference codes")
for reference in manifest.get("number_sequences", {}).get("references", []):
    if not str(reference["code"]).startswith(app_id + "."):
        problems.append(f"number sequence: reference missing app prefix: {reference['code']}")
    prefix = str(reference.get("default_prefix", ""))
    if len(prefix) != 4 or not prefix.isalpha() or prefix != prefix.upper():
        problems.append(f"number sequence {reference['code']}: default_prefix must be exactly 4 uppercase letters")
    bad = set(reference["allowed_scopes"]) - SCOPES
    if bad:
        problems.append(f"number sequence {reference['code']}: invalid scopes {sorted(bad)}")

# Navigasi hanya boleh menunjuk permission yang benar-benar dideklarasikan.
for group, entries in manifest["ui"]["navigation"]["sidebar"].items():
    for entry in entries:
        gate = entry.get("permission")
        if gate not in permissions:
            problems.append(f"nav {entry['id']}: gate is not a declared permission: {gate}")

# Setiap permission harus benar-benar dapat diberikan melalui privilege dan duty.
# Transaksi tidak dipaksa menjadi CRUD: contoh register menerima/memutasi aset,
# sedangkan monitoring hanya dibaca dan tidak membutuhkan nomor dokumen.
granted_permissions = {
    permission
    for privilege in security.get("privileges", [])
    for permission in privilege["permissions"]
}
for permission in permissions:
    if permission not in granted_permissions:
        problems.append(f"permission is not included by a privilege: {permission}")

granted_privileges = {
    privilege
    for duty in security.get("duties", [])
    for privilege in duty["privileges"]
}
for privilege in privileges:
    if privilege not in granted_privileges:
        problems.append(f"privilege is not included by a duty: {privilege}")

reachable_permissions = {
    permission
    for privilege in security.get("privileges", [])
    if privilege["code"] in granted_privileges
    for permission in privilege["permissions"]
}
for group, entries in manifest["ui"]["navigation"]["sidebar"].items():
    for entry in entries:
        gate = entry.get("permission")
        if gate in permissions and gate not in reachable_permissions:
            problems.append(f"nav {entry['id']}: gate is not reachable from a duty: {gate}")

# Nomor hanya boleh dideklarasikan untuk dokumen yang memang dapat dibuat.
for reference in references:
    resource = reference.removeprefix(app_id + ".")
    if f"{app_id}.{resource}.create" not in permissions:
        problems.append(f"number sequence {reference}: no matching .create permission")

print(
    f"entry_points={len(entry_points)} permissions={len(permissions)} "
    f"privileges={len(privileges)} duties={len(duties)} references={len(references)}"
)
print("reachable_permissions:", len(reachable_permissions))
print("PROBLEMS:", problems if problems else "none")
sys.exit(1 if problems else 0)
