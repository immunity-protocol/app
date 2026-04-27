#!/usr/bin/env node
// 0G Storage download helper for the PHP indexer's HydrationWorker.
//
// Usage:
//   node og-download.mjs <rootHash>
//
// Reads OG_STORAGE_INDEXER from env (default: testnet turbo).
// Writes the downloaded payload as JSON to stdout. Exits non-zero on any
// failure with a one-line JSON error on stderr.
import { mkdtempSync, readFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import process from "node:process";
import { Indexer } from "@0gfoundation/0g-ts-sdk";

function fail(message, cause) {
  const payload = { ok: false, error: message };
  if (cause && cause.message) payload.cause = String(cause.message);
  process.stderr.write(JSON.stringify(payload) + "\n");
  process.exit(1);
}

async function main() {
  const rootHash = process.argv[2];
  if (!rootHash) fail("missing rootHash argument");
  if (!/^0x[0-9a-fA-F]{64}$/.test(rootHash)) fail("rootHash must be 0x-prefixed 32-byte hex");

  if (/^0x0+$/.test(rootHash)) {
    // Caller checks for the empty-CID case but allow this as a no-op anyway.
    process.stdout.write(JSON.stringify({ ok: true, empty: true }) + "\n");
    return;
  }

  const indexerUrl = process.env.OG_STORAGE_INDEXER || "https://indexer-storage-testnet-turbo.0g.ai";
  const indexer = new Indexer(indexerUrl);

  const dir = mkdtempSync(path.join(tmpdir(), "immunity-og-"));
  const file = path.join(dir, "blob");
  try {
    const err = await indexer.download(rootHash, file, true);
    if (err) fail("storage download failed", err);
    const bytes = readFileSync(file);
    let parsed;
    try {
      parsed = JSON.parse(bytes.toString("utf8"));
    } catch (e) {
      fail("downloaded payload is not valid JSON", e);
    }
    process.stdout.write(JSON.stringify({ ok: true, payload: parsed }) + "\n");
  } finally {
    try { rmSync(dir, { recursive: true, force: true }); } catch { /* ignore */ }
  }
}

main().catch((e) => fail("unexpected error", e));
