import { spawnSync } from "node:child_process";
import {
  existsSync,
  mkdirSync,
  mkdtempSync,
  readdirSync,
  readFileSync,
  rmSync,
  symlinkSync,
  writeFileSync,
} from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import { afterEach, beforeEach, describe, expect, it } from "vitest";

/**
 * Runs `docker/install-deps.sh` — what the dev container runs ahead of `next
 * dev` on every start — under `/bin/sh` (dash in the node image), with `node`
 * and `npm` replaced by probes on PATH. The `npm` probe behaves like the real
 * `npm ci` in the two ways the script depends on: it empties node_modules
 * before installing (so a failure leaves a directory, never a matching stamp),
 * and a successful install rewrites the hidden lockfile npm keeps inside
 * node_modules. It records each `ci` it is asked for, so the assertions can
 * count installs across consecutive starts.
 *
 * PATH holds the probes plus symlinks to the few system tools the script and
 * the probes use, so a case can withhold one of them.
 */
const SCRIPT = path.resolve(__dirname, "../docker/install-deps.sh");
const STAMP = "node_modules/.erpify-install-stamp";
const HIDDEN_LOCKFILE = "node_modules/.package-lock.json";
const SYSTEM_TOOLS = ["cat", "cut", "mkdir", "rm", "sha256sum"];

let dir: string;
let bin: string;

function linkSystemTools(target: string, tools: readonly string[]): void {
  for (const tool of tools) {
    const resolved = ["/usr/bin", "/bin"]
      .map((root) => path.join(root, tool))
      .find((candidate) => existsSync(candidate));
    if (resolved === undefined) {
      throw new Error(`system tool ${tool} not found`);
    }
    symlinkSync(resolved, path.join(target, tool));
  }
}

beforeEach(() => {
  dir = mkdtempSync(path.join(tmpdir(), "install-deps-"));
  bin = path.join(dir, "bin");
  mkdirSync(bin);
  writeFileSync(
    path.join(bin, "node"),
    [
      "#!/bin/sh",
      'if [ "$1" = "--version" ]; then printf "%s\\n" "${PROBE_NODE_VERSION:-v26.0.0}"; exit 0; fi',
      'if [ "$1" = "-p" ]; then printf "%s\\n" "${PROBE_PLATFORM:-linux-x64}"; exit 0; fi',
      "exit 2",
      "",
    ].join("\n"),
    { mode: 0o755 },
  );
  writeFileSync(
    path.join(bin, "npm"),
    [
      "#!/bin/sh",
      'if [ "$1" = "--version" ]; then printf "%s\\n" "${PROBE_NPM_VERSION:-12.0.1}"; exit 0; fi',
      'if [ "$1" = "ci" ]; then',
      '  echo ci >> "$PROBE_CALLS"',
      "  rm -rf node_modules",
      "  mkdir -p node_modules",
      '  if [ "${PROBE_NPM_FAILS:-}" = 1 ]; then exit 1; fi',
      `  cat package-lock.json > ${HIDDEN_LOCKFILE}`,
      "  exit 0",
      "fi",
      "exit 2",
      "",
    ].join("\n"),
    { mode: 0o755 },
  );
  writeFileSync(path.join(dir, "package.json"), '{"name":"probe","dependencies":{"a":"^1.0.0"}}\n');
  writeFileSync(path.join(dir, "package-lock.json"), '{"lockfileVersion":3}\n');
});

afterEach(() => {
  rmSync(dir, { recursive: true, force: true });
});

type Start = { status: number | null; output: string; installs: number };

function installsSoFar(calls: string): number {
  return existsSync(calls) ? readFileSync(calls, "utf8").split("\n").filter(Boolean).length : 0;
}

function start(env: Record<string, string> = {}, tools: readonly string[] = SYSTEM_TOOLS): Start {
  const calls = path.join(dir, "calls");
  const system = mkdtempSync(path.join(dir, "system-"));
  linkSystemTools(system, tools);
  const before = installsSoFar(calls);
  const result = spawnSync("/bin/sh", [SCRIPT], {
    cwd: dir,
    encoding: "utf8",
    env: {
      NODE_ENV: "test",
      PATH: `${bin}:${system}`,
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
    expect(start()).toMatchObject({ status: 0, installs: 1 });
    expect(existsSync(path.join(dir, STAMP))).toBe(true);
  });

  it("skips the install on a restart over the same inputs and tree", () => {
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

  it("reinstalls once package.json changes over an unchanged lockfile", () => {
    start();
    writeFileSync(
      path.join(dir, "package.json"),
      '{"name":"probe","dependencies":{"a":"^2.0.0"}}\n',
    );
    expect(start()).toMatchObject({ status: 0, installs: 1 });
  });

  it("reinstalls when Node changes over an unchanged lockfile", () => {
    start();
    expect(start({ PROBE_NODE_VERSION: "v27.0.0" })).toMatchObject({ status: 0, installs: 1 });
  });

  it("reinstalls when npm changes over an unchanged lockfile", () => {
    start();
    expect(start({ PROBE_NPM_VERSION: "13.0.0" })).toMatchObject({ status: 0, installs: 1 });
  });

  it("reinstalls when the platform changes over an unchanged lockfile", () => {
    start();
    expect(start({ PROBE_PLATFORM: "linux-arm64" })).toMatchObject({ status: 0, installs: 1 });
  });

  it("reinstalls a tree emptied by hand, even with the stamp left behind", () => {
    start();
    for (const entry of readdirSync(path.join(dir, "node_modules"))) {
      if (`node_modules/${entry}` !== STAMP) {
        rmSync(path.join(dir, "node_modules", entry), { recursive: true, force: true });
      }
    }
    expect(existsSync(path.join(dir, STAMP))).toBe(true);
    expect(start()).toMatchObject({ status: 0, installs: 1 });
  });

  it("reinstalls a tree an in-container install changed, after the lockfile is restored", () => {
    start();
    writeFileSync(path.join(dir, HIDDEN_LOCKFILE), '{"lockfileVersion":3,"extra":"foo"}\n');
    expect(start()).toMatchObject({ status: 0, installs: 1 });
  });

  it("fails the start and leaves no stamp when the install fails, so the next start retries", () => {
    const failed = start({ PROBE_NPM_FAILS: "1" });
    expect(failed.status).not.toBe(0);
    expect(existsSync(path.join(dir, "node_modules"))).toBe(true);
    expect(existsSync(path.join(dir, STAMP))).toBe(false);
    expect(start()).toMatchObject({ status: 0, installs: 1 });
  });

  it("does not trust a stamp left by an install of another lockfile", () => {
    mkdirSync(path.join(dir, "node_modules"));
    writeFileSync(path.join(dir, STAMP), "0000\n");
    expect(start()).toMatchObject({ status: 0, installs: 1 });
  });

  it("refuses to start when it cannot fingerprint, instead of matching an empty stamp", () => {
    start();
    writeFileSync(path.join(dir, "package-lock.json"), '{"lockfileVersion":3,"changed":true}\n');
    const blind = start(
      {},
      SYSTEM_TOOLS.filter((tool) => tool !== "sha256sum"),
    );
    expect(blind.status).not.toBe(0);
    expect(blind.installs).toBe(0);
    expect(blind.output).toContain("cannot fingerprint");
  });
});
