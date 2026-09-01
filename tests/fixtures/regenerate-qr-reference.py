#!/usr/bin/env python3
"""Regenerate tests/fixtures/qr-reference.php from an independent QR implementation.

    python3 -m pip install qrcode
    python3 tests/fixtures/regenerate-qr-reference.py > tests/fixtures/qr-reference.php

The reference matrices in that file are what prove our hand-written encoder in
App\\Services\\Export\\QrCode agrees with a real implementation, module for module. Every
table in it was typed from the specification by hand, so agreement is not a given.

Our encoder's version and mask are read back from PHP and handed to the library, so only the
encoding is compared. Mask *selection* is still pinned, because a different mask produces a
different matrix and the fixture holds every module.
"""

import json
import os
import subprocess
import sys

try:
    import qrcode
    import qrcode.util
    from qrcode.constants import ERROR_CORRECT_M
except ImportError:
    sys.exit("This needs the reference library: python3 -m pip install qrcode")

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

# Chosen for what each one exercises, not for variety. See the header of the generated file.
CASES = [
    "LRMS",
    "https://lrms.example.in/admin/inspections/42",
    "https://lrms.example.in/admin/visits/1?x=" + "y" * 100,
]

PHP = r"""
require $argv[1] . "/app/bootstrap.php";
use App\Services\Export\QrCode;
$out = [];
foreach (json_decode($argv[2], true) as $payload) {
    $qr = QrCode::encode($payload);
    $out[] = ["version" => $qr->version(), "mask" => $qr->mask()];
}
echo json_encode($out);
"""

result = subprocess.run(
    ["php", "-r", PHP, ROOT, json.dumps(CASES)],
    capture_output=True,
    text=True,
)

if result.returncode != 0:
    sys.exit("PHP failed:\n" + result.stdout[-2000:] + result.stderr[-2000:])

chosen = json.loads(result.stdout)

HEADER = '''<?php

declare(strict_types=1);

/**
 * QR matrices captured from Python's `qrcode` library, module for module.
 *
 * These are not expectations somebody wrote down: they are the output of an independent,
 * widely used implementation, asked for the same version and mask our encoder chose, and
 * pasted here. Every table in App\\Services\\Export\\QrCode was typed by hand from the
 * specification, and a hand-typed table is a table with a typo in it. This is how the typo
 * gets caught.
 *
 * The three cases are chosen for what they exercise, not for variety:
 *
 *   version 1   the smallest code: one block, no alignment pattern
 *   version 4   the size a real verification link lands on
 *   version 8   two block groups of unequal length, and the version-information block
 *               that only exists from version 7 up
 *
 * DO NOT EDIT BY HAND. Regenerate with:
 *
 *   python3 -m pip install qrcode
 *   python3 tests/fixtures/regenerate-qr-reference.py > tests/fixtures/qr-reference.php
 *
 * @return array<int, array{payload:string, version:int, mask:int, rows:array<int, string>}>
 */

return [
'''

out = [HEADER]

for payload, choice in zip(CASES, chosen):
    code = qrcode.QRCode(
        version=choice["version"],
        error_correction=ERROR_CORRECT_M,
        box_size=1,
        border=0,
        mask_pattern=choice["mask"],
    )
    code.add_data(
        qrcode.util.QRData(
            payload.encode(), mode=qrcode.util.MODE_8BIT_BYTE, check_data=False
        )
    )
    code.make(fit=False)

    rows = ["".join("1" if cell else "0" for cell in row) for row in code.get_matrix()]

    out.append("    [\n")
    out.append("        'payload' => %s,\n" % json.dumps(payload).replace("'", "\\'").replace('"', "'"))
    out.append("        'version' => %d,\n" % choice["version"])
    out.append("        'mask' => %d,\n" % choice["mask"])
    out.append("        'rows' => [\n")
    for row in rows:
        out.append("            '%s',\n" % row)
    out.append("        ],\n")
    out.append("    ],\n")

out.append("];\n")

sys.stdout.write("".join(out))
