param(
    [ValidateRange(1024, 65535)][int]$Port = 8004,
    [switch]$EnableAgent
)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$python = Join-Path $root '.venv-intelligence\Scripts\python.exe'
if (-not (Test-Path -LiteralPath $python)) {
    throw 'Create .venv-intelligence and install intelligence/requirements.txt first. See README.md.'
}
if ($EnableAgent -and -not (Test-Path -LiteralPath (Join-Path $root 'intelligence/.env'))) {
    throw 'Add a fresh OPENAI_API_KEY to intelligence/.env. Do not reuse a key shared in chat.'
}
Push-Location -LiteralPath $root
try {
    # The agent reads only intelligence/.env, never ambient environment credentials.
    & $python -m uvicorn intelligence.api.main:app --host 127.0.0.1 --port $Port
    $code = $LASTEXITCODE
} finally {
    Pop-Location
}
exit $code
