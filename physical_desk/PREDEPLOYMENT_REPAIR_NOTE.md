# Predeployment repair checkpoint

AWS-05B release freeze superseded after predeployment security review. Do not distribute or deploy.

The prior source archive included a tracked runtime database credential. The old database password must be rotated before public staging. Any new staging password must remain outside Git and release archives. Historical engineering logs are retained unchanged.

GLPI priority value 5 is displayed by the framework as **Very high**. The deterministic safety rule keeps value 5 and uses that name; value 6 is **Major**.

GLPI represents time to own (TTO) and time to resolve (TTR) as separate SLA records. PD57's setup now provisions both objectives with minute durations. These numeric minutes are elapsed-time objectives unless a business-hours calendar is explicitly configured and verified.

A new release bundle must be generated only after the repaired acceptance checks, restart and persistence checks, and credential scan have passed against the preserved PD57 database.

## Runtime credential setup for the next release

The source archive should contain `config/config_db.php.example` only. On the target host, copy it to untracked `config/config_db.php` and provide `PD57_DB_HOST`, `PD57_DB_USER`, `PD57_DB_PASSWORD`, and `PD57_DB_NAME` through server-side environment configuration. Docker Compose also requires `PD57_DB_ROOT_PASSWORD` and `PD57_LDAP_ADMIN_PASSWORD` outside Git. Do not include a populated `.env` or `config/config_db.php` in an archive.

The local runtime retained its existing ignored configuration and database volume during this repair. The current password remains compromised by the superseded historical archive and must be rotated before any public staging.
