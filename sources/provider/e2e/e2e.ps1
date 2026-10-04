# SPDX-License-Identifier: MIT
#
# The end-to-end procedure of spec section 18 (REQ-TST-8) against a real server, with throwaway
# containers only. It runs the tofu steps, checks what each one should have done, and stops for
# the steps a person does in the webgui. At the first failure it stops and leaves everything in
# place, as the procedure asks. tofu has to find the provider, for example in a filesystem mirror
# before a registry lists it. Each run works in a fresh folder under sources/provider/.work/ and
# writes summary.txt there.
#
#   $env:TOFUMAN_ENDPOINT = 'https://<server>'
#   $env:TOFUMAN_API_KEY = '<key>'
#   pwsh -File e2e.ps1
#
# Preconditions from section 18: the plugin is installed on the server, the API key has only
# DOCKER:CREATE_ANY and is on the key allowlist, and /mnt/user/appdata/ is a bind root. At the
# end the script offers to remove the adopted container again; the host path
# /mnt/user/appdata/tofuman-e2e stays, as every delete leaves host paths (REQ-MUT-13).

#Requires -Version 7.0
Set-StrictMode -Version 3.0
$ErrorActionPreference = 'Stop'

if (-not $env:TOFUMAN_ENDPOINT) { throw 'Set TOFUMAN_ENDPOINT to the base URL of the server.' }
if (-not $env:TOFUMAN_API_KEY) { throw 'Set TOFUMAN_API_KEY to the API key.' }

$work = Join-Path (Split-Path $PSScriptRoot -Parent) ".work/e2e-$((Get-Date).ToUniversalTime().ToString('yyyyMMddTHHmmssZ'))"
New-Item -ItemType Directory -Path $work | Out-Null
Copy-Item -Path (Join-Path $PSScriptRoot 'providers.tf'), (Join-Path $PSScriptRoot 'e2e.tf') -Destination $work
Push-Location $work

function Write-Note([string] $Line) {
  Write-Host $Line
  Add-Content -Path summary.txt -Value $Line
}

function Write-Pass([string] $What) {
  Write-Note "ok    $What"
}

function Stop-Run([string] $What) {
  Write-Note "FAIL  $What"
  Write-Note "Stopped. The containers stay as they are; download the diagnostics file from the tofuman tab, and do not promote the candidate release. Work folder: $work"
  Pop-Location
  exit 1
}

# A step in the webgui: the person does it and answers.
function Confirm-Step([string] $Step, [string] $What) {
  Write-Host ''
  Write-Host $Step
  $answer = Read-Host '  Done, and as described? [y/n]'
  if ($answer -in 'y', 'yes') { Write-Pass $What } else { Stop-Run "$What (answer: $answer)" }
}

# Runs tofu with everything it prints in a file, and returns its exit code.
function Invoke-Tofu([string] $Log, [string[]] $Arguments) {
  & tofu @Arguments *> $Log
  return $LASTEXITCODE
}

function Show-Log([string] $Log) {
  Get-Content $Log | Write-Host
}

# Whether the whole log matches: tofu wraps long lines of a diagnostic, so a pattern that
# allows whitespace between words matches across them.
function Test-Log([string] $Log, [string] $Pattern) {
  return (Get-Content $Log -Raw) -match $Pattern
}

# The exit code of tofu plan -detailed-exitcode: 0 no change, 2 changes, 1 error.
function Get-PlanCode([string[]] $Extra = @()) {
  return Invoke-Tofu 'plan.txt' (@('plan', '-no-color', '-input=false', '-detailed-exitcode') + $Extra)
}

function Invoke-Apply([string[]] $Extra = @()) {
  if ((Invoke-Tofu 'apply.txt' (@('apply', '-no-color', '-input=false', '-auto-approve') + $Extra)) -eq 0) { return $true }
  Show-Log apply.txt
  return $false
}

function Get-ManagedId([string] $Address) {
  Invoke-Tofu 'state.txt' @('state', 'show', '-no-color', $Address) | Out-Null
  $match = Select-String -Path state.txt -Pattern '^    id\s*=\s*"(.*)"$' | Select-Object -First 1
  if ($match) { return $match.Matches[0].Groups[1].Value }
  return $null
}

Write-Note "tofuman end-to-end, $((Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')), $(& tofu version | Select-Object -First 1)"
if ((Invoke-Tofu 'init.txt' @('init', '-no-color', '-input=false')) -ne 0) {
  Show-Log init.txt
  Stop-Run 'tofu init'
}
$provider = Select-String -Path init.txt -Pattern 'tomzorz/tofuman v[0-9][0-9.]*[0-9]' | Select-Object -First 1
Write-Note "      provider $($provider ? $provider.Matches[0].Value : 'unknown')"

# Steps 1 and 2
if (-not (Invoke-Apply)) { Stop-Run '1-2: apply creates tofuman-e2e' }
if ((Get-PlanCode) -ne 0) {
  Show-Log plan.txt
  Stop-Run '1-2: the plan right after the apply shows no change'
}
$id = Get-ManagedId 'tofuman_container.e2e'
Write-Pass "1-2: apply creates tofuman-e2e with the managed ID $id, and the next plan shows no change"

Confirm-Step 'Step 3. In the webgui, open Docker and check that tofuman-e2e runs, has Edit in its menu, and shows an update status such as "up-to-date" (DockerMan tracks updates only for its own containers; Update appears there when a newer image exists).' `
  '3: tofuman-e2e runs, has Edit, and has an update status'

# Steps 4 and 5
Write-Host ''
Write-Host 'Step 4. In the webgui, select Edit on tofuman-e2e, change GREETING to anything else, and select Apply.'
Read-Host '  Press Enter when it is done' | Out-Null
# tofu hides unchanged attributes of a block, so the plan names the value it goes back to, not the key
if ((Get-PlanCode) -ne 2 -or -not (Select-String -Path plan.txt -Pattern '"hello"' -Quiet)) {
  Show-Log plan.txt
  Stop-Run '5: tofu plan shows the edit of GREETING as drift'
}
Write-Pass '5: tofu plan shows the edit of GREETING as drift'

# Step 6
if (-not (Invoke-Apply)) { Stop-Run '6: apply puts hello back' }
if ((Get-PlanCode) -ne 0) {
  Show-Log plan.txt
  Stop-Run '6: the plan after the revert shows no change'
}
Write-Pass '6: apply puts hello back, and the next plan shows no change'

# Step 7
$rename = @('-var', 'name=tofuman-e2e2')
$code = Get-PlanCode $rename
if ($code -ne 2 -or -not (Select-String -Path plan.txt -Pattern 'updated in-place' -Quiet) -or (Select-String -Path plan.txt -Pattern 'must be replaced' -Quiet)) {
  Show-Log plan.txt
  Stop-Run '7: the rename plans an update in place'
}
if (-not (Invoke-Apply $rename)) { Stop-Run '7: apply renames tofuman-e2e to tofuman-e2e2' }
if ((Get-ManagedId 'tofuman_container.e2e') -ne $id) { Stop-Run "7: the managed ID stays $id through the rename" }
Write-Pass "7: the rename updates in place and keeps the managed ID $id"
Confirm-Step "Step 7. On the tofuman tab, check that tofuman-e2e2 shows the managed ID $id." `
  "7: the tab shows $id under tofuman-e2e2"

# Step 8: the plan names a failed check before anything changes (REQ-PRV-15)
$code = Get-PlanCode ($rename + @('-var', 'extra_params=["--tofuman-e2e-unknown"]'))
if ($code -ne 1 -or -not (Test-Log plan.txt 'tofuman\s+would\s+refuse') -or -not (Test-Log plan.txt 'tofuman-e2e-unknown')) {
  Show-Log plan.txt
  Stop-Run '8: tofu plan names the flag that tofuman does not know'
}
Write-Pass '8: tofu plan names the flag that tofuman does not know, and nothing changes'

# Step 9: a command that exits at once fails the apply, and the previous container comes back (REQ-MUT-19)
$dies = $rename + @('-var', 'command=["sh","-c","echo tofuman-e2e-start-check; exit 3"]')
if ((Invoke-Tofu 'apply.txt' (@('apply', '-no-color', '-input=false', '-auto-approve') + $dies)) -eq 0 -or
  -not (Test-Log apply.txt 'start\s+check') -or -not (Test-Log apply.txt 'tofuman-e2e-start-check')) {
  Show-Log apply.txt
  Stop-Run '9: the apply fails at the start check with the log line of the container'
}
if ((Get-PlanCode $rename) -ne 0) {
  Show-Log plan.txt
  Stop-Run '9: the previous container is back, so the plan without the new command shows no change'
}
Write-Pass '9: the apply fails at the start check with the log line, and the previous container is back'

# Step 10
if ((Invoke-Tofu 'destroy.txt' (@('destroy', '-no-color', '-input=false', '-auto-approve') + $rename)) -ne 0) {
  Show-Log destroy.txt
  Stop-Run '10: destroy removes tofuman-e2e2'
}
Remove-Item e2e.tf # the import below plans against the adopted container alone
Write-Pass '10: destroy removes tofuman-e2e2'
Confirm-Step 'Step 10. In the webgui, check that tofuman-e2e2 is gone from the Docker page.' `
  '10: tofuman-e2e2 is gone'

# Step 11
Write-Host ''
Write-Host 'Step 11. In the webgui, create a throwaway container with Add Container (a small image, no masked variables). Then on the tofuman tab, open Hand-made containers, tick it, and select Adopt selected.'
$adopted = Read-Host '  Its name'
if (-not $adopted) { Stop-Run '11: a hand-made container to adopt' }
Write-Pass "11: $adopted is adopted in the tab"

# Step 12
Set-Content -Path adopt.tf -Encoding ascii -Value "import {`n  to = tofuman_container.adopted`n  id = `"$adopted`"`n}"
$code = Get-PlanCode @('-generate-config-out=adopted.tf')
if ($code -notin 0, 2 -or -not (Test-Path adopted.tf)) {
  Show-Log plan.txt
  Stop-Run "12: tofu writes the HCL of $adopted"
}
if (-not (Invoke-Apply)) { Stop-Run "12: tofu imports $adopted" }
if ((Get-PlanCode) -ne 0) {
  Show-Log plan.txt
  Stop-Run '12: after the import, tofu plan shows no change'
}
Write-Pass "12: tofu imports $adopted by its name, writes matching HCL, and the next plan shows no change"

Confirm-Step 'Step 13. On the tofuman tab, check that the activity shows each mutation and the adoption, and that selecting the failed update of step 9 shows its operation with the log line.' `
  '13: the activity shows each mutation, the adoption, and the operation of step 9'

Write-Note 'All steps passed.'
$answer = Read-Host "  Remove $adopted again, through tofu? [y/n]"
if ($answer -in 'y', 'yes') {
  if ((Invoke-Tofu 'destroy.txt' @('destroy', '-no-color', '-input=false', '-auto-approve')) -eq 0) {
    Write-Note "      $adopted is gone. /mnt/user/appdata/tofuman-e2e stays on the server; delete it there if you like."
  } else {
    Show-Log destroy.txt
    Write-Note "      The removal of $adopted failed; 'tofu destroy' in $work tries again."
  }
} else {
  Write-Note "      $adopted stays on the server and in this state: 'tofu destroy' in $work removes it."
}
Pop-Location
