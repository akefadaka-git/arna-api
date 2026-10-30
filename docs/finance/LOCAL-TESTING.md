# Running Finance Tests Locally (Windows PowerShell)

**Purpose:** run the Finance PHP tests on the Owner's machine without cloning or downloading the repository and without invoking GitHub Actions.

## Prerequisites

- An **existing** local working tree of \`akefadaka-git/arna-api\`.
- PHP 8.3 or later available on PowerShell's \`PATH\`.
- Composer dependencies already installed in that working tree (\`vendor/autoload.php\` and \`vendor/bin/phpunit\`).
- The checked-out code must be the exact revision you intend to test. The runner prints the local branch and commit; it does not fetch or update them.

The runner deliberately does **not** clone, download repository contents, fetch, pull, switch branches, reset/clean files, install packages, run migrations outside PHPUnit, or modify \`.env\`. It refuses to start if the PHPUnit configuration does not pin the environment to \`APP_ENV=testing\` and \`DB_CONNECTION=sqlite\` with \`DB_DATABASE=:memory:\`.

## Option A — runner is already present in the local checkout

From PowerShell:

\`\`\`powershell
Set-Location 'D:\\MASES\\github-current\\arna-api'
Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass
.\\tools\\finance\\Invoke-FinanceTests.ps1
\`\`\`

The default \`Finance\` suite discovers Finance-related PHP test files under \`tests\\\` and the Finance domain tree. It writes PHPUnit's cache to a unique temporary directory and removes that temporary directory after the run.

## Option B — retrieve only the runner script, not the repository

If the local checkout already contains the Finance code but not this helper script, retrieve this one script file through your authenticated GitHub CLI session:

\`\`\`powershell
$runner = Join-Path $env:TEMP 'Invoke-FinanceTests.ps1'
$encoded = gh api 'repos/akefadaka-git/arna-api/contents/tools/finance/Invoke-FinanceTests.ps1?ref=feat/finance-phase0-baseline-local-test-runner' --jq .content
if ($LASTEXITCODE -ne 0) { throw 'GitHub CLI could not read the runner; confirm gh auth status and repository access.' }
$bytes = [Convert]::FromBase64String(($encoded -replace '\\s', ''))
[IO.File]::WriteAllText($runner, [Text.Encoding]::UTF8.GetString($bytes), [Text.UTF8Encoding]::new($false))

& $runner -RepoPath 'D:\\MASES\\github-current\\arna-api'
\`\`\`

This retrieves only the runner file. It does not clone, download, or update the repository. The runner checks the local origin and executes the tests in the local files currently present; it does not certify that those files match a remote commit. Confirm the printed commit SHA before interpreting results.

## Useful invocations

Run the Finance test files (default):

\`\`\`powershell
& $runner -RepoPath 'D:\\MASES\\github-current\\arna-api'
\`\`\`

List the command without running tests:

\`\`\`powershell
& $runner -RepoPath 'D:\\MASES\\github-current\\arna-api' -ListOnly
\`\`\`

Run a named test file:

\`\`\`powershell
& $runner -RepoPath 'D:\\MASES\\github-current\\arna-api' \`
  -TestPath 'tests\\Feature\\FinanceGeneralLedgerTest.php'
\`\`\`

Run only a matching test method/class:

\`\`\`powershell
& $runner -RepoPath 'D:\\MASES\\github-current\\arna-api' \`
  -Filter 'journal.*idempotency'
\`\`\`

Run one PHPUnit suite:

\`\`\`powershell
& $runner -RepoPath 'D:\\MASES\\github-current\\arna-api' -Suite Unit
& $runner -RepoPath 'D:\\MASES\\github-current\\arna-api' -Suite Feature
& $runner -RepoPath 'D:\\MASES\\github-current\\arna-api' -Suite Domain
\`\`\`

## Interpretation and limitations

- Exit code \`0\` means PHPUnit passed on the printed **local** commit.
- A non-zero exit code means the test run failed or could not start. Preserve the full output and exit code.
- The runner is a test launcher, not proof that every required Finance test exists or is discovered. Compare the discovered file list with the acceptance register.
- SQLite in-memory tests do not prove MySQL-specific trigger behavior, production concurrency safety, or deployment readiness.
- Do not use \`composer test\` for this controlled invocation because the current Composer script clears Laravel configuration before running tests.
- This runner never invokes CI. GitHub workflow checks remain \`NOT RUN\` until separately authorized.
- If the existing checkout lacks the required dependencies or the correct destination remote, stop; do not download the repository or silently repair the environment.
