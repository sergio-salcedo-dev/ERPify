import { spawnSync } from "node:child_process";
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import { afterEach, beforeEach, describe, expect, it } from "vitest";

/**
 * Runs `docker/install-deps.sh` — what the dev container runs ahead of `next
 * dev` on every start — under `sh` (dash in the node image), with `node` and
 * `npm` replaced by probes on PATH. The `npm` probe records each `ci` it is
 * asked for and creates node_modules the way a real install would, so the
 * assertions can count installs across consecutive starts.
 */
const SCRIPT = path.resolve(__dirname, "../docker/install-deps.sh");
const STAMP = "node_modules/.erpify-install-stamp";

let dir: string;
let bin: string;

beforeEach(() => {
  dir = mkdtempSync(path.join(tmpdir(), "install-deps-"));
  bin = path.join(dir, "bin");
  mkdirSync(bin);
  writeFileSync(
    path.join(bin, "node"),
    '#!/bin/sh\nprintf "%s\\n" "${PROBE_NODE_VERSION:-v26.0.0}"\n',
    { mode: 0o755 },
  );
  writeFileSync(
    path.join(bin, "npm"),
    [
      "#!/bin/sh",
      'if [ "$1" = "--version" ]; then echo 12.0.1; exit 0; fi',
      'if [ "$1" = "ci" ]; then',
      '  echo ci >> "$PROBE_CALLS"',
      '  if [ "${PROBE_NPM_FAILS:-}" = 1 ]; then exit 1; fi',
      "  mkdir -p node_modules",
      "  exit 0",
      "fi",
      "exit 2",
      "",
    ].join("\n"),
    { mode: 0o755 },
  );
  writeFileSync(path.join(dir, "package-lock.json"), '{"lockfileVersion":3}\n');
});

afterEach(() => {
  rmSync(dir, { recursive: true, force: true });
});

type Start = { status: number | null; output: string; installs: number };

function installsSoFar(calls: string): number {
  return existsSync(calls) ? readFileSync(calls, "utf8").split("\n").filter(Boolean).length : 0;
}

function start(env: Record<string, string> = {}): Start {
  const calls = path.join(dir, "calls");
  const before = installsSoFar(calls);
  const result = spawnSync("sh", [SCRIPT], {
    cwd: dir,
    encoding: "utf8",
    env: {
      NODE_ENV: "test",
      PATH: `${bin}:${process.env.PATH ?? "/usr/bin:/bin"}`,
      PROBE_CALLS: calls,
      ...env,
    },
  });
  return {
    status: result.status,
    output: `${result.stdout}${result.stderr}`,
    installs: installsSoFar(calls) - before,
  };
}

describe("install-deps.sh", () => {
  it("installs into an empty volume and stamps it", () => {
    const first = start();
    expect(first).toMatchObject({ status: 0, installs: 1 });
    expect(existsSync(path.join(dir, STAMP))).toBe(true);
  });

  it("skips the install on a restart over the same lockfile, Node and npm", () => {
    start();
    const restart = start();
    expect(restart).toMatchObject({ status: 0, installs: 0 });
    expect(restart.output).toContain("skipping npm ci");
  });

  it("reinstalls once the lockfile changes", () => {
    start();
    writeFileSync(path.join(dir, "package-lock.json"), '{"lockfileVersion":3,"changed":true}\n');
    expect(start()).toMatchObject({ status: 0, installs: 1 });
    expect(start()).toMatchObject({ status: 0, installs: 0 });
  });

  it("reinstalls when Node changes over an unchanged lockfile", () => {
    start();
    expect(start({ PROBE_NODE_VERSION: "v27.0.0" })).toMatchObject({ status: 0, installs: 1 });
  });

  it("fails the start and leaves no stamp when the install fails, so the next start retries", () => {
    const failed = start({ PROBE_NPM_FAILS: "1" });
    expect(failed.status).not.toBe(0);
    expect(existsSync(path.join(dir, STAMP))).toBe(false);
    expect(start()).toMatchObject({ status: 0, installs: 1 });
  });

  it("does not trust a stamp left by an install of another lockfile", () => {
    mkdirSync(path.join(dir, "node_modules"));
    writeFileSync(path.join(dir, STAMP), "0000\n");
    expect(start()).toMatchObject({ status: 0, installs: 1 });
  });
});
