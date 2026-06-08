@echo off
cd /d "C:\Users\Administrator\Downloads\Telegram Desktop\dontplay-site\dontplay-site"

:: Kill any existing node/ngrok
taskkill /f /im node.exe 2>nul
taskkill /f /im ngrok.exe 2>nul
timeout /t 2 /nobreak >nul

:: Start node server (background window)
start /b "" node server.js > "%USERPROFILE%\server.log" 2> "%USERPROFILE%\server.err"

:: Wait for server to start
timeout /t 3 /nobreak >nul

:: Start ngrok tunnel (background window)
start /b "" ngrok http 3000 --log=stdout > "%USERPROFILE%\ngrok_output.txt" 2> "%USERPROFILE%\ngrok_err.txt"

exit