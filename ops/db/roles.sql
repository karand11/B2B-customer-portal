-- Least-privilege database roles (Phase 7 S28; applied on the real server in Phase 8).
--
--   OWNER_PASSWORD=... APP_PASSWORD=... psql -d <database> -f ops/db/roles.sql          (as a superuser)
--   optional psql variables: -v owner_role=portal_owner -v app_role=portal_app
--
-- portal_owner  owns the tables; used ONLY for migrations (deploy step).
-- portal_app    runtime role used by the web app, queue worker and scheduler. It cannot TRUNCATE,
--               create or drop triggers, ALTER tables, or change/delete audit rows, so a compromised
--               app cannot rewrite history or bypass the invoice and audit protections.
--
-- WARNING: roles are cluster-wide. Running this against a server resets the passwords of the roles it names
-- (the verification script uses its own throwaway role names for exactly that reason).
-- Idempotent. RE-RUN IT AFTER EVERY MIGRATION that creates tables: default privileges cover new tables created
-- by the owner, but the audit_log REVOKE below only holds until a migration recreates the table.
-- Passwords are read from the environment (\getenv), never from the command line (visible in process lists).

\if :{?owner_role}
\else
  \set owner_role portal_owner
\endif
\if :{?app_role}
\else
  \set app_role portal_app
\endif

-- Keep the password statements out of the server log whatever log_statement says (needs superuser, which this script requires).
SET log_statement = 'none';
\getenv owner_pw OWNER_PASSWORD
\getenv app_pw APP_PASSWORD
\if :{?owner_pw}
\else
  \echo 'OWNER_PASSWORD is not set'
  \quit 3
\endif
\if :{?app_pw}
\else
  \echo 'APP_PASSWORD is not set'
  \quit 3
\endif

SELECT format('CREATE ROLE %I LOGIN', :'owner_role') WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = :'owner_role') \gexec
SELECT format('CREATE ROLE %I LOGIN', :'app_role') WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = :'app_role') \gexec

ALTER ROLE :"owner_role" PASSWORD :'owner_pw';
ALTER ROLE :"app_role" PASSWORD :'app_pw';
ALTER ROLE :"app_role" NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION;

SELECT current_database() AS dbname \gset
REVOKE CONNECT ON DATABASE :"dbname" FROM PUBLIC;
GRANT CONNECT ON DATABASE :"dbname" TO :"owner_role", :"app_role";
ALTER DATABASE :"dbname" OWNER TO :"owner_role";
ALTER SCHEMA public OWNER TO :"owner_role";
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
REVOKE CREATE ON SCHEMA public FROM :"app_role";
GRANT USAGE ON SCHEMA public TO :"app_role";

GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO :"app_role";
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO :"app_role";
ALTER DEFAULT PRIVILEGES FOR ROLE :"owner_role" IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO :"app_role";
ALTER DEFAULT PRIVILEGES FOR ROLE :"owner_role" IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO :"app_role";

REVOKE TRUNCATE, TRIGGER, REFERENCES ON ALL TABLES IN SCHEMA public FROM :"app_role";
-- append-only: INSERT and SELECT only (the triggers also block UPDATE and DELETE, this removes the privilege as well)
SELECT format('REVOKE UPDATE, DELETE ON public.audit_log FROM %I', :'app_role') WHERE to_regclass('public.audit_log') IS NOT NULL \gexec
