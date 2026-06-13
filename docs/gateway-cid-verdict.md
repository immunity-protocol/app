# Storage Gateway — Step-0 CID verdict (pinned)

**Date:** 2026-06-13 · **Probe:** `LighthouseService::uploadFile` of a 40-byte JSON, real key.

## What Lighthouse returns

```
CID:    QmXE7xhPkmfF4tdXmwSh6ZUWydKn6fmaE9FVasfzWUxou2
shape:  CIDv0 / dag-pb (0x70) / sha2-256 / base58btc ("Qm…", 46 chars)
```

`https://upload.lighthouse.storage/api/v0/add` returns `{"Hash": "Qm…"}` — Kubo's
default **CIDv0/dag-pb**. Round-trip verified: the bytes read back **identical** from
`https://gateway.lighthouse.storage/ipfs/<CID>` (and `/` stays unescaped in the body).

Decoding the CID: base58btc → `0x12 0x20 ‖ <32-byte digest>` (multihash = sha2-256, len 32).
The 32 bytes on-chain (`bytes32`) are this **dag-pb/UnixFS multihash digest** — NOT the
sha256 of the raw file bytes (UnixFS wraps the block before hashing).

## Pinned canonical shape (gateway + SDK + indexer must all agree)

**CIDv0 / dag-pb / sha2-256.**

- **Gateway** returns the raw `Qm…` string as `evidenceCid` / `contextCid` (this is what we do).
- On-chain `evidenceCid` (bytes32) = the multihash digest (the 32 bytes after the `0x12 0x20`
  prefix). Reconstruct the fetch CID as `base58btc(0x12 ‖ 0x20 ‖ digest)` → `Qm…`.

## SDK / indexer reconciliation REQUIRED (flagged — not edited here)

The shipped SDK `~/www/immunity-sdk/src/storage/cid.ts` assumes **CIDv1 / raw (0x55) /
sha2-256** (`bafkrei…`, multibase `b`). It is **incompatible**: `cidToHex32` rejects any CID
whose first char is not `b`, so it throws on the `Qm…` CIDs the gateway returns. Required change
(S2 follow-up, do NOT edit from the gateway session):

1. `cid.ts` → reconcile to **CIDv0/dag-pb**:
   - `cidToHex32(cid)`: base58btc-decode; assert prefix `0x12 0x20`; return the trailing 32-byte
     digest as `Hex32`.
   - `hex32ToCid(hex32)`: `base58btcEncode(0x12 ‖ 0x20 ‖ digest)` → `Qm…`.
2. `client.ts` `GatewayResponseV1`: `{ cid }` → `{ evidenceCid: string; contextCid?: string }`;
   `putEvidence` maps `contextCid` → on-chain `contextHash`, returns both.
3. Indexer's CID reconstruction must use the same CIDv0/dag-pb base58 path.

Until reconciled, the SDK's `putEvidence`/`fetchPublicEnvelope` round-trip will not parse the
gateway's CIDs. The gateway side is correct as built (returns the raw CID string).
