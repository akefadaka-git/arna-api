[CmdletBinding()]
param([string] $RepositoryPath = (Get-Location).Path,[switch] $RunMySql,[switch] $AllowDirtyTree)
$ErrorActionPreference = 'Stop'
$ExpectedBranch = 'feat/people-domain-phase-2-api'
$DomainPath = 'mases_core/src/Domains/People'
$TestsPath = "$DomainPath/Tests/Feature"
function Invoke-Checked([string] $Exe,[string[]] $Args) {
 Write-Host ("> " + $Exe + " " + ($Args -join " "))
 & $Exe @Args
 if ($LASTEXITCODE -ne 0) { throw "Command failed ($LASTEXITCODE): $Exe $($Args -join ' ')" }
}
$Repo = (Resolve-Path -LiteralPath $RepositoryPath).Path
Set-Location -LiteralPath $Repo
if (-not (Test-Path '.git') -or -not (Test-Path 'artisan') -or -not (Test-Path 'composer.json')) { throw 'RepositoryPath must point to an existing ARNA API Laravel Git checkout.' }
$branch = (& git branch --show-current).Trim()
if ($LASTEXITCODE -ne 0 -or $branch -ne $ExpectedBranch) { throw "Wrong branch '$branch'; expected '$ExpectedBranch'. No tests were run." }
$status = @(& git status --porcelain)
if ($LASTEXITCODE -ne 0) { throw 'Could not read git status.' }
if ($status.Count -gt 0 -and -not $AllowDirtyTree) {
 $status | ForEach-Object { Write-Host $_ -ForegroundColor Yellow }
 throw 'Working tree is dirty. Review changes, then rerun with -AllowDirtyTree if intentional. No changes were discarded.'
}
if ($status.Count -gt 0) { Write-Host 'Existing changes will be preserved. No reset, clean, stash, checkout, commit, or push is performed.' -ForegroundColor Yellow }
$null = Get-Command php -ErrorAction Stop
$version = (& php -r 'echo PHP_VERSION;').Trim()
if ($LASTEXITCODE -ne 0) { throw 'Unable to determine PHP CLI version.' }
if ([version]$version -lt [version]'8.3.0') { throw "PHP $version found; ARNA API requires PHP 8.3 or later." }
if (-not (Test-Path 'vendor/autoload.php')) { throw 'vendor/autoload.php is missing. Run composer install manually in this existing checkout.' }

Write-Host ''; Write-Host '=== PHP syntax: People domain ===' -ForegroundColor Cyan
$phpFiles = Get-ChildItem -LiteralPath $DomainPath -Recurse -File -Filter '*.php'
if (-not $phpFiles) { throw "No PHP files found under $DomainPath." }
foreach ($file in $phpFiles) { & php -l $file.FullName; if ($LASTEXITCODE -ne 0) { throw "Syntax error: $($file.FullName)" } }
Write-Host "Syntax checked $($phpFiles.Count) PHP files."

Write-Host ''; Write-Host '=== Laravel route registration (local only) ===' -ForegroundColor Cyan
Invoke-Checked 'php' @('artisan','route:list','--path=api/people','--except-vendor')
Write-Host ''; Write-Host '=== People feature acceptance tests ===' -ForegroundColor Cyan
if (-not (Test-Path $TestsPath)) { throw "Missing People test directory: $TestsPath" }
Invoke-Checked 'php' @('artisan','test',$TestsPath,'--stop-on-failure')

if ($RunMySql) {
 Write-Host ''; Write-Host '=== MySQL test safety check ===' -ForegroundColor Cyan
 $testingEnv = Join-Path $Repo '.env.testing'
 if (-not (Test-Path $testingEnv)) { throw 'Create and review .env.testing first; MySQL mode will not use .env.' }
 $lines = Get-Content -LiteralPath $testingEnv
 $connection = (($lines | Where-Object { $_ -match '^DB_CONNECTION=' } | Select-Object -First 1) -replace '^DB_CONNECTION=','').Trim('"', "'", ' ')
 $database = (($lines | Where-Object { $_ -match '^DB_DATABASE=' } | Select-Object -First 1) -replace '^DB_DATABASE=','').Trim('"', "'", ' ')
 if ($connection -ne 'mysql') { throw "DB_CONNECTION in .env.testing is '$connection', not mysql." }
 if ($database -notmatch '^arna.*(test|testing|disposable)') { throw "Database '$database' is not named like a dedicated ARNA test database. Refusing MySQL mode." }
 Write-Host "Configured .env.testing database: $database"
 Write-Host 'No database is created/dropped; migrate:fresh and db:wipe are never run.'
 $confirmation = Read-Host "Type the exact database name '$database' to run MySQL-mode tests"
 if ($confirmation -cne $database) { throw 'Database confirmation mismatch; MySQL mode cancelled.' }
 Invoke-Checked 'php' @('artisan','test',$TestsPath,'--stop-on-failure','--env=testing')
} else { Write-Host 'MySQL mode skipped. Use -RunMySql only after a dedicated disposable test database is configured in .env.testing.' -ForegroundColor Yellow }
Write-Host ''; Write-Host 'Completed local checks. No GitHub Actions workflow was triggered.' -ForegroundColor Green
