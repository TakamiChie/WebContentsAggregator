[CmdletBinding()]
param(
    [string]$DestinationDirectory = (Join-Path ([Environment]::GetFolderPath('UserProfile')) '.config\dagu\dags')
)

$ErrorActionPreference = 'Stop'

$sourceDirectory = [System.IO.Path]::GetFullPath($PSScriptRoot)
$sourcePath = Join-Path $sourceDirectory 'web_contents_aggregator.yaml'

if (-not (Test-Path -LiteralPath $sourcePath -PathType Leaf)) {
    throw "Workflow YAML was not found: $sourcePath"
}

$destinationDirectoryPath = [System.IO.Path]::GetFullPath($DestinationDirectory)
[System.IO.Directory]::CreateDirectory($destinationDirectoryPath) | Out-Null

$yaml = [System.IO.File]::ReadAllText($sourcePath)
$workingDirectoryValue = "'" + $sourceDirectory.Replace("'", "''") + "'"
$workingDirectoryPattern = '(?m)^(\s*working_dir\s*:\s*).*$'

if (-not [System.Text.RegularExpressions.Regex]::IsMatch($yaml, $workingDirectoryPattern)) {
    throw "The working_dir setting was not found in: $sourcePath"
}

$yaml = [System.Text.RegularExpressions.Regex]::Replace(
    $yaml,
    $workingDirectoryPattern,
    ('$1' + $workingDirectoryValue),
    1
)

$destinationPath = Join-Path $destinationDirectoryPath ([System.IO.Path]::GetFileName($sourcePath))
$utf8WithoutBom = [System.Text.UTF8Encoding]::new($false)
[System.IO.File]::WriteAllText($destinationPath, $yaml, $utf8WithoutBom)

Write-Host "Copied Dagu workflow to: $destinationDirectoryPath"
