@echo off
REM one-click batch wrapper to run the PowerShell script with ExecutionPolicy bypass
SET SCRIPT_DIR=%~dp0
echo Running one-click PowerShell script from %SCRIPT_DIR%
powershell -NoProfile -ExecutionPolicy Bypass -File "%SCRIPT_DIR%one_click_start.ps1" -Install
pause
