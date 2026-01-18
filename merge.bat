@echo off
setlocal

REM pastikan parameter ada
if "%~1"=="" (
    echo Usage: merge.bat filename.txt
    exit /b 1
)

REM jika merged.txt belum ada
if not exist merged.txt (
    copy "%~1" merged.txt >nul
) else (
    REM tambahkan separator lalu file
    type separator.txt >> merged.txt
    type "%~1" >> merged.txt
)

endlocal
