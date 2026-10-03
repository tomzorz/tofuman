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
# DOCKER:CREATE_ANY and is on the key allowlist, and /mnt/user/appdata/ is a bind root.

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
  Write-Note "Stopped. The containers stay as they are; copy the audit log from the tofuman tab. Work folder: $work"
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

Confirm-Step 'Step 3. In the webgui, open Docker and check that tofuman-e2e has Edit and Update and runs.' `
  '3: tofuman-e2e has Edit and Update and runs'

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

# Step 8
if ((Invoke-Tofu 'destroy.txt' (@('destroy', '-no-color', '-input=false', '-auto-approve') + $rename)) -ne 0) {
  Show-Log destroy.txt
  Stop-Run '8: destroy removes tofuman-e2e2'
}
Remove-Item e2e.tf # the import below plans against the adopted container alone
Write-Pass '8: destroy removes tofuman-e2e2'
Confirm-Step 'Step 8. In the webgui, check that tofuman-e2e2 is gone from the Docker page.' `
  '8: tofuman-e2e2 is gone'

# Step 9
Write-Host ''
Write-Host 'Step 9. In the webgui, create a throwaway container with Add Container (a small image, no masked variables), then select Adopt for it on the tofuman tab.'
$adopted = Read-Host '  Its name'
if (-not $adopted) { Stop-Run '9: a hand-made container to adopt' }
Write-Pass "9: $adopted is adopted in the tab"

# Step 10
Set-Content -Path adopt.tf -Encoding ascii -Value "import {`n  to = tofuman_container.adopted`n  id = `"$adopted`"`n}"
$code = Get-PlanCode @('-generate-config-out=adopted.tf')
if ($code -notin 0, 2 -or -not (Test-Path adopted.tf)) {
  Show-Log plan.txt
  Stop-Run "10: tofu writes the HCL of $adopted"
}
if (-not (Invoke-Apply)) { Stop-Run "10: tofu imports $adopted" }
if ((Get-PlanCode) -ne 0) {
  Show-Log plan.txt
  Stop-Run '10: after the import, tofu plan shows no change'
}
Write-Pass "10: tofu imports $adopted by its name, writes matching HCL, and the next plan shows no change"

Confirm-Step 'Step 11. On the tofuman tab, check that the audit log shows each mutation and the adoption.' `
  '11: the audit log shows each mutation and the adoption'

Write-Note "All steps passed. $adopted stays on the server and in this state: 'tofu destroy' in $work removes it."
Pop-Location
