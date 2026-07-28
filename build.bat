@echo off
REM ===========================================================================
REM  Nofliq Server-Side A/B Testing - distribution build script (Windows)
REM
REM  Produces a clean ZIP for WordPress.org:
REM    - copies the plugin into a temp build folder
REM    - installs Composer runtime deps only (--no-dev): drops phpstan/stubs
REM    - purges the Flagship SDK's development baggage (demo, tests, CI, etc.)
REM    - removes the plugin's own dev files
REM    - zips the result with the correct internal folder name
REM
REM  Run from the plugin root. Does NOT touch your working vendor/ or files:
REM  everything happens inside .\build\ , which is recreated each run.
REM ===========================================================================

setlocal

set "SLUG=nofliq-server-side-ab-testing"
set "BUILD=build"
set "DEST=%BUILD%\%SLUG%"

echo.
echo === 1/6  Cleaning previous build ===
if exist "%BUILD%" rmdir /s /q "%BUILD%"
mkdir "%DEST%"

echo.
echo === 2/6  Copying plugin files ===
REM Copy everything, then prune. /E = subdirs incl. empty. Excludes below.
robocopy "." "%DEST%" /E ^
  /XD "%BUILD%" ".git" ".github" ".vscode" ".idea" "node_modules" "tests" ^
  /XF "build.bat" "phpstan.neon" "phpstan-constants.php" "composer.lock" ^
      "*.sql" "backup_*" ".gitattributes" ".gitignore" ".DS_Store" "Thumbs.db" >nul

echo.
echo === 3/6  Installing runtime dependencies (--no-dev) ===
pushd "%DEST%"
call composer install --no-dev --optimize-autoloader --quiet
if errorlevel 1 (
    echo ERROR: composer install failed.
    popd
    exit /b 1
)
popd

echo.
echo === 4/6  Purging Flagship SDK development files ===
set "SDK=%DEST%\vendor\flagship-io\flagship-php-sdk"
if exist "%SDK%" (
    for %%D in (.github .vs .vscode demo dockers tests) do (
        if exist "%SDK%\%%D" rmdir /s /q "%SDK%\%%D"
    )
    for %%F in (.dockerignore .gitignore clover.xml composer.lock phpcs.xml ^
                phpstan-future.neon phpstan.neon phpunit.xml readme.md ^
                test-report.xml) do (
        if exist "%SDK%\%%F" del /q "%SDK%\%%F"
    )
)

echo.
if exist "%DEST%\composer.lock" del /q "%DEST%\composer.lock"
echo === 5/6  Removing any stray dev packages from vendor ===
for %%D in (phpstan php-stubs bin) do (
    if exist "%DEST%\vendor\%%D" rmdir /s /q "%DEST%\vendor\%%D"
)

echo.
echo === 6/6  Creating ZIP ===
if exist "%SLUG%.zip" del /q "%SLUG%.zip"
REM PowerShell Compress-Archive keeps the top-level folder name (the -Path is
REM the folder itself), so the ZIP contains %SLUG%\... as WordPress.org expects.
powershell -NoProfile -Command "Compress-Archive -Path '%DEST%' -DestinationPath '%SLUG%.zip' -Force"

echo.
echo === DONE ===
echo Created %SLUG%.zip
echo Review the build\ folder to confirm its contents before uploading.
endlocal