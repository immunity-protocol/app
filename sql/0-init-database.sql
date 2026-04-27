-- ##################################################################################################################
-- FOUNDATION
-- ##################################################################################################################
\ir ./core/init.sql

-- ##################################################################################################################
-- DOMAIN MODULES
-- Order: independent modules first (antibody, agent, network), then event which references antibody.
-- ##################################################################################################################
\ir ./antibody/init.sql
\ir ./agent/init.sql
\ir ./network/init.sql
\ir ./event/init.sql
\ir ./indexer/init.sql

-- ##################################################################################################################
-- GRANTS
-- ##################################################################################################################
DO
$do$
    DECLARE
        _sch text;
        _usr text := current_user;
    BEGIN
        FOR _sch IN
            SELECT nspname FROM pg_namespace
            WHERE nspname NOT LIKE 'pg_%' AND nspname <> 'information_schema'
        LOOP
            EXECUTE format('GRANT ALL PRIVILEGES ON SCHEMA %I TO %I', _sch, _usr);
            EXECUTE format('GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA %I TO %I', _sch, _usr);
            EXECUTE format('GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA %I TO %I', _sch, _usr);
            EXECUTE format('GRANT ALL PRIVILEGES ON ALL FUNCTIONS IN SCHEMA %I TO %I', _sch, _usr);
        END LOOP;
    END
$do$;
