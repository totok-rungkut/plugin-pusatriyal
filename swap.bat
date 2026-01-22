@echo off
REM ============================================
REM Swap isi file antara file1 dan file2
REM Usage: swap.bat file1 file2
REM Bisa drag & drop 2 file ke swap.bat di Explorer
REM ============================================

REM Cek jumlah argumen
if "%~2"=="" (
    echo Usage: swap.bat file1 file2
    echo Drag & drop 2 file ke swap.bat
    pause
    exit /b
)

set "file1=%~1"
set "file2=%~2"
set "temp=%~dp0swap_temp.tmp"

REM Copy file1 ke temp
copy /Y "%file1%" "%temp%" >nul

REM Copy file2 ke file1
copy /Y "%file2%" "%file1%" >nul

REM Copy temp ke file2
copy /Y "%temp%" "%file2%" >nul

REM Hapus file temp
del "%temp%"

echo Selesai swap isi file:
echo %file1%
echo %file2%
pause
