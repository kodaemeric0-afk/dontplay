@echo off
cd /d "C:\Users\Administrator\Downloads\Telegram Desktop\dontplay-site\dontplay-site"

:: Check if ngrok is running
tasklist /FI "IMAGENAME eq ngrok.exe" 2>nul | find /I "ngrok.exe" >nul
if %ERRORLEVEL% NEQ 0 (
    :: Check if node is running, if not start it
    tasklist /FI "IMAGENAME eq node.exe" 2>nul | find /I "node.exe" >nul
    if %ERRORLEVEL% NEQ 0 (
        start /b "" node server.js > "%USERPROFILE%\server.log" 2> "%USERPROFILE%\server.err"
        timeout /t 3 /nobreak >nul
    )
    :: Start ngrok
    start /b "" ngrok http 3000 --log=stdout > "%USERPROFILE%\ngrok_output.txt" 2> "%USERPROFILE%\ngrok_err.txt"
)

exit