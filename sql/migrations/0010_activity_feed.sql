CREATE TABLE activity_feed (
    id           bigserial PRIMARY KEY,
    event_type   activity_event_type NOT NULL,
    antibody_id  bigint REFERENCES antibodies (id) ON DELETE CASCADE,
    payload      jsonb NOT NULL DEFAULT '{}'::jsonb,
    actor        varchar(255) NOT NULL,
    occurred_at  timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX activity_feed_occurred_at_idx ON activity_feed (occurred_at DESC);
CREATE INDEX activity_feed_event_type_idx  ON activity_feed (event_type);
CREATE INDEX activity_feed_antibody_id_idx ON activity_feed (antibody_id);
