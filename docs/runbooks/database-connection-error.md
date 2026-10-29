# Runbook: "Error establishing a database connection"

WordPress (PHP-FPM) is running but cannot log in to MySQL on RDS. nginx, the ALB
and the ECS service are usually fine; the problem is between the `wordpress`
container and RDS.

Names used below (prod):

| Thing | Name |
|---|---|
| Region | `ap-southeast-2` |
| ECS cluster / service | `autosourcechina-prod-cluster` / `autosourcechina-prod-service` |
| Task definition family | `autosourcechina-prod` |
| RDS instance | `autosourcechina-prod-mysql` |
| DB credential secret | `autosourcechina-prod/db-master` |
| Log group | `/ecs/autosourcechina-prod` |

## 1. Restore service first

If the cause isn't obvious within a few minutes, start a fresh task. It re-reads
the secret and reconnects:

```bash
aws ecs update-service --region ap-southeast-2 \
  --cluster autosourcechina-prod-cluster --service autosourcechina-prod-service \
  --task-definition autosourcechina-prod --force-new-deployment
```

`--task-definition autosourcechina-prod` (no revision) uses the latest revision.
Expect 2-5 minutes: the new task only takes traffic after `wp-bootstrap.sh`
finishes.

## 2. Inspect

### Is RDS up?

```bash
aws rds describe-db-instances --region ap-southeast-2 \
  --db-instance-identifier autosourcechina-prod-mysql \
  --query 'DBInstances[0].[DBInstanceStatus,EngineVersion,AllocatedStorage]'
```

Anything other than `available` (`rebooting`, `upgrading`, `modifying`,
`storage-full`, `stopped`) is the cause. `auto_minor_version_upgrade` can reboot
the instance in its maintenance window; that fixes itself in a few minutes.

### What happened to RDS recently?

```bash
aws rds describe-events --region ap-southeast-2 \
  --source-type db-instance --source-identifier autosourcechina-prod-mysql \
  --duration 2880 --query 'Events[].[Date,Message]' --output table
```

### Did the password change after the task started?

ECS reads secrets only when a task starts. If the password changes later, the
running task keeps the old one.

```bash
aws secretsmanager describe-secret --region ap-southeast-2 \
  --secret-id autosourcechina-prod/db-master \
  --query '[LastChangedDate,RotationEnabled,RotationRules]'

aws ecs list-tasks --region ap-southeast-2 --cluster autosourcechina-prod-cluster \
  --service-name autosourcechina-prod-service --query 'taskArns' --output text \
| xargs aws ecs describe-tasks --region ap-southeast-2 --cluster autosourcechina-prod-cluster \
  --query 'tasks[].[startedAt,lastStatus,healthStatus,taskDefinitionArn]' --tasks
```

`LastChangedDate` later than the task's `startedAt` means the task has a stale
password. Fix: step 1.

### Logs

```bash
aws logs tail /ecs/autosourcechina-prod --region ap-southeast-2 --since 2h \
  | grep -iE "access denied|mysql|database|wp-bootstrap" | tail -30
```

- `Access denied for user` - wrong password.
- `Connection timed out` / `Can't connect` - RDS down, or a security group /
  subnet problem.
- Nothing at all - normal for this error. WordPress renders the error page
  without logging unless `WP_DEBUG` is on, so an empty result rules nothing out.

The ECS task can show `HEALTHY` throughout: its health check only tests that
bootstrap finished, not the database.

## 3. Incident 2026-10-28: secret rotation vs. running task

### Symptom

Every page returned "Error establishing a database connection". The task was
`RUNNING` / `HEALTHY` and the logs had no matching errors.

### Timeline (ACDT, +10:30)

| Time | Event |
|---|---|
| 07:48:59 | Task started and read the DB password from the RDS-managed secret |
| 08:40:08 | RDS rotated the password (`AutomaticallyAfterDays: 7`) |
| after 08:40 | Every request failed to connect with the old password |

### Root cause

RDS was created with `manage_master_user_password = true`. RDS stores that
password in its own secret and rotates it every 7 days. The task definition
injects the password as an environment variable, which ECS resolves only at task
start. Frequent deploys and Spot replacements had hidden the problem until a task
outlived a rotation.

### Immediate fix

`--force-new-deployment` (step 1). The new task read the current password.

### Permanent fix

The single-application database doesn't need rotation, so the credential is now
owned by Terraform (`infra/modules/rds/main.tf`):

- `random_password.db` generates the password once and keeps it in state.
- `aws_secretsmanager_secret.db` (`autosourcechina-prod/db-master`) stores
  `{username, password}`, the same shape as the RDS secret, so the task
  definition's `:password::` reference didn't change. No rotation is configured.
- `aws_db_instance` sets `password` instead of `manage_master_user_password`;
  RDS deleted its managed secret when this was applied.
- The ECS module takes the new secret ARN, and the execution role's read
  permission follows from it.

### Rollout notes

- The first apply failed partway: the apply role (`GITHUB_OIDC_ROLE`) could not
  call `iam:PutRolePolicy` on the execution role. Terraform stops starting new
  changes after an error, so the DB password was untouched and the site stayed
  up. Docker Build only runs after a successful Terraform run, so nothing
  deployed the half-applied task definition.
- The re-run plan then failed: the read-only plan role
  (`autosourcechina-terraform-plan-role`) could read only the secrets it was
  listed for, not the new one.
- Permissions added:
  - Apply role: Secrets Manager create/update/delete/tag on
    `autosourcechina-prod/*`, delete on `rds!*`, and
    `iam:Get/Put/Delete/ListRolePolicy` on `role/autosourcechina-prod-*`.
  - Plan role: `secretsmanager:GetSecretValue` on
    `autosourcechina-prod/db-master-*`.
- Re-run with "Re-run all jobs", not "Re-run failed jobs": the saved `tfplan`
  was stale.
- Expected downtime: a few minutes between RDS switching passwords and Docker
  Build's new task becoming healthy.
- Watch out: Docker Build rolls back to the *previous* task definition when a
  deploy fails. Right after a credential change, that revision points at the old
  secret, so a rollback stays broken. Deploy the latest revision with step 1
  instead.

### Follow-ups

- Health checks don't cover the database. The container check only looks for
  `/tmp/wp-bootstrap-ready`, and the ALB checks `/`, which nginx can answer from
  its page cache (`fastcgi_cache_use_stale ... http_500`). Consider an uncached
  health endpoint that queries the database, or a CloudWatch alarm on 5xx
  responses.
- If you change the password on purpose (`terraform apply -replace=module.rds.random_password.db`),
  expect the same few minutes of downtime until a new task starts.
