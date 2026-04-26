\# CAT Framework Project



\## What this is

An open-source, modular PHP framework for building computer-assisted

translation (CAT) tools. Composer packages, framework-agnostic, no

Laravel dependency in core.



\## My background

\- Solo freelance translator (English, Hindi, Urdu)

\- Daily user of Wordfast, Trados, memoQ, Phrase

\- PHP developer, Laravel + Inertia + React stack

\- 5-10 hours/week on this project



\## Rules for Claude

\- Do NOT suggest packages without verifying they exist on Packagist

\- Do NOT hallucinate features or timelines

\- Do NOT over-engineer. Smallest working version first.

\- Challenge my assumptions. Push back when scope creeps.

\- All core packages must be framework-agnostic PHP 8.2+

\- UTF-8 and RTL support is non-negotiable from day one

\- When writing code, show interfaces/contracts only until I ask

&#x20; for implementation



\## Current phase

Planning — defining data models, contracts, and package inventory.

No code yet.



\## Tool Paths (Laravel Herd — do NOT search, trust these)

PHP and Composer are managed by Laravel Herd. Use these exact paths — no need to run `where php` or probe the system.

**PHP 8.3** (default for this project — targets ^8.2):
\- Windows: `C:/Users/shaik/.config/herd/bin/php83/php.exe`
\- Git Bash: `/c/Users/shaik/.config/herd/bin/php83/php.exe`

**PHP 8.4** (available if needed):
\- Windows: `C:/Users/shaik/.config/herd/bin/php84/php.exe`
\- Git Bash: `/c/Users/shaik/.config/herd/bin/php84/php.exe`

**Composer**:
\- Windows: `C:/Users/shaik/.config/herd/bin/composer.bat`
\- Git Bash: `/c/Users/shaik/.config/herd/bin/composer.bat`
\- Alternative (phar): `C:/Users/shaik/.config/herd/bin/php83/php.exe C:/Users/shaik/.config/herd/bin/composer.phar`

**Canonical one-liners for bash commands:**
```
PHP="C:/Users/shaik/.config/herd/bin/php83/php.exe"
COMPOSER="C:/Users/shaik/.config/herd/bin/composer.bat"
```

Run tests: `"C:/Users/shaik/.config/herd/bin/php83/php.exe" vendor/bin/phpunit`
Install deps: `"C:/Users/shaik/.config/herd/bin/composer.bat" install --no-interaction`



\## Key reference

See planning/cat-framework-planning-prompt.md for the full

planning prompt to use when starting a new task.

