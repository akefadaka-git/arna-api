[CmdletBinding()]
param(
    [string]$RepoPath = "",
    [string[]]$TestPath = @(),
    [string]$Filter = "",
    [ValidateSet("Finance", "Unit", "Feature", "Domain", "All")]
    [string]$Suite = "Finance",
    [switch]$ListOnly
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function Stop-Runner([string]$Message) {
    Write-Error $Message
    exit 2
}

# Default to the repository root two levels above tools/finance.
if ([string]::IsNullOrWhiteSpace($RepoPath)) {
    $RepoPath = Join-Path $PSScriptRoot "..\.."
}

try {
    $resolved = Resolve-Path -LiteralPath $RepoPath -ErrorAction Stop
    $RepoRoot = $resolved.Path
} catch {
    Stop-Runner "Repository path does not exist: $RepoPath. This runner never clones or downloads the repository."
}

if (-not (Test-Path -LiteralPath (Join-Path $RepoRoot ".git"))) {
    Stop-Runner "Not a Git working tree: $RepoRoot. Open an existing ARNA API checkout; this runner will not download one."
}
foreach ($required in @("artisan", "composer.json", "phpunit.xml", "vendor/autoload.php", "vendor/bin/phpunit")) {
    if (-not (Test-Path -LiteralPath (Join-Path $RepoRoot $required))) {
        Stop-Runner "Required local file is missing: $required. No dependency installation or download was attempted."
    }
}

$origin = ""
try {
    $origin = (& git -C $RepoRoot remote get-url origin 2>$null | Select-Object -First 1)
} catch {
    $origin = ""
}
if ([string]::IsNullOrWhiteSpace($origin)) {
    Stop-Runner "Cannot identify origin remote. This runner requires an existing checkout with an inspectable Git origin."
}
if ($origin -notmatch "github\.com[:/]akefadaka-git/arna-api(?:\.git)?$") {
    Stop-Runner "Origin is not the destination repository akefadaka-git/arna-api: $origin. No fetch, pull, or remote mutation was attempted."
}

$branch = (& git -C $RepoRoot branch --show-current 2>$null | Select-Object -First 1)
$commit = (& git -C $RepoRoot rev-parse HEAD 2>$null | Select-Object -First 1)
$dirty = (& git -C $RepoRoot status --porcelain 2>$null)
Write-Host "ARNA Finance local test runner"
Write-Host "Repository : $RepoRoot"
Write-Host "Origin     : $origin"
Write-Host "Branch     : $branch"
Write-Host "Commit     : $commit"
if ($dirty) {
    Write-Warning "Working tree has existing changes. The runner will not stash, reset, clean, checkout, or overwrite them."
}

$phpCommand = Get-Command php -ErrorAction SilentlyContinue
if (-not $phpCommand) {
    Stop-Runner "PHP CLI was not found on PATH. Install/configure PHP 8.3+ locally; this runner will not install it."
}
$PhpExe = $phpCommand.Source
$phpVersion = (& $PhpExe -r "echo PHP_VERSION;" 2>$null | Select-Object -First 1)
if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($phpVersion)) {
    Stop-Runner "Unable to query the PHP CLI version."
}
try {
    if ([version]$phpVersion -lt [version]"8.3.0") {
        Stop-Runner "PHP 8.3+ is required by composer.json; found $phpVersion. Tests were not started."
    }
} catch {
    Stop-Runner "Could not parse PHP CLI version '$phpVersion'."
}

# Fail closed unless PHPUnit itself pins tests to an isolated SQLite in-memory database.
$phpunitXml = Get-Content -LiteralPath (Join-Path $RepoRoot "phpunit.xml") -Raw
if ($phpunitXml -notmatch '<env\s+name="APP_ENV"\s+value="testing"\s*/?>') {
    Stop-Runner "phpunit.xml does not explicitly set APP_ENV=testing. Tests were not started."
}
if ($phpunitXml -notmatch '<env\s+name="DB_CONNECTION"\s+value="sqlite"\s*/?>' -or
    $phpunitXml -notmatch '<env\s+name="DB_DATABASE"\s+value=":memory:"\s*/?>') {
    Stop-Runner "phpunit.xml does not pin the test database to SQLite :memory:. Tests were not started to avoid risking a configured database."
}

$arguments = @("--configuration", "phpunit.xml")
$cacheRoot = Join-Path ([System.IO.Path]::GetTempPath()) ("arna-finance-phpunit-" + [guid]::NewGuid().ToString("N"))
New-Item -ItemType Directory -Path $cacheRoot -Force | Out-Null
$arguments += @("--cache-directory", $cacheRoot)

if ($TestPath.Count -gt 0) {
    $resolvedTests = @()
    foreach ($item in $TestPath) {
        $candidate = if ([System.IO.Path]::IsPathRooted($item)) { $item } else { Join-Path $RepoRoot $item }
        if (-not (Test-Path -LiteralPath $candidate -PathType Leaf)) {
            Stop-Runner "Requested test file does not exist: $item"
        }
        $full = (Resolve-Path -LiteralPath $candidate).Path
        $rootPrefix = $RepoRoot.TrimEnd([IO.Path]::DirectorySeparatorChar, [IO.Path]::AltDirectorySeparatorChar) + [IO.Path]::DirectorySeparatorChar
        if (-not $full.StartsWith($rootPrefix, [System.StringComparison]::OrdinalIgnoreCase)) {
            Stop-Runner "Test path must remain inside the selected repository: $item"
        }
        if ([IO.Path]::GetExtension($full) -ne ".php") {
            Stop-Runner "Only PHP test files are accepted: $item"
        }
        $resolvedTests += $full
    }
    $arguments += $resolvedTests
} elseif ($Suite -eq "Finance") {
    $financeTests = @()
    $scanRoots = @(
        (Join-Path $RepoRoot "tests"),
        (Join-Path $RepoRoot "mases_core/src/Domains/Finance")
    )
    foreach ($root in $scanRoots) {
        if (Test-Path -LiteralPath $root -PathType Container) {
            $financeTests += Get-ChildItem -LiteralPath $root -Recurse -File -Filter "*Test.php" |
                Where-Object {
                    $_.FullName -match '(?i)(Finance|CashFlow|Accounting|Ledger|Journal|ChartOfAccounts|ControlAccount|FinancialReport)' -or
                    $_.FullName -match '(?i)Domains[\\/]+Finance[\\/].*[\\/]+Tests[\\/]'
                } |
                ForEach-Object { $_.FullName }
        }
    }
    $financeTests = @($financeTests | Sort-Object -Unique)
    if ($financeTests.Count -eq 0) {
        Stop-Runner "No Finance-related PHP test files were discovered. No tests were run."
    }
    $arguments += $financeTests
} else {
    if ($Suite -ne "All") {
        $arguments += @("--testsuite", $Suite)
    }
}

if (-not [string]::IsNullOrWhiteSpace($Filter)) {
    $arguments += @("--filter", $Filter)
}

if ($ListOnly) {
    Write-Host "Discovery command:"
    Write-Host ('php vendor/bin/phpunit ' + ($arguments -join " "))
    exit 0
}

Write-Host "PHP        : $phpVersion"
Write-Host "Suite      : $Suite"
Write-Host "Filter     : $(if ($Filter) { $Filter } else { '(none)' })"
Write-Host "Execution  : local PHPUnit only; no GitHub Actions/CI is invoked"
Write-Host "Database   : guarded by phpunit.xml (SQLite in-memory)"
Write-Host "Running tests. Existing repository files and dependencies will not be installed or updated."

Push-Location $RepoRoot
try {
    & $PhpExe "vendor/bin/phpunit" @arguments
    $testExitCode = $LASTEXITCODE
} finally {
    Pop-Location
    if (Test-Path -LiteralPath $cacheRoot) {
        Remove-Item -LiteralPath $cacheRoot -Recurse -Force -ErrorAction SilentlyContinue
    }
}
if ($testExitCode -ne 0) {
    Write-Error "Finance test run failed or PHPUnit returned exit code $testExitCode."
    exit $testExitCode
}
Write-Host "Finance test run passed. This result applies only to local commit $commit."
exit 0
