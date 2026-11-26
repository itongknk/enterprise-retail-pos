param(
    [string]$ContainerName = "erp_pos_postgres",
    [string]$DbUser = "pos",
    [string]$DbName = "retail_pos",
    [string]$OutDir = "H:\enterprise-retail-pos\backups"
)

New-Item -ItemType Directory -Force -Path $OutDir | Out-Null
$timestamp = Get-Date -Format "yyyyMMdd_HHmmss"
$outFile = Join-Path $OutDir "${DbName}_${timestamp}.sql"

docker exec $ContainerName pg_dump -U $DbUser -d $DbName --clean --if-exists |
    Out-File -FilePath $outFile -Encoding utf8

if ($LASTEXITCODE -eq 0 -and (Test-Path $outFile) -and ((Get-Item $outFile).Length -gt 0)) {
    Write-Host "Backup succeeded: $outFile"
} else {
    Write-Error "Backup failed!"
    exit 1
}