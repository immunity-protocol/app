// Regenerate request.fixture.json from the REAL SDK so the gateway's
// canonical-JSON + EIP-191 tests verify byte-parity against the shipped
// contract (immunity-sdk/src/storage/client.ts). Assumes the SDK repo is a
// sibling checkout (../immunity-sdk) and has been built (`dist/`).
//
//   node tests/fixtures/gateway/generate.cjs > tests/fixtures/gateway/request.fixture.json
//
// The private key is the well-known hardhat/anvil account #0 — FIXTURE ONLY.
const path = require("node:path");
const SDK = path.resolve(__dirname, "../../../../immunity-sdk");
const { canonicalJson } = require(path.join(SDK, "dist/index.cjs"));
const { Wallet, keccak256, toUtf8Bytes, getBytes } = require(path.join(SDK, "node_modules/ethers"));

const PRIV = "0xac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80";
const wallet = new Wallet(PRIV);

// Keys intentionally OUT of order; covers unicode, a slash, and an escaped quote.
const envelope = {
  schema: "immunity/antibody-envelope/v1",
  publisher: wallet.address.toLowerCase(),
  keccakId: "0x" + "11".repeat(32),
  immId: "imm_test_001",
  abType: "ADDRESS",
  flavor: 0,
  createdAt: "2026-06-13T00:00:00.000Z",
  reasonSummary: 'path a/b — unicode é and quote " test',
  matcher: { kind: "graph", chainId: 84532, taintSetId: "0x" + "22".repeat(32), size: 7 },
};

const withContext = { encryptedContext: "0x04aabbccddeeff00112233", envelope };
const noContext = { envelope };

async function sign(payload) {
  const cjson = canonicalJson(payload);
  const payloadHash = keccak256(toUtf8Bytes(cjson));
  const signature = await wallet.signMessage(getBytes(payloadHash));
  return { payload, canonicalJson: cjson, payloadHash, signature };
}

(async () => {
  const out = {
    privateKey: PRIV,
    publisher: wallet.address.toLowerCase(),
    withContext: await sign(withContext),
    noContext: await sign(noContext),
  };
  process.stdout.write(JSON.stringify(out, null, 2) + "\n");
})();
