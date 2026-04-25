CREATE TABLE publishers (
    address                    bytea PRIMARY KEY,
    ens                        varchar(255),
    antibodies_published       integer NOT NULL DEFAULT 0,
    successful_blocks          integer NOT NULL DEFAULT 0,
    total_earned_usdc          numeric(20, 6) NOT NULL DEFAULT 0,
    total_staked_usdc          numeric(20, 6) NOT NULL DEFAULT 0,
    successful_challenges_won  integer NOT NULL DEFAULT 0,
    challenges_lost            integer NOT NULL DEFAULT 0,
    first_seen_at              timestamptz NOT NULL DEFAULT now(),
    last_active_at             timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX publishers_last_active_at_idx       ON publishers (last_active_at DESC);
CREATE INDEX publishers_antibodies_published_idx ON publishers (antibodies_published DESC);
