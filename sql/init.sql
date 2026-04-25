CREATE TABLE IF NOT EXISTS session
(
    session_id VARCHAR(255) PRIMARY KEY,
    access     INT          NOT NULL,
    data       TEXT         NOT NULL,
    expire     INT          NOT NULL,
    ip_address VARCHAR(45)  NULL DEFAULT NULL,
    user_agent TEXT         NULL DEFAULT NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT now()
);
