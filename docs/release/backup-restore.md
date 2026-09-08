LCMS Backup and Restore Runbook

Purpose

This runbook defines the release-safe backup and restore procedure for the
Language Center Management System (LCMS).

It covers:

the MySQL/MariaDB application database

private application files stored under storage/app/private

restore verification

rollback safety

Backups must be stored outside the Git repository and outside the public web
root.

Scope

The current LCMS release stores registration personal pictures on Laravel's
private local disk:

storage/app/private

These files are part of the recoverable application state and must be backed
up together with the database whenever they exist.

The public filesystem disk is not required for the current MVP release.

Safety Rules

Before any restore:

Do not overwrite the current production database directly.

Preserve the current known-good backup before making changes.

Restore into a new empty database first.

Validate the restored database before switching the application to it.

Keep the database backup and private-storage backup together.

Never commit database dumps, credentials, .env, or private user files to Git.

Keep backup files outside the project repository and outside the web root.

In production, use database credentials from the deployment environment.

Do not place real database passwords directly into committed scripts or documentation.

Recommended Backup Location

Use a dedicated location outside the repository, for example:

G:\(01)04\Taqat\LCMS_Backups\<YYYYMMDD-HHMMSS>

The M7-G validation used:

G:\(01)04\Taqat\LCMS_Backups\M7G_20260905

That validation folder is the known-good M7-G backup artifact and should be
preserved until the release process is complete.

Database Backup

Example PowerShell variables:

$MysqlDump = "C:\xampp\mysql\bin\mysqldump.exe"
$Database  = "lcms"
$BackupDir = "G:\(01)04\Taqat\LCMS_Backups\20260905-220000"
$BackupSql = Join-Path $BackupDir "lcms.sql"

New-Item -ItemType Directory -Path $BackupDir -Force | Out-Null

Create a consistent transactional backup:

& $MysqlDump `
    -u root `
    --single-transaction `
    --quick `
    --routines `
    --triggers `
    --events `
    --hex-blob `
    --default-character-set=utf8mb4 `
    --skip-comments `
    "--result-file=$BackupSql" `
    $Database

For production, replace the local root connection with the deployment
database account and obtain credentials securely from the deployment
environment.

Verify that the backup exists and is non-empty:

Get-Item $BackupSql |
    Select-Object FullName, Length, LastWriteTime

Record a SHA-256 hash:

Get-FileHash $BackupSql -Algorithm SHA256

The hash should be retained with release/operations records so the backup can
be checked for accidental modification.

Private Storage Backup

The private storage path is:

storage\app\private

Inventory the files first:

$PrivateRoot = Resolve-Path "storage\app\private"

Get-ChildItem `
    $PrivateRoot `
    -Recurse `
    -File |
    Select-Object FullName, Length

Create the archive:

$PrivateZip = Join-Path $BackupDir "private-storage.zip"

Compress-Archive `
    -Path "$PrivateRoot\*" `
    -DestinationPath $PrivateZip `
    -Force

If the private storage directory contains no files, document that fact for the
backup rather than creating artificial data.

For backups that contain private files, record the archive hash:

Get-FileHash $PrivateZip -Algorithm SHA256

Restore Procedure

1. Create a New Empty Database

Never restore directly over the active database.

Example:

$Mysql     = "C:\xampp\mysql\bin\mysql.exe"
$RestoreDb = "lcms_restore_20260905"

& $Mysql `
    -u root `
    -e "CREATE DATABASE ``$RestoreDb`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

Before creation, confirm that the chosen database name is not already in use.

2. Restore the Database Backup

PowerShell-friendly restore command:

$BackupSqlForMysql = $BackupSql.Replace("\", "/")

& $Mysql `
    -u root `
    $RestoreDb `
    -e "SOURCE $BackupSqlForMysql;"

A non-zero exit code is a restore failure.

3. Validate the Restored Database

Temporarily point only the current PowerShell process to the restored database:

$OldProcessDb =
    [Environment]::GetEnvironmentVariable(
        "DB_DATABASE",
        "Process"
    )

try {
    $env:DB_DATABASE = $RestoreDb

    php artisan migrate:status
    php artisan about
}
finally {
    Remove-Item Env:DB_DATABASE -ErrorAction SilentlyContinue

    if (-not [string]::IsNullOrWhiteSpace($OldProcessDb)) {
        $env:DB_DATABASE = $OldProcessDb
    }
}

Required checks include:

all expected migrations report Ran

the application boots successfully

expected tenant/demo/sample data exists

expected account identifiers exist

table list matches the source

exact row counts match

table checksums match when supported

the application returns to the original database after validation

For release validation, git diff --check should remain clean.

Private Storage Restore

Restore private files into a separate temporary location first:

$PrivateRestore = Join-Path $BackupDir "private-storage-restored"

New-Item `
    -ItemType Directory `
    -Path $PrivateRestore `
    -Force |
    Out-Null

Expand-Archive `
    -Path $PrivateZip `
    -DestinationPath $PrivateRestore `
    -Force

Compare SHA-256 hashes of the original and restored files before treating the
archive as valid.

Only after successful validation should restored private files be placed into
the deployed application's private storage location.

Rollback Guidance

Before replacing or changing an active environment:

create a fresh database backup

create a private-storage backup

record hashes

preserve the previous known-good backup

perform the restore into an isolated database

validate the restored state

switch the application only after successful verification

If verification fails, do not switch the application to the restored state.
Keep using the last known-good environment and investigate the failed restore.

M7-G Validation Evidence

M7-G validated the procedure using isolated databases:

lcms_m7g_source_20260905
lcms_m7g_restore_20260905

Validation results:

Source tables                  39
Restored tables                39
Exact row counts               MATCH
Table checksums                MATCH
Schema SHA-256                 MATCH
Restored migrations            all Ran
Application boot               PASS
Demo Centers                   1
Demo Branches                  2
Demo Accounts                  6
Private storage files          1
Private storage bytes          14
Private storage restore hash   MATCH
Application returned to DB     lcms
git diff --check               CLEAN
Branch                         feature/release-readiness

The database backup produced during M7-G was:

G:\(01)04\Taqat\LCMS_Backups\M7G_20260905\lcms-demo-backup.sql

Backup size:

74,766 bytes

A SHA-256 hash was recorded and verified before and after cleanup.

The two temporary validation databases were deleted successfully after
validation. The known-good backup folder was intentionally preserved.

Production Note

The M7-G validation proves the backup/restore mechanism on the current local
MariaDB 10.4.32 environment. Production operations should use the equivalent
database tooling available on the deployment host and should test restore
procedures periodically rather than assuming a backup is usable.