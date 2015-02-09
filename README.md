Drawpile Public Listing Server
------------------------------

This is a sample implementation of a Drawpile public listing server.
At the moment, just a PHP implementation is provided.

To install, first create the database. The included creation script is for
MySQL/MariaDB, but the the server itself should run just as well on PostgreSQL too.

Copy the contents of `php/` to your server. Remember to copy the `.htaccess` file as well!

Rename or copy `config.php.sample` to `config.php` and modify the the settings
to suit your server environment. You should now have a functioning Drawpile listing server!

To use it, go to Drawpile's preferences dialog, click on
the *Add* button (in the *List servers* tab) and enter the URL to your listing server. (E.g. `http://example.com/my-listing-server/`)

Note. The list server script will never delete anything from the database, so
you should periodically run the `cleanup-database.sql` to keep old listings
from accumulating.
