#!/usr/bin/env bash
#
# Wakdo - installe les hooks Git versionnes (.githooks).
#
# Pointe core.hooksPath vers .githooks (versionne) au lieu de .git/hooks (local,
# non versionne). A lancer une fois apres le clone :
#   scripts/install-hooks.sh
#
# Les hooks (pre-commit, commit-msg) sont alors actifs pour ce clone.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
HOOKS_DIR="$ROOT/.githooks"

if [ ! -d "$HOOKS_DIR" ]; then
    echo "install-hooks: $HOOKS_DIR introuvable." >&2
    exit 1
fi

chmod +x "$HOOKS_DIR"/* 2>/dev/null || true
git -C "$ROOT" config core.hooksPath .githooks

echo "Hooks Git installes (core.hooksPath = .githooks)."
echo "Actifs : $(cd "$HOOKS_DIR" && ls | tr '\n' ' ')"
