# Synchronize only the People local-test runner from the destination GitHub repository.
# This does not use or modify the checkout's origin remote, does not run tests,
# and does not reset, stash, checkout, commit, or push any local changes.
[CmdletBinding()]
param(
    [string] $RepositoryPath = (Get-Location).Path,
    [string] $Branch = 'feat/people-domain-phase-2-api'
)

$ErrorActionPreference = 'Stop'
$Repository = 'akefadaka-git/arna-api'
$RelativePath = 'scripts/people/Invoke-ArnaPeopleLocalTests.ps1'

function Invoke-GhChecked([string[]] $Arguments) {
    $output = & gh @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "GitHub CLI failed ($LASTEXITCODE): gh $($Arguments -join ' ')"
    }
    return ($output -join "`n")
}

$repoPath = (Resolve-Path -LiteralPath $RepositoryPath).Path
$targetPath = Join-Path $repoPath ($RelativePath -replace '/', [IO.Path]::DirectorySeparatorChar)
if (-not (Test-Path -LiteralPath (Join-Path $repoPath '.git'))) {
    throw "RepositoryPath is not a Git checkout: $repoPath"
}
if (-not (Test-Path -LiteralPath $targetPath)) {
    throw "Local runner does not exist: $targetPath"
}
$branchNow = (& git -C $repoPath branch --show-current).Trim()
if ($LASTEXITCODE -ne 0) { throw 'Could not determine the local branch.' }
if ($branchNow -ne $Branch) {
    throw "Local branch '$branchNow' does not match expected '$Branch'. No files changed."
}
$null = Get-Command gh -ErrorAction Stop
$null = Get-Command git -ErrorAction Stop

# Confirm authenticated access to the destination repo. Never rely on origin,
# which may still point to the retired/source owner (akeayu/arna-api).
$repoInfo = Invoke-GhChecked @('api', "repos/$Repository")
$repoMetadata = $repoInfo | ConvertFrom-Json
if ($repoMetadata.full_name -ne $Repository) {
    throw "GitHub returned '$($repoMetadata.full_name)' instead of '$Repository'. No files changed."
}

$endpoint = "repos/$Repository/contents/$RelativePath"
$json = Invoke-GhChecked @('api', $endpoint, '-f', "ref=$Branch")
$remote = $json | ConvertFrom-Json
if (-not $remote.sha -or -not $remote.content -or $remote.encoding -ne 'base64') {
    throw 'Destination API response did not contain the expected base64 file content and blob SHA. No files changed.'
}

$bytes = [Convert]::FromBase64String(($remote.content -replace '\s', ''))
$decoded = [Text.Encoding]::UTF8.GetString($bytes)
if ($decoded -match '(?i)artisan.{0,40}route:list|route:list.{0,40}artisan') {
    throw 'Destination runner still contains artisan route:list. Local file was not changed.'
}
if ($decoded -notmatch 'PeopleRouteRegistrationTest|Tests/Feature') {
    throw 'Destination runner does not reference the People feature acceptance suite. Local file was not changed.'
}

# Preserve the current runner before replacing exactly this one file.
$backup = "$targetPath.pre-sync-$(Get-Date -Format 'yyyyMMdd-HHmmss')"
Copy-Item -LiteralPath $targetPath -Destination $backup
[IO.File]::WriteAllBytes($targetPath, $bytes)

# Git blob SHA is content-addressed; compare local bytes with the API's exact blob SHA.
$localBlob = (& git -C $repoPath hash-object -- $targetPath).Trim()
if ($LASTEXITCODE -ne 0 -or $localBlob -ne $remote.sha) {
    throw "Local content verification failed (local=$localBlob, remote=$($remote.sha)). Backup preserved at $backup"
}

Write-Host 'Runner synchronized from destination GitHub branch.' -ForegroundColor Green
Write-Host "Repository: $Repository"
Write-Host "Branch: $Branch"
Write-Host "Remote/local blob SHA: $($remote.sha)"
Write-Host "Backup: $backup"
Write-Host 'No tests were run. No Git remote was changed. Only the runner file was replaced.'
