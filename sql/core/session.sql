CREATE TABLE core.session
(
    session_id  varchar(255) PRIMARY KEY,
    access      integer NOT NULL,
    data        text NOT NULL,
    expire      integer NOT NULL,
    ip_address  varchar(45),
    user_agent  text,
    created_at  timestamptz NOT NULL DEFAULT now()
);
