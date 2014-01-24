podio-backup
============

Creating a "maximum complete" backup of data stored at Podio (including files).

This runs as php commandline script (cli) and is based on: http://www.podiomail.com/blog/easy-podio-full-backup.php (thanks!)

Features include:
-------------------------
- _Incremental backup_ (files are downloaded only once and are linked on subsequent runs).
- Stores item human readable _including comments_ - additionally items are bulk downloaded as excel file.
- All files hosted at Podio are downloaded, files hosted externally are linked and easily accessible in an html app overview.
- Smart handling of rate limits (sleeps before hitting the podio rate limit).

Usage:
----------
    php podio_backup_full_cli.php [-f] [-v] [-s PARAMETER_FILE] --backupTo BACKUP_FOLDER --podioClientId PODIO_CLIENT_ID --podioClientSecret PODIO_CLIENT_SECRET --podioUser PODIO_USERNAME --podioPassword PODIO_PASSWORD
    
    php podio_backup_full_cli.php [-f] [-v] -l PARAMETER_FILE [--backupTo BACKUP_FOLDER] [--podioClientId PODIO_CLIENT_ID] [--podioClientSecret PODIO_CLIENT_SECRET] [--podioUser PODIO_USERNAME] [--podioPassword PODIO_PASSWORD]

    php podio_backup_full_cli.php --help

    Arguments:
       -f	download files from podio (rate limit of 250/h applies!)
       -v	verbose
       -s	store parameters in PARAMETER_FILE
       -l	load parameters from PARAMETER_FILE (parameters can be overwritten by command line parameters)
     
    BACKUP_FOLDER represents a (incremental) backup storage. I.e. consecutive backups only downloads new files.
