# Security Policy

## Supported Version

Security fixes are handled for the current `main` branch.

## Reporting a Vulnerability

Please do not report security vulnerabilities in public issues.

Use a private channel to contact the repository maintainer first. Include:

- affected version or commit
- short description of the issue
- steps to reproduce
- possible impact
- suggested fix, if known

The project may handle school-related workflows and device information, so reports should avoid including real student data, real serial numbers, credentials, or exported CSV files.

## Secrets and Local Configuration

Do not commit local configuration files or credentials. The following files are expected to live outside the repository or remain ignored:

- `db.php`
- `/etc/disown/jamf.conf`
- `/etc/disown/mail.conf`
- `/etc/disown/notify.conf`
- SQL dumps and backups
