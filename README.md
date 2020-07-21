# libpress-menu-mgmt

### Running for single site

Find blogID and URL

``wp site list |grep maple``

``export BLOGID=73``

Run command

``wp libpress-export-blogmenus --blogid=$BLOGID``

Saves backup file in WXR format to path specified in ``MENU_MGMT_EXPORT_DIR``, organized by Blog domain.

Defaults to ``/home/siteuser/libpress_menu_backups/{domain}/`` on LibPress.

### Running network-wide (in cron)

``wp libpress-export-blogmenus --network``

### Restoring a menu backup

@todo