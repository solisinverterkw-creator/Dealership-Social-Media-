# Packages the whole project into ONE zip (deploy_upload.zip) ready to upload
# to GoogieHost File Manager. Run this after making any changes.
#
# Deliberately EXCLUDES:
#   - config.php            (live server has different DB credentials than local dev)
#   - assets/uploads/        (live user-uploaded logos/submissions/vehicle images)
#   - .claude/, .git/        (local tooling only, not needed on the server)
#   - old deploy zips + the DB export (not needed on the server)
#
# After running: upload deploy_upload.zip to public_html in File Manager,
# then right-click it -> Extract, and confirm "Overwrite" when asked.

$ErrorActionPreference = 'Stop'
$projectRoot = $PSScriptRoot
$staging = Join-Path $env:TEMP 'rosp_deploy_staging'
$zipPath = Join-Path $projectRoot 'deploy_upload.zip'

if (Test-Path $staging) { Remove-Item $staging -Recurse -Force }
New-Item -ItemType Directory -Path $staging | Out-Null

$excludeDirs = @(
    (Join-Path $projectRoot 'assets\uploads'),
    (Join-Path $projectRoot '.claude'),
    (Join-Path $projectRoot '.git')
)
$excludeFiles = @(
    'config.php',
    'deploy_upload.zip',
    'missing_folders.zip',
    'safe_update.zip',
    'full_site_update.zip',
    'dealership_dashboard_export.sql',
    'build_deploy_zip.ps1'
)

robocopy $projectRoot $staging /E /XD @excludeDirs /XF @excludeFiles /NFL /NDL /NJH /NJS /NP | Out-Null

if (Test-Path $zipPath) { Remove-Item $zipPath -Force }

# Compress-Archive stores directory entries using Windows' backslash
# separator, which Linux hosting's unzip reads as a literal character
# instead of a folder separator (e.g. "includes\Auth.php" ends up as one
# flat, wrongly-named file in public_html instead of inside includes/).
# Build the zip by hand via System.IO.Compression so every entry name uses
# forward slashes, matching the ZIP spec that Linux tools expect.
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$zip = [System.IO.Compression.ZipFile]::Open($zipPath, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    Push-Location $staging
    try {
        Get-ChildItem -Recurse -File | ForEach-Object {
            $relativePath = (Resolve-Path -Relative $_.FullName) -replace '^\.[\\/]', '' -replace '\\', '/'
            [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $_.FullName, $relativePath, [System.IO.Compression.CompressionLevel]::Optimal) | Out-Null
        }
    } finally {
        Pop-Location
    }
} finally {
    $zip.Dispose()
}
Remove-Item $staging -Recurse -Force

Write-Host ""
Write-Host "Deploy package ready: $zipPath"
Write-Host "1. GoogieHost File Manager me public_html folder open karein."
Write-Host "2. Upload > deploy_upload.zip select karein."
Write-Host "3. Zip par right-click > Extract, aur Overwrite confirm karein."
Write-Host "4. config.php aur assets/uploads/ is zip me nahi hain - wo live pe untouched rahenge."
