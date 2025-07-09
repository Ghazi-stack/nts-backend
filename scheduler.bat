@echo off
cd /d C:\xampp\htdocs\newtownspares
C:\xampp\php\php.exe artisan schedule:run >> scheduler.log 2>&1