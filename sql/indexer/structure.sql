-- ##################################################################################################################
-- STATE (singleton row tracking the indexer's progress through chain history)
-- ##################################################################################################################
CREATE TABLE indexer.state
(
    id                   smallint PRIMARY KEY DEFAULT 1,
    last_processed_block bigint      NOT NULL DEFAULT 0,
    mode                 varchar(16) NOT NULL DEFAULT 'live',
    last_run_at          timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT state_singleton CHECK (id = 1)
);
