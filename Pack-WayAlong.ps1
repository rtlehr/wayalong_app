param(
    [Parameter(Mandatory = $true)]
    [string]$ProjectPath,

    [Parameter(Mandatory = $true)]
    [string]$ZipName
)

$ErrorActionPreference = 'Stop'

$ProjectPath = (Resolve-Path $ProjectPath).Path.TrimEnd('\')
$ZipName = [System.IO.Path]::GetFileNameWithoutExtension($ZipName)

if ([string]::IsNullOrWhiteSpace($ZipName)) {
    throw 'ZIP name cannot be empty.'
}

$OutputDirectory = Join-Path $ProjectPath '_packages'
$ZipPath = Join-Path $OutputDirectory ($ZipName + '.zip')

New-Item -ItemType Directory -Force -Path $OutputDirectory | Out-Null

if (Test-Path $ZipPath) {
    Remove-Item $ZipPath -Force
}

$ExcludedDirectoryNames = @(
    '.git',
    '.idea',
    '.vscode',
    'node_modules',
    'vendor',
    '_packages'
)

$ExcludedRelativeDirectories = @(
    'bootstrap/cache',
    'public/build',
    'public/hot',
    'public/storage',
    'storage/app/private',
    'storage/app/public',
    'storage/framework/cache',
    'storage/framework/sessions',
    'storage/framework/testing',
    'storage/framework/views',
    'storage/logs',
    'docs'
)

$ExcludedFileNames = @(
    '.env',
    '.env-testing',
    '.phpunit.result.cache',
    'auth.json',
    'npm-debug.log',
    'yarn-error.log',
    'Thumbs.db',
    '.DS_Store',
    'phpinfo.php'
)

$ExcludedExtensions = @(
    '.log',
    '.sqlite',
    '.sqlite3'
)

function Get-NormalizedRelativePath {
    param([string]$FullPath)

    $rootUri = New-Object System.Uri(($ProjectPath + '\'))
    $fileUri = New-Object System.Uri($FullPath)
    $relativeUri = $rootUri.MakeRelativeUri($fileUri)
    $relativePath = [System.Uri]::UnescapeDataString($relativeUri.ToString())

    return $relativePath.Replace('\', '/')
}

function Test-IsExcluded {
    param(
        [System.IO.FileInfo]$File,
        [string]$RelativePath
    )

    $segments = $RelativePath.Split('/')

    foreach ($directoryName in $ExcludedDirectoryNames) {
        if ($segments -contains $directoryName) {
            return $true
        }
    }

    foreach ($prefix in $ExcludedRelativeDirectories) {
        if (
            $RelativePath.Equals($prefix, [System.StringComparison]::OrdinalIgnoreCase) -or
            $RelativePath.StartsWith($prefix + '/', [System.StringComparison]::OrdinalIgnoreCase)
        ) {
            return $true
        }
    }

    if ($ExcludedFileNames -contains $File.Name) {
        return $true
    }

    if ($RelativePath.Equals('public/phpinfo.php', [System.StringComparison]::OrdinalIgnoreCase)) {
        return $true
    }

    if ($ExcludedExtensions -contains $File.Extension.ToLowerInvariant()) {
        return $true
    }

    if ($File.Extension.Equals('.zip', [System.StringComparison]::OrdinalIgnoreCase)) {
        return $true
    }

    return $false
}

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$fileCount = 0
$totalBytes = 0

$archive = [System.IO.Compression.ZipFile]::Open(
    $ZipPath,
    [System.IO.Compression.ZipArchiveMode]::Create
)

try {
    Get-ChildItem -Path $ProjectPath -File -Recurse -Force | ForEach-Object {
        $relativePath = Get-NormalizedRelativePath $_.FullName

        if (-not (Test-IsExcluded -File $_ -RelativePath $relativePath)) {
            [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                $archive,
                $_.FullName,
                $relativePath,
                [System.IO.Compression.CompressionLevel]::Optimal
            ) | Out-Null

            $fileCount++
            $totalBytes += $_.Length
        }
    }
}
finally {
    $archive.Dispose()
}

$zipInfo = Get-Item $ZipPath
$sourceSizeMb = [math]::Round($totalBytes / 1MB, 2)
$zipSizeMb = [math]::Round($zipInfo.Length / 1MB, 2)

Write-Host ''
Write-Host 'Source package created successfully.' -ForegroundColor Green
Write-Host "Files included: $fileCount"
Write-Host "Source size:    $sourceSizeMb MB"
Write-Host "ZIP size:       $zipSizeMb MB"
Write-Host "Output:         $ZipPath"
