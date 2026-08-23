#!/usr/bin/env python3
"""Validate a Shopware app manifest.

Two layers, because one is not enough:

 1. XSD validation against manifest-3.0.xsd from shopware/shopware trunk.
 2. The checks the XSD provably CANNOT make.

Layer 2 exists because <meta> is declared `xs:choice maxOccurs="unbounded"`,
so a manifest missing <author>, <copyright>, <license>, <version> or
<compatibility> is schema-valid and still rejected by Shopware's runtime
manifest validation at `bin/console app:refresh`. Verified by mutation:
deleting <author> and deleting <version> both pass xmllint.

Usage:
    python3 bin/validate-manifest.py [path/to/manifest.xml]

Exits non-zero on any failure. Network is used only to fetch the XSD, and
only when it is not already cached next to this script.
"""
from __future__ import annotations

import os
import subprocess
import sys
import urllib.request
import xml.etree.ElementTree as ET

XSD_URL = (
    "https://raw.githubusercontent.com/shopware/shopware/trunk/"
    "src/Core/Framework/App/Manifest/Schema/manifest-3.0.xsd"
)

# Required by Shopware's runtime manifest validation but NOT by the XSD.
REQUIRED_META = ("name", "label", "author", "copyright", "version", "license", "compatibility")

XSI = "http://www.w3.org/2001/XMLSchema-instance"


def fail(msg: str) -> None:
    print(f"FAIL  {msg}")
    fail.count += 1  # type: ignore[attr-defined]


fail.count = 0  # type: ignore[attr-defined]


def ok(msg: str) -> None:
    print(f"ok    {msg}")


def cached_xsd(script_dir: str) -> str | None:
    path = os.path.join(script_dir, ".manifest-3.0.xsd")
    if os.path.exists(path) and os.path.getsize(path) > 0:
        return path
    try:
        with urllib.request.urlopen(XSD_URL, timeout=30) as resp:
            data = resp.read()
        if not data:
            return None
        with open(path, "wb") as handle:
            handle.write(data)
        return path
    except Exception as exc:  # noqa: BLE001 - reported, never swallowed
        print(f"WARN  could not fetch the XSD ({exc}); skipping schema validation")
        return None


def main(argv: list[str]) -> int:
    script_dir = os.path.dirname(os.path.abspath(__file__))
    app_dir = os.path.dirname(script_dir)
    manifest = argv[1] if len(argv) > 1 else os.path.join(app_dir, "manifest.xml")

    if not os.path.exists(manifest):
        print(f"FAIL  no manifest at {manifest}")
        return 1
    ok(f"manifest found: {manifest}")

    # --- layer 1: XSD ----------------------------------------------------
    xsd = cached_xsd(script_dir)
    if xsd:
        which = subprocess.run(["which", "xmllint"], capture_output=True)
        if which.returncode != 0:
            print("WARN  xmllint not installed; skipping schema validation")
        else:
            result = subprocess.run(
                ["xmllint", "--noout", "--schema", xsd, manifest],
                capture_output=True,
                text=True,
            )
            if result.returncode == 0:
                ok("XSD validation (manifest-3.0.xsd)")
            else:
                fail("XSD validation:\n" + (result.stderr or result.stdout).strip())

    # --- layer 2: what the XSD cannot enforce ----------------------------
    try:
        root = ET.parse(manifest).getroot()
    except ET.ParseError as exc:
        print(f"FAIL  manifest is not well-formed XML: {exc}")
        return 1

    if root.tag != "manifest":
        fail(f"root element is <{root.tag}>, expected <manifest>")
    else:
        ok("root element is <manifest>")

    if root.get(f"{{{XSI}}}noNamespaceSchemaLocation"):
        ok("root carries xsi:noNamespaceSchemaLocation")
    else:
        fail("root is missing xsi:noNamespaceSchemaLocation")

    meta = root.find("meta")
    if meta is None:
        print("FAIL  no <meta> element")
        return 1

    for field in REQUIRED_META:
        node = meta.find(field)
        if node is None:
            fail(f"<meta><{field}> is missing (XSD does not catch this)")
        elif not (node.text or "").strip():
            fail(f"<meta><{field}> is empty (XSD does not catch this)")
        else:
            ok(f"<meta><{field}> present")

    # Folder name must equal the technical name: Shopware resolves the app by
    # directory, and the name is concatenated into the registration proof HMAC.
    name_node = meta.find("name")
    tech_name = (name_node.text or "").strip() if name_node is not None else ""
    folder = os.path.basename(os.path.dirname(os.path.abspath(manifest)))
    if tech_name and tech_name != folder:
        fail(f"<meta><name> is {tech_name!r} but the directory is {folder!r}; they must match")
    elif tech_name:
        ok(f"<meta><name> matches the directory name ({folder})")

    if tech_name and not tech_name[:1].isupper():
        fail(f"technical name {tech_name!r} must be UpperCamelCase")

    setup = root.find("setup")
    if setup is not None:
        reg = setup.find("registrationUrl")
        url = (reg.text or "").strip() if reg is not None else ""
        if not url:
            fail("<setup> present but <registrationUrl> is missing or empty")
        elif not url.startswith("https://"):
            fail(f"registrationUrl must be https (got {url!r})")
        else:
            ok("registrationUrl is https")

        if setup.find("secret") is not None:
            fail(
                "<setup><secret> is committed. That is a live credential in version "
                "control, and its presence also makes shops skip Shopware Account "
                "authentication. Keep it in a working copy only."
            )
        else:
            ok("no <setup><secret> committed")

    # Webhook names must be globally unique; xs:unique covers within-file
    # duplicates, this reports them with a clearer message.
    names = [w.get("name") for w in root.findall("./webhooks/webhook")]
    dupes = {n for n in names if n and names.count(n) > 1}
    if dupes:
        fail(f"duplicate webhook name(s): {sorted(dupes)}")
    elif names:
        ok(f"{len(names)} webhook name(s), all distinct")

    print()
    if fail.count:  # type: ignore[attr-defined]
        print(f"{fail.count} check(s) failed")  # type: ignore[attr-defined]
        return 1
    print("manifest OK")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
