#!/bin/bash

# Variables
database=$DATABASE
dbuser=$DB_USER
dbpass=$DB_PASS
dbname='owncloud'
ocpath='/var/www/html/owncloud'
htuser='apache'
htgroup='apache'
rootuser='root'

# Run command line install
sudo -u apache php ${ocpath}/occ maintenance:install --database "mysql" --database-host ${database} --database-name ${dbname} --database-user ${dbuser} --database-pass=${dbpass} --admin-user "css_admin" --admin-pass "jN$43eraA!"

if [ $? -eq 0 ]; then
        # Setup proper permissions
        printf "Creating possible missing Directories\n"
        mkdir -p $ocpath/data
        mkdir -p $ocpath/assets

        printf "chmod Files and Directories\n"
        find ${ocpath}/ -type f -print0 | xargs -0 chmod 0640
        find ${ocpath}/ -type d -print0 | xargs -0 chmod 0750

        printf "chown Directories\n"
        chown -R ${rootuser}:${htgroup} ${ocpath}/
        chown -R ${htuser}:${htgroup} ${ocpath}/apps/
        chown -R ${htuser}:${htgroup} ${ocpath}/config/
        chown -R ${htuser}:${htgroup} ${ocpath}/data/
        chown -R ${htuser}:${htgroup} ${ocpath}/themes/
        chown -R ${htuser}:${htgroup} ${ocpath}/assets/

        chmod +x ${ocpath}/occ

        printf "chmod/chown .htaccess\n"
        if [ -f ${ocpath}/.htaccess ]
         then
          chmod 0644 ${ocpath}/.htaccess
          chown ${rootuser}:${htgroup} ${ocpath}/.htaccess
        fi
        if [ -f ${ocpath}/data/.htaccess ]
         then
          chmod 0644 ${ocpath}/data/.htaccess
          chown ${rootuser}:${htgroup} ${ocpath}/data/.htaccess
        fi
else
    echo "ERROR $?: Could not install Owncloud!"
fi
