@echo off
REM ============================================
REM Gabungkan file sesuai group list
REM Mode:
REM   run.bat        -> semua group otomatis
REM   run.bat ASK    -> tanya per group (Y/N)
REM   run.bat CORE   -> hanya group CORE
REM   run.bat HELPER -> hanya group HELPER
REM   run.bat WAREHOUSE -> hanya group WAREHOUSE
REM   run.bat TRANSACTION -> hanya group TRANSACTION
REM   run.bat OTHERS -> hanya group OTHERS
REM ============================================

if "%~1"=="" goto run_all
if /I "%~1"=="ASK" goto ask_core
if /I "%~1"=="CORE" goto exec_core
if /I "%~1"=="HELP" goto exec_helper
if /I "%~1"=="WARE" goto exec_warehouse
if /I "%~1"=="TRANS" goto exec_transaction
if /I "%~1"=="OTH" goto exec_others

REM ---------------------------
REM MODE LANGSUNG JALAN SEMUA
REM ---------------------------
:run_all
echo Mode langsung, semua group digabung otomatis...

call :exec_core
call :exec_helper
call :exec_warehouse
call :exec_transaction
call :exec_others
goto finish

REM ---------------------------
REM MODE TANYA PER GROUP
REM ---------------------------
:ask_core
set /p ans_core=Apakah akan gabung CORE (Y/N)? 
if /I "%ans_core%"=="Y" call :exec_core
goto ask_helper

:ask_helper
set /p ans_helper=Apakah akan gabung HELPER (Y/N)? 
if /I "%ans_helper%"=="Y" call :exec_helper
goto ask_warehouse

:ask_warehouse
set /p ans_wh=Apakah akan gabung WAREHOUSE (Y/N)? 
if /I "%ans_wh%"=="Y" call :exec_warehouse
goto ask_transaction

:ask_transaction
set /p ans_tr=Apakah akan gabung TRANSACTION (Y/N)? 
if /I "%ans_tr%"=="Y" call :exec_transaction
goto ask_others

:ask_others
set /p ans_oth=Apakah akan gabung OTHERS (Y/N)? 
if /I "%ans_oth%"=="Y" call :exec_others
goto finish

REM ---------------------------
REM EXECUTION PER GROUP
REM ---------------------------
:exec_core
echo Preparing: X_CORE.TXT
DEL X_CORE.TXT 2>nul
FOR %%F IN (
    mc-00-admin-hub.php
    mc-01-core.php
    mc-03-engine.php
    mc-03a-ledger.php
    mc-03b-stocker.php
    mc-03c-journalist.php
) DO (
    echo Processing: %%F
    type %%F >> X_CORE.TXT
    type separator.txt >> X_CORE.TXT
)
goto :eof

:exec_helper
echo Preparing: X_HELPER.TXT
DEL X_HELPER.TXT 2>nul
FOR %%F IN (
    mc-17-maintenance.php
    mc-19-crud-partnership.php
    mc-23-coa-manager.php
    mc-28-gl-mapping.php
) DO (
    echo Processing: %%F
    type %%F >> X_HELPER.TXT
    type separator.txt >> X_HELPER.TXT
)
goto :eof

:exec_warehouse
echo Preparing: X_WAREHOUSE.TXT
DEL X_WAREHOUSE.TXT 2>nul
FOR %%F IN (
    mc-04-procurement.php
    mc-40-scripts.php
    mc-40-styles.php
    mc-40-transfer-stock.php
) DO (
    echo Processing: %%F
    type %%F >> X_WAREHOUSE.TXT
    type separator.txt >> X_WAREHOUSE.TXT
)
goto :eof

:exec_transaction
echo Preparing: X_TRANSACTION.TXT
DEL X_TRANSACTION.TXT 2>nul
FOR %%F IN (
    mc-05-allnew-pos.php
    mc-05a-eod-control.php
    mc-05b-stock-recon.php
    mc-05c-stock-opname.php
) DO (
    echo Processing: %%F
    type %%F >> X_TRANSACTION.TXT
    type separator.txt >> X_TRANSACTION.TXT
)
goto :eof

:exec_others
echo Preparing: X_OTHERS.TXT
DEL X_OTHERS.TXT 2>nul
FOR %%F IN (
    mc-00-user-control.php
    mc-02-data-model.php
    mc-06-visitor-cockpit.php
    mc-07-queue-orders.php
    mc-08-stock-transfer.php
    mc-09-consignment.php
    mc-10-expenses.php
    mc-11-journal.php
    mc-12-pl.php
    mc-13-data-io.php
    mc-14-balance.php
    mc-15-invoice.php
    mc-16-monitoring.php
    mc-18-webhook.php
    mc-20-inventory-ledger.php
    mc-21-daily-riyal.php
    mc-22-pivot.php
    mc-24-user-role-manager.php
    mc-25-cockpit-extended-pos.php
    mc-26-stock-adjustment.php
    mc-27-general-journal.php
    mc-29-configuration-base.php
    mc-30-landing-menu.php
) DO (
    echo Processing: %%F
    type %%F >> X_OTHERS.TXT
    type separator.txt >> X_OTHERS.TXT
)
goto :eof

:finish
echo ============================================
echo Semua group selesai diproses!
pause