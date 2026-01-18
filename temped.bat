@echo off
:: Mengecek apakah folder ..\temp sudah ada, jika belum maka dibuat
if not exist "..\temp" mkdir "..\temp"

:: Menyalin file dan mengubah ekstensinya menjadi .txt
:: %~n1 mengambil nama file saja tanpa ekstensi asli
copy "%1" "..\temp\%~n1.txt"

echo File %1 berhasil dikopi ke ..\temp\%~n1.txt
pause
