-- ##################################################################################################################
-- UPLOAD (one row per accepted evidence write — doubles as the nonce/replay store and an audit log)
-- ##################################################################################################################
-- The storage gateway records every accepted GatewayRequestV1 here. The UNIQUE
-- nonce is the replay guard (a re-sent request collides and is rejected). The
-- per-publisher row count over a trailing window is the rate limit. CIDs are the
-- raw Lighthouse strings (CIDv0/dag-pb — see docs/gateway-cid-verdict.md).
CREATE TABLE gateway.upload
(
    id           bigserial   PRIMARY KEY,
    publisher    bytea       NOT NULL,           -- 20-byte lowercased address
    nonce        bytea       NOT NULL UNIQUE,     -- per-request nonce (replay guard)
    evidence_cid text        NOT NULL,            -- public envelope CID
    context_cid  text,                            -- encrypted-context CID (when present)
    created_at   timestamptz NOT NULL DEFAULT now()
);

-- Quota lookups: count a publisher's uploads within a trailing window.
CREATE INDEX gateway_upload_publisher_created_idx
    ON gateway.upload (publisher, created_at);
