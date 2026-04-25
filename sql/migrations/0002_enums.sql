CREATE TYPE antibody_type AS ENUM (
    'address',
    'call_pattern',
    'bytecode',
    'graph',
    'semantic'
);

CREATE TYPE antibody_verdict AS ENUM (
    'malicious',
    'suspicious'
);

CREATE TYPE antibody_status AS ENUM (
    'active',
    'challenged',
    'slashed',
    'expired'
);

CREATE TYPE mirror_status AS ENUM (
    'active',
    'removed'
);

CREATE TYPE check_decision AS ENUM (
    'allow',
    'block',
    'escalate'
);

CREATE TYPE agent_role AS ENUM (
    'trader',
    'publisher',
    'watcher',
    'relay'
);

CREATE TYPE activity_event_type AS ENUM (
    'published',
    'protected',
    'mirrored',
    'challenged',
    'released'
);
