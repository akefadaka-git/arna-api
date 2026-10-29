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
function Read-DotEnv([string] $File) {
 $values=@{}
 foreach($line in Get-Content -LiteralPath $File) {
  if($line -match '^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)$') {
   $value=$Matches[2].Trim()
   if($value.Length -ge 2 -and (($value.StartsWith('"') -and $value.EndsWith('"')) -or ($value.StartsWith("'") -and $value.EndsWith("'")))) { $value=$value.Substring(1,$value.Length-2) }
   $values[$Matches[1]]=$value
  }
 }
 return $values
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
 Write-Host ''; Write-Host '=== Disposable MySQL migration and acceptance gate ===' -ForegroundColor Cyan
 $testingEnv = Join-Path $Repo '.env.testing'
 if (-not (Test-Path $testingEnv)) { throw 'Create and review .env.testing first; MySQL mode will not use .env.' }
 $envValues=Read-DotEnv $testingEnv
 if ($envValues['DB_CONNECTION'] -ne 'mysql') { throw 'DB_CONNECTION in .env.testing must be mysql.' }
 $database=[string]$envValues['DB_DATABASE']
 if ($database -notmatch '^arna.*(?:test|testing|disposable)) { throw "Database '$database' is not named like a dedicated ARNA test database. Refusing MySQL mode." }
 if (-not $envValues['DB_HOST'] -or -not $envValues['DB_USERNAME']) { throw '.env.testing must define DB_HOST and DB_USERNAME.' }
 Write-Host "Target database name: $database"
 Write-Host 'Provision this as an EMPTY disposable schema before continuing. This script does not create/drop databases and never runs migrate:fresh or db:wipe.'
 $confirmation = Read-Host "Type the exact database name '$database' to run the clean-install migration gate"
 if ($confirmation -cne $database) { throw 'Database confirmation mismatch; MySQL mode cancelled.' }
 foreach($key in @('APP_ENV','DB_CONNECTION','DB_HOST','DB_PORT','DB_DATABASE','DB_USERNAME','DB_PASSWORD','APP_KEY')) {
  if($envValues.ContainsKey($key)) { [Environment]::SetEnvironmentVariable($key,$envValues[$key],'Process') }
 }
 $phpCode = 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo json_encode(\Illuminate\Support\Facades\Schema::connection("mysql")->getTableListing());'
 $tableJson = & php -r $phpCode
 if ($LASTEXITCODE -ne 0) { throw 'Could not inspect MySQL schema; no migrations were run.' }
 $tables = @($tableJson | ConvertFrom-Json)
 if ($tables.Count -gt 0) { throw "Refusing clean-install check because schema is not empty. Found tables: $($tables -join ', ')" }
 Write-Host 'Verified the target schema has zero tables.'
 Invoke-Checked 'php' @('artisan','migrate','--env=testing','--database=mysql','--force')
 Invoke-Checked 'php' @('artisan','test',$TestsPath,'--stop-on-failure','--env=testing')
} else { Write-Host 'MySQL clean-install mode skipped. Use -RunMySql only with a manually provisioned empty disposable schema in .env.testing.' -ForegroundColor Yellow }
Write-Host ''; Write-Host 'Completed local checks. No GitHub Actions workflow was triggered.' -ForegroundColor Green
) { throw "Database '$database' is not named like a dedicated ARNA test database. Refusing MySQL mode." }
 if (-not $envValues['DB_HOST'] -or -not $envValues['DB_USERNAME']) { throw '.env.testing must define DB_HOST and DB_USERNAME.' }
 Write-Host "Target database name: $database"
 Write-Host 'Provision this as an EMPTY disposable schema before continuing. This script does not create/drop databases and never runs migrate:fresh or db:wipe.'
 $confirmation = Read-Host "Type the exact database name '$database' to run the clean-install migration gate"
 if ($confirmation -cne $database) { throw 'Database confirmation mismatch; MySQL mode cancelled.' }
 foreach($key in @('DB_CONNECTION','DB_HOST','DB_PORT','DB_DATABASE','DB_USERNAME','DB_PASSWORD')) {
  if($envValues.ContainsKey($key)) { [Environment]::SetEnvironmentVariable($key,$envValues[$key],'Process') }
 }
 Invoke-Checked 'php' @('artisan','migrate','--env=testing','--database=mysql','--force')
 Invoke-Checked 'php' @('artisan','test',$TestsPath,'--stop-on-failure','--env=testing')
} else { Write-Host 'MySQL clean-install mode skipped. Use -RunMySql only with a manually provisioned empty disposable schema in .env.testing.' -ForegroundColor Yellow }
Write-Host ''; Write-Host 'Completed local checks. No GitHub Actions workflow was triggered.' -ForegroundColor Green
