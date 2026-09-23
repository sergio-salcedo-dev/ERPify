import { spawnSync } from "node:child_process";
import {
  chmodSync,
  existsSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import { afterEach, beforeEach, describe, expect, it } from "vitest";

/**
 * Runs `docker/read-sentry-token.sh` — the step that hands the BuildKit secret
 * to `npm run build` — against every shape the mount can take, under `sh`
 * (dash in the builder image). The wrapped command is a probe that writes what
 * it received to files, so the assertions can read the token the build would
 * see while also asserting it never reaches stdout or stderr, which is the
 * build log.
 *
 * Root reads a mode-000 file, so the unreadable case runs only as another user.
 */
const SCRIPT = path.resolve(__dirname, "../docker/read-sentry-token.sh");
const PROBE =
  'if [ "${SENTRY_AUTH_TOKEN+set}" = set ]; then printf %s "$SENTRY_AUTH_TOKEN" > "$1"; fi; ' +
  'if [ "${SENTRY_ORG+set}" = set ]; then printf %s "$SENTRY_ORG" > "$2"; fi';
const ORG_TOKEN = "sntrys_eyJhIjoxfQ==_abc";

type Run = {
  status: number | null;
  output: string;
  token: string | undefined;
  org: string | undefined;
};

let dir: string;

beforeEach(() => {
  dir = mkdtempSync(path.join(tmpdir(), "read-sentry-token-"));
});

afterEach(() => {
  chmodSync(dir, 0o700);
  rmSync(dir, { recursive: true, force: true });
});

function secretFile(content: string): string {
  const file = path.join(dir, "secret");
  writeFileSync(file, content);
  return file;
}

function run(secretPath: string, env: Record<string, string> = {}): Run {
  const tokenOut = path.join(dir, "token.out");
  const orgOut = path.join(dir, "org.out");
  const result = spawnSync("sh", [SCRIPT, "sh", "-c", PROBE, "probe", tokenOut, orgOut], {
    encoding: "utf8",
    env: {
      NODE_ENV: "test",
      PATH: process.env.PATH ?? "/usr/bin:/bin",
      SENTRY_TOKEN_SECRET_PATH: secretPath,
      ...env,
    },
  });
  const read = (file: string): string | undefined =>
    existsSync(file) ? readFileSync(file, "utf8") : undefined;
  return {
    status: result.status,
    output: `${result.stdout}${result.stderr}`,
    token: read(tokenOut),
    org: read(orgOut),
  };
}

describe("read-sentry-token.sh", () => {
  it("hands a bare token to the command and never prints it", () => {
    const result = run(secretFile("sntryu_abc\n"), { SENTRY_ORG: "acme" });
    expect(result).toMatchObject({ status: 0, token: "sntryu_abc", org: "acme" });
    expect(result.output).not.toContain("sntryu_abc");
  });

  it("accepts an organisation token whose payload keeps its base64 padding", () => {
    const result = run(secretFile(ORG_TOKEN));
    expect(result).toMatchObject({ status: 0, token: ORG_TOKEN });
    expect(result.output).not.toContain(ORG_TOKEN);
  });

  it("runs the command with upload off when no secret is mounted", () => {
    const result = run(path.join(dir, "absent"), { SENTRY_AUTH_TOKEN: "ambient" });
    expect(result).toMatchObject({ status: 0, token: undefined });
    expect(result.output).toContain("source-map upload is off");
  });

  it("runs the command with upload off when the mounted secret is empty", () => {
    const result = run(secretFile(" \n"));
    expect(result).toMatchObject({ status: 0, token: undefined });
    expect(result.output).toContain("source-map upload is off");
  });

  it.each([
    ["an env line", "SENTRY_AUTH_TOKEN=sntrys_x", "NAME=value"],
    ["a lower-case env line", "sentry_auth_token=abc", "NAME=value"],
    ["a double-quoted token", '"sntryu_abc"', "quote"],
    ["a single-quoted token", "'sntryu_abc'", "quote"],
    ["more than one word", "sntryu_abc sntryu_def", "more than one word"],
    ["a whole env file", "SENTRY_ORG=acme\nSENTRY_AUTH_TOKEN=sntryu_abc\n", "more than one word"],
  ])("fails on %s without printing it or running the command", (_label, content, reason) => {
    const result = run(secretFile(content));
    expect(result.status).toBe(1);
    expect(result.output).toContain(reason);
    expect(result.token).toBeUndefined();
    for (const word of content.split(/[\s"'=]+/).filter((part) => part.length > 3)) {
      if (word !== "SENTRY_AUTH_TOKEN" && word !== "SENTRY_ORG") {
        expect(result.output).not.toContain(word);
      }
    }
  });

  it("fails on a directory mounted where the secret should be", () => {
    const secret = path.join(dir, "secret");
    mkdirSync(secret);
    const result = run(secret);
    expect(result.status).toBe(1);
    expect(result.output).toContain("not a readable file");
    expect(result.token).toBeUndefined();
  });

  it.skipIf(process.getuid?.() === 0)("fails on a secret it cannot read", () => {
    const secret = secretFile("sntryu_abc");
    chmodSync(secret, 0o000);
    const result = run(secret);
    expect(result.status).toBe(1);
    expect(result.output).toContain("not a readable file");
    expect(result.output).not.toContain("sntryu_abc");
  });

  it.each([
    ["empty", ""],
    ["blank", " \t "],
  ])("unsets an %s SENTRY_ORG rather than passing it on", (_label, org) => {
    const result = run(secretFile(ORG_TOKEN), { SENTRY_ORG: org });
    expect(result).toMatchObject({ status: 0, token: ORG_TOKEN, org: undefined });
  });

  it("refuses to run with no command", () => {
    const result = spawnSync("sh", [SCRIPT], { encoding: "utf8" });
    expect(result.status).toBe(2);
  });
});
