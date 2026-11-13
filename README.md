# Soma na Mbiti School

Source backup for [somanambiti.com](https://somanambiti.com), an independent learning academy using HTML, CSS, JavaScript, PHP and MySQL on Safaricom shared hosting.

The public repository deliberately excludes hosting credentials, student records, local test accounts and protected examination files. It is a source backup, not a backup of the student database.

## Protected files

Keep these outside the public repository and restore them from a protected school backup:

- `private/config.php` and actual school, tutor and certificate configuration files.
- `private/assessment-bank.json` — formal investing examinations.
- `private/college-course.json` — foundation teaching and protected checkpoints.
- `private/term-courses.json` — standard-course teaching and protected examinations.

The PHP application reads these files privately. Never move them into `public/`, commit their answer keys, or publish a ZIP containing them under the public website directory.

## Development and release

Public pages live in `public/`, server code in `private/`, database migrations in `database/`, and checks in `tests/`. Preserve the existing private hosting configuration when uploading a release. Full academic tests require the protected school files and disposable local test accounts; never run fixture tests against production.

Use feature branches and pull requests for coordinated changes. Review the phone and desktop staging preview and obtain the owner’s approval before a production upload. Do not configure automatic production deployment from this public repository.

See `SETUP.txt` for hosting instructions. Course materials link to their credited publishers; a reference does not imply endorsement.
