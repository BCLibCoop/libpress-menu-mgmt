# libpress-menu-mgmt

### Running for single site

Find blogID and URL

``wp site list |grep maple``

``export BLOGID=73 ; export URL=maple.bc.libraries.coop``

Run command

``wp libpress-export-blogmenus --blogid=$BLOGID --url=$URL``

Saves backup file to directory specified in ``MENU_MGMT_EXPORT_DIR``

### Running network-wide (in cron)

``wp libpress-export-blogmenus --network``
