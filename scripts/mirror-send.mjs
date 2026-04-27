#!/usr/bin/env node
// Relayer signing helper for the PHP RelayerWorker.
//
// Reads a JSON envelope from STDIN (NOT argv - the private key must never
// appear in `ps`). Dispatches to one of:
//   - Mirror.mirrorAntibody(antibody, auxiliaryKey)
//   - Mirror.mirrorAddressAntibody(antibody, target)
//   - Mirror.unmirrorAntibody(keccakId)
//
// Writes a single JSON line to stdout:
//   { ok: true,  txHash, blockNumber }                      // tx mined
//   { ok: true,  alreadyAbsent: true }                      // unmirror no-op
//   { ok: false, error, code, permanent: true|false }       // failure
//
// Stdin schema:
// {
//   "chainId": 11155111,
//   "rpcUrl": "https://...",
//   "mirrorAddress": "0x...",
//   "privateKey": "0x...",
//   "jobType": "mirror" | "mirror_address" | "unmirror",
//   "antibody": { ...19 fields from AntibodyPublished args... },
//   "auxiliaryKey": "0x...",   // mirror only
//   "target": "0x...",         // mirror_address only
//   "keccakId": "0x...",       // unmirror only
//   "timeoutMs": 60000          // optional, default 60s
// }
import process from "node:process";
import { ethers } from "ethers";

const MIRROR_ABI = [
  "function mirrorAntibody((bytes32,bytes32,bytes32,bytes32,bytes32,address,uint64,uint32,address,uint64,uint8,uint8,uint8,uint8,uint64,uint96,uint8,uint8,uint8) a, bytes32 auxiliaryKey)",
  "function mirrorAddressAntibody((bytes32,bytes32,bytes32,bytes32,bytes32,address,uint64,uint32,address,uint64,uint8,uint8,uint8,uint8,uint64,uint96,uint8,uint8,uint8) a, address target)",
  "function unmirrorAntibody(bytes32 keccakId)",
  "error NotRelayer()",
  "error ZeroAddress()",
  "error AntibodyNotMirrored(bytes32 keccakId)",
];

const ZERO_BYTES32 = "0x" + "0".repeat(64);

function out(obj) {
  process.stdout.write(JSON.stringify(obj) + "\n");
}

function fail(error, code = "UNKNOWN", permanent = false) {
  out({ ok: false, error, code, permanent });
  process.exit(0); // PHP wrapper reads from stdout regardless of exit code.
}

function readStdin() {
  return new Promise((resolve, reject) => {
    const chunks = [];
    process.stdin.on("data", (c) => chunks.push(c));
    process.stdin.on("end", () => resolve(Buffer.concat(chunks).toString("utf8")));
    process.stdin.on("error", reject);
  });
}

function asBytes32(value, name) {
  if (typeof value !== "string") return ZERO_BYTES32;
  const v = value.startsWith("0x") || value.startsWith("0X") ? value : "0x" + value;
  if (!/^0x[0-9a-fA-F]{64}$/.test(v)) {
    throw new Error(`${name}: not a 32-byte hex value (${value})`);
  }
  return v.toLowerCase();
}

function asAddress(value, name) {
  if (typeof value !== "string" || !/^0x[0-9a-fA-F]{40}$/.test(value)) {
    throw new Error(`${name}: not a 20-byte hex address (${value})`);
  }
  return ethers.getAddress(value);
}

function asUint(value) {
  if (value === null || value === undefined) return 0n;
  if (typeof value === "bigint") return value;
  if (typeof value === "number") return BigInt(value);
  return BigInt(String(value));
}

function asBool(value) {
  if (typeof value === "boolean") return value;
  if (typeof value === "number") return value !== 0;
  if (typeof value === "string") return value === "true" || value === "1" || value === "t";
  return Boolean(value);
}

// Build the 19-tuple Antibody struct for the Mirror ABI from the
// AntibodyPublished event args (PHP key naming).
function buildAntibody(env) {
  return [
    asBytes32(env.primaryMatcherHash, "primaryMatcherHash"),
    asBytes32(env.evidenceCid,        "evidenceCid"),
    asBytes32(env.contextHash,        "contextHash"),
    asBytes32(env.embeddingHash,      "embeddingHash"),
    asBytes32(env.attestation,        "attestation"),
    asAddress(env.publisher,          "publisher"),
    asUint(env.stakeLockUntil),
    asUint(env.immSeq),
    asAddress(env.reviewer,           "reviewer"),
    asUint(env.expiresAt),
    Number(asUint(env.abType)),
    Number(asUint(env.flavor)),
    Number(asUint(env.verdict)),
    Number(asUint(env.confidence)),
    asUint(env.createdAt),
    asUint(env.stake ?? env.stakeAmount),
    Number(asUint(env.severity)),
    0, // status: ACTIVE (Mirror always re-mirrors as active; lifecycle changes flow via unmirror).
    asBool(env.isSeeded) ? 1 : 0,
  ];
}

// Solidity custom errors come back as data on revert. Decode against the ABI.
function classifyError(err, jobType, iface) {
  const msg = err && err.message ? err.message : String(err);

  // ethers v6 surfaces revert data in different places depending on the path.
  const data =
    err?.data ||
    err?.error?.data ||
    err?.info?.error?.data ||
    err?.transaction?.data ||
    null;

  if (typeof data === "string" && data.startsWith("0x") && data.length >= 10) {
    try {
      const decoded = iface.parseError(data);
      if (decoded) {
        const name = decoded.name;
        if (name === "AntibodyNotMirrored" && jobType === "unmirror") {
          // Already absent on this chain - desired end state.
          return { ok: true, alreadyAbsent: true };
        }
        if (name === "NotRelayer" || name === "ZeroAddress") {
          return { ok: false, error: name, code: name, permanent: true };
        }
        return { ok: false, error: name, code: name, permanent: false };
      }
    } catch {
      // fall through
    }
  }

  // Common ethers / RPC error codes.
  const code = err?.code || err?.error?.code || "UNKNOWN";
  const permanentCodes = new Set(["INVALID_ARGUMENT", "UNSUPPORTED_OPERATION"]);
  if (permanentCodes.has(code)) {
    return { ok: false, error: msg, code, permanent: true };
  }
  if (code === "INSUFFICIENT_FUNDS") {
    return { ok: false, error: "relayer wallet out of gas funds", code, permanent: false };
  }
  if (code === "NONCE_EXPIRED" || /nonce/i.test(msg) || /replacement/i.test(msg)) {
    return { ok: false, error: msg, code: "NONCE", permanent: false };
  }
  if (code === "TIMEOUT" || /timeout/i.test(msg)) {
    return { ok: false, error: msg, code: "TIMEOUT", permanent: false };
  }
  if (code === "NETWORK_ERROR" || code === "SERVER_ERROR" || /ECONN|ETIMEDOUT|fetch/i.test(msg)) {
    return { ok: false, error: msg, code: "NETWORK", permanent: false };
  }
  return { ok: false, error: msg, code, permanent: false };
}

async function main() {
  const raw = await readStdin();
  let req;
  try {
    req = JSON.parse(raw);
  } catch (e) {
    fail("invalid json on stdin: " + e.message, "BAD_INPUT", true);
  }

  for (const k of ["chainId", "rpcUrl", "mirrorAddress", "privateKey", "jobType"]) {
    if (req[k] === undefined || req[k] === null || req[k] === "") {
      fail(`missing required field: ${k}`, "BAD_INPUT", true);
    }
  }

  const provider = new ethers.JsonRpcProvider(req.rpcUrl, Number(req.chainId), { staticNetwork: true });
  const wallet = new ethers.Wallet(req.privateKey, provider);
  const iface = new ethers.Interface(MIRROR_ABI);
  const mirror = new ethers.Contract(req.mirrorAddress, MIRROR_ABI, wallet);
  const timeoutMs = Number(req.timeoutMs ?? 60000);

  let txPromise;
  try {
    if (req.jobType === "mirror") {
      const a = buildAntibody(req.antibody || {});
      const auxKey = asBytes32(req.auxiliaryKey ?? ZERO_BYTES32, "auxiliaryKey");
      txPromise = mirror.mirrorAntibody(a, auxKey);
    } else if (req.jobType === "mirror_address") {
      const a = buildAntibody(req.antibody || {});
      const target = asAddress(req.target, "target");
      txPromise = mirror.mirrorAddressAntibody(a, target);
    } else if (req.jobType === "unmirror") {
      const keccakId = asBytes32(req.keccakId, "keccakId");
      txPromise = mirror.unmirrorAntibody(keccakId);
    } else {
      fail(`unknown jobType: ${req.jobType}`, "BAD_INPUT", true);
    }
  } catch (e) {
    fail(`build tx: ${e.message}`, "BUILD", true);
  }

  let tx;
  try {
    tx = await txPromise;
  } catch (e) {
    const result = classifyError(e, req.jobType, iface);
    out(result);
    return;
  }

  let receipt;
  try {
    receipt = await Promise.race([
      tx.wait(1),
      new Promise((_, rej) => setTimeout(() => rej(new Error("tx.wait timeout")), timeoutMs)),
    ]);
  } catch (e) {
    const result = classifyError(e, req.jobType, iface);
    // Even if wait failed, the tx may have been broadcast; surface its hash.
    if (tx?.hash) result.txHash = tx.hash;
    out(result);
    return;
  }

  if (receipt?.status === 0) {
    out({ ok: false, error: "tx reverted", code: "REVERT", permanent: false, txHash: tx.hash });
    return;
  }

  out({
    ok: true,
    txHash: tx.hash,
    blockNumber: receipt?.blockNumber ?? null,
  });
}

main().catch((e) => {
  out({ ok: false, error: `unhandled: ${e.message || e}`, code: "UNHANDLED", permanent: false });
  process.exit(0);
});
