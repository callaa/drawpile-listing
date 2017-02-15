Drawpile Public Listing Server
------------------------------

This is a collection of sample implementations of a Drawpile public listing server.

**Note:** these are now obsolete and unmaintained. The current version is at [drawpile/listserver](https://github.com/drawpile/listserver).

To install, first create the database. Scripts are provided for MySQL/MariaDB and Postgresql.
The PHP based server uses MySQL and the Python based on Postgresql.

To use your server, go to Drawpile's preferences dialog, click on
the *Add* button (in the *List servers* tab) and enter the URL to your listing server. (E.g. `http://example.com/my-listing-server/`)

Note. The list server scripts will never delete anything from the database, so
you should periodically run the `cleanup-database.sql` to keep old listings
from accumulating.

## Installing the PHP based server

Note! The PHP version is currently outdated and not recommended for use.

Copy the contents of `php/` to your server. Remember to copy the `.htaccess` file as well!

Rename or copy `config.php.sample` to `config.php` and modify the the settings
to suit your server environment. You should now have a functioning Drawpile listing server!

## Installing the Python (CherryPy) based server

1. (Optional) Create a virtualenv
2. Install the dependencies with `pip install -r requirements.txt`
3. Copy `settings.py.sample` to `settings.py` and adjust to suit your environment.
4. Configure nginx/apache/other web server to proxy traffic to the application. (See various CherryPy deployment guides)

