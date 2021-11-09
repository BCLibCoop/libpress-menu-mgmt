# libpress-menu-mgmt

## Running for single site

Find blogID and URL

```wp site list |grep maple```

```export BLOGID=73```

Run command

```wp libpress-export-blogmenus --blogid=$BLOGID```

Saves backup file in WXR format to path specified in ```MENU_MGMT_EXPORT_DIR```, organized by Blog domain.

Defaults to ```/home/siteuser/libpress_menu_backups/{domain}/``` on LibPress.

## Running network-wide (in cron)

```wp libpress-export-blogmenus --network```

Full cron run, for example:

```cd /var/www/libpress.libraries.coop/current && /usr/local/bin/wp libpress-export-blogmenus --network &>/dev/null && gzip /home/siteuser/libpress_menu_backups/*/*.xml```

## Restoring a menu backup

Normally, without all pages/posts/terms linked to from the menu items, an import would fail and only import custom links. This plugin now includes functionality that dynamically adds referenced posts/terms from the menu into the importer, thus allowing an export with only menu items to be imported.

There are limitations however, insofar as a post/page/term may have been deleted between the export and the import. Efforts have been made to log errors when this occurs, but edge cases may exist where they are missed. Even when these errors are logged, if the missing item has child items, your menu probably doesn't look how it did when exported, so be aware of that.

Importing is a simple matter of

```wp libpress-import-blogmenu /the/path/to/the/exportfile.xml```

The blog URL will be pulled from the export file, so no other parameters are required. In addition, the XML file can also be imported via the normal WP Importer web interface.
