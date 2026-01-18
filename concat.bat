@echo off
chcp 65001 >nul
setlocal enabledelayedexpansion

rem ===== KONFIGURASI =====
set "maxPerFile=9"
set "outputPrefix=gabs-"
set "jsMain=..\library\js\puri-main.js"
set "jsSFX=..\library\js\puri-sfx.js"
set "coreFile=..\puri-centre.php"
rem =======================

set /a fileCount=0
set /a outputIndex=1
set "outputFile=%outputPrefix%!outputIndex!.txt"

echo.
echo Membersihkan file output lama...

rem Bersihkan file output lama dengan benar
if exist %outputPrefix%*.txt (
    del /f /q %outputPrefix%*.txt 2>nul
    echo [x] File lama berhasil dihapus
) else (
    echo [i] Tidak ada file lama yang perlu dihapus
)
echo.
echo ───────────────────────────────────────
echo Menggabungkan file PHP dan JSON...
echo ───────────────────────────────────────

rem Loop semua file .php dan .json di folder saat ini
for %%F in (*.php *.json) do (
    rem Jika mulai file baru (fileCount == 0), set nama output
    if !fileCount! equ 0 (
        set "outputFile=%outputPrefix%!outputIndex!.txt"
        echo [*] Membuat file: !outputFile!
    )
   
    rem Tulis header, isi file, dan footer ke output
    echo Filename: %%~nxF >>"!outputFile!"
    echo. >>"!outputFile!"
    type "%%F" >>"!outputFile!"
    echo. >>"!outputFile!"
    echo -- end of file: %%~nxF ------- >>"!outputFile!"
    echo. >>"!outputFile!"
     
    echo     [+] %%F ---^> !outputFile! 

    rem Increment counter
    set /a fileCount+=1

    rem Jika sudah mencapai batas, naikkan index dan reset counter
    if !fileCount! geq %maxPerFile% (
        set /a outputIndex+=1
        set /a fileCount=0
        echo.
    )
)


rem Tambahkan file core ke output file terakhir
    echo. >>"!outputFile!"    
    echo. >>"!outputFile!"
    echo         # Menambahkan JS Helper SoundFX ****
echo ████████████████████████████████████████████████ BOF: puri-Main.js  === >>"!outputFile!" ████████████
if exist "%jsMain%" (
    type "%jsMain%" >>"!outputFile!"
)
    echo. >>"!outputFile!"
echo ████████████████████████████████████████████████ BOF: puri-SFX.js  === >>"!outputFile!" ████████████
if exist "%jsSFX%" (
    type "%jsSFX%" >>"!outputFile!"
    echo    [+] JS helper SoundFX ---^> !outputFile!
)

echo         # Menambahkan Plugin Core ****
if exist "%coreFile%" (
    echo. >>"!outputFile!"
    echo. >>"!outputFile!"
    echo ████████████████████████████████████████████████ BOF: puri-centre.php === >>"!outputFile!" ████████████
    type "%coreFile%" >>"!outputFile!"
    echo ──────────────────────────────────────────────── EOF: puri-centre.php --- >>"!outputFile!" ────────────
    echo    [+] Plugin Core ---^> !outputFile!
) else (
    echo [!] ERROR: File "%coreFile%" tidak ditemukan!
)

echo.
echo.
echo --------------
echo FINISH !!!
set /a totalfile=outputIndex-1
echo Total file output: !totalfile! 
echo.
echo File yang dibuat:
for /L %%i in (1,1,!outputIndex!) do (
    if exist %outputPrefix%%%i.txt (
        echo   - %outputPrefix%%%i.txt
    )
)
echo. 
pause
