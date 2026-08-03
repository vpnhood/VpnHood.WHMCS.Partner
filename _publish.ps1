<#
.SYNOPSIS
    Bumps the version and publishes a release of the VpnHood! Partner Connector.

.DESCRIPTION
    Triggers the "Release" GitHub Actions workflow (.github/workflows/release.yml),
    which increments ./VERSION, writes that number into every module, commits and
    tags it, builds the partner zip and publishes it as a GitHub release.

    The bump happens in CI, never here. Two people can publish at the same time
    without picking the same number: the workflow serialises releases, so the second
    run reads ./VERSION only after the first has pushed its bump.

    This script's job is to check that what you have locally is what is on GitHub —
    the release is built from the pushed branch, so anything uncommitted would not be
    in it — then start the workflow and follow it.

.PARAMETER Bump
    Which part of ./VERSION to increment: patch (default), minor, major, or none.
    minor and major are a judgement about what changed, so they are always yours to
    make; none releases ./VERSION exactly as committed.

.PARAMETER Version
    Exact version to release (MAJOR.MINOR.PATCH, optionally -rc.1). Overrides -Bump.

.PARAMETER Ref
    Branch to release from. Defaults to the branch currently checked out.

.PARAMETER Draft
    Create the release as a draft — nothing is public until you press Publish on GitHub.

.PARAMETER PreRelease
    Mark the release as a pre-release. A version with a -suffix is treated as one anyway.

.PARAMETER Force
    Skip the "your working tree matches origin" safety checks.

.PARAMETER NoWait
    Start the workflow and return immediately instead of following it to the end.

.EXAMPLE
    ./_publish.ps1
    Patch bump (1.0.0 -> 1.0.1), tagged and released.

.EXAMPLE
    ./_publish.ps1 minor
    Minor bump (1.0.1 -> 1.1.0).

.EXAMPLE
    ./_publish.ps1 -Version 2.0.0-rc.1
    Releases exactly 2.0.0-rc.1, automatically marked as a pre-release.

.EXAMPLE
    ./_publish.ps1 -Draft
    Builds and tags as usual, but leaves the release unpublished for review.
#>
[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet('patch', 'minor', 'major', 'none')]
    [string] $Bump = 'patch',

    [ValidatePattern('^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$')]
    [string] $Version,

    [string] $Ref,

    [switch] $Draft,
    [switch] $PreRelease,
    [switch] $Force,
    [switch] $NoWait
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$RepoRoot = $PSScriptRoot
$Workflow = 'release.yml'

function Fail([string] $Message) {
    Write-Host "ERROR: $Message" -ForegroundColor Red
    exit 1
}

function Step([string] $Message) {
    Write-Host "==> $Message" -ForegroundColor Cyan
}

# git and gh write ordinary progress to stderr, which PowerShell can raise as a
# terminating error. Judge success by the exit code instead.
function Invoke-Native([string] $Exe, [string[]] $Arguments, [switch] $AllowFailure) {
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $output = & $Exe @Arguments 2>&1 | ForEach-Object { "$_" }
    }
    finally {
        $ErrorActionPreference = $previous
    }
    if ($LASTEXITCODE -ne 0 -and -not $AllowFailure) {
        Fail "$Exe $($Arguments -join ' ') failed:`n$($output -join "`n")"
    }
    return $output
}

Push-Location $RepoRoot
try {
    # --- prerequisites -------------------------------------------------------
    if (-not (Get-Command gh -ErrorAction SilentlyContinue)) {
        Fail 'GitHub CLI not found. Install it from https://cli.github.com and run `gh auth login`.'
    }
    Invoke-Native gh @('auth', 'status') | Out-Null

    if (-not $Ref) {
        $Ref = (Invoke-Native git @('rev-parse', '--abbrev-ref', 'HEAD')) -join ''
        if ($Ref -eq 'HEAD') { Fail 'Detached HEAD — pass -Ref <branch> to say which branch to release.' }
    }

    # --- the release must come from code that is actually on GitHub ----------
    # The workflow checks out $Ref from origin, so anything uncommitted or unpushed
    # here would silently not be in the zip.
    if (-not $Force) {
        $dirty = Invoke-Native git @('status', '--porcelain')
        if ($dirty) {
            Fail ("Uncommitted changes — they would not be in the release:`n" +
                  ($dirty -join "`n") + "`nCommit and push them, or re-run with -Force.")
        }

        Step "Fetching origin/$Ref"
        Invoke-Native git @('fetch', '--quiet', 'origin', $Ref) | Out-Null

        $local  = (Invoke-Native git @('rev-parse', 'HEAD')) -join ''
        $remote = (Invoke-Native git @('rev-parse', "origin/$Ref")) -join ''
        if ($local -ne $remote) {
            Fail "HEAD ($($local.Substring(0, 7))) is not origin/$Ref ($($remote.Substring(0, 7))). Push (or pull) first, or re-run with -Force."
        }
    }

    # --- what are we about to ship? ------------------------------------------
    $versionFile = Join-Path $RepoRoot 'VERSION'
    if (-not (Test-Path $versionFile)) { Fail "$versionFile is missing." }
    $declared = (Get-Content $versionFile -Raw).Trim()

    if ($Version) {
        $plan = "$Version (exactly as given)"
        # Caught in the workflow too, but failing here saves a round-trip.
        $existing = Invoke-Native git @('ls-remote', '--tags', 'origin', "refs/tags/v$Version") -AllowFailure
        if ($existing) { Fail "v$Version is already released. Pick another version." }
    }
    elseif ($Bump -eq 'none') {
        $plan = "$declared (./VERSION as committed)"
    }
    else {
        $plan = "$Bump bump of $declared — the workflow computes the final number"
    }

    Write-Host ''
    Write-Host "  Repository  $((Invoke-Native gh @('repo', 'view', '--json', 'nameWithOwner', '-q', '.nameWithOwner')) -join '')"
    Write-Host "  Branch      $Ref"
    Write-Host "  Releasing   $plan"
    if ($Draft)      { Write-Host '  Draft       yes — you must press Publish on GitHub' }
    if ($PreRelease) { Write-Host '  Pre-release yes' }
    Write-Host ''

    # --- dispatch ------------------------------------------------------------
    # Runs are identified by id, so remember the newest one to spot ours appearing.
    $lastRunId = 0
    $previousRuns = Invoke-Native gh @('run', 'list', '--workflow', $Workflow, '--limit', '1', '--json', 'databaseId') -AllowFailure
    if ($previousRuns) {
        $parsed = @(($previousRuns -join '') | ConvertFrom-Json)
        if ($parsed.Count -gt 0) { $lastRunId = [int64] $parsed[0].databaseId }
    }

    $dispatch = @('workflow', 'run', $Workflow, '--ref', $Ref, '-f', "bump=$Bump")
    if ($Version) { $dispatch += @('-f', "version=$Version") }
    $dispatch += @('-f', "draft=$($Draft.IsPresent.ToString().ToLowerInvariant())")
    $dispatch += @('-f', "prerelease=$($PreRelease.IsPresent.ToString().ToLowerInvariant())")

    Step "Starting the Release workflow on $Ref"
    Invoke-Native gh $dispatch | Out-Null

    # GitHub queues the run asynchronously; it shows up a second or two later.
    $runId = $null
    $runUrl = $null
    foreach ($attempt in 1..30) {
        Start-Sleep -Seconds 2
        $listed = Invoke-Native gh @('run', 'list', '--workflow', $Workflow, '--limit', '10', '--json', 'databaseId,url') -AllowFailure
        if ($listed) {
            $runs = @(($listed -join '') | ConvertFrom-Json)
            $fresh = $runs | Where-Object { [int64] $_.databaseId -gt $lastRunId } |
                     Sort-Object databaseId | Select-Object -First 1
            if ($fresh) { $runId = $fresh.databaseId; $runUrl = $fresh.url; break }
        }
    }

    if (-not $runId) {
        Write-Host 'Workflow dispatched, but the run did not appear in time. Check:' -ForegroundColor Yellow
        Write-Host "  gh run list --workflow $Workflow"
        exit 0
    }

    Write-Host "    $runUrl"

    if ($NoWait) {
        Write-Host ''
        Write-Host "Started. Follow it with: gh run watch $runId" -ForegroundColor Green
        exit 0
    }

    # --- follow it -----------------------------------------------------------
    # A queued release (someone else is mid-publish) sits here until it is let in.
    Step 'Waiting for the release to build'
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try { & gh run watch $runId --exit-status } finally { $ErrorActionPreference = $previous }

    if ($LASTEXITCODE -ne 0) {
        Write-Host ''
        Write-Host "Release failed. Logs: gh run view $runId --log-failed" -ForegroundColor Red
        exit 1
    }

    # --- report what actually shipped ----------------------------------------
    Write-Host ''
    $released = Invoke-Native gh @('release', 'list', '--limit', '1', '--json', 'tagName,url,isDraft') -AllowFailure
    if ($released) {
        $info = @(($released -join '') | ConvertFrom-Json)
        if ($info.Count -gt 0) {
            if ($info[0].isDraft) {
                Step "Draft $($info[0].tagName) created — review it, then press Publish"
            } else {
                Step "Released $($info[0].tagName)"
            }
            Write-Host "    $($info[0].url)" -ForegroundColor Green
        }
    }

    # The workflow pushed a bump commit and a tag; this clone is now behind.
    Invoke-Native git @('fetch', '--quiet', '--tags', 'origin', $Ref) -AllowFailure | Out-Null
    Write-Host ''
    Write-Host "Run 'git pull' to pick up the version bump commit." -ForegroundColor Yellow
}
finally {
    Pop-Location
}
