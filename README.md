# dyndns2-pdns

A thin wrapper around PowerDNS API to implement a basic DynDNS 2 protocol layer, see [[1](#references)] and [[2](#references)].\
Functionality is extended by the ability to update & delete TXT records making this usable for name validation by letsencrypt, see [[3](#references)] and [[4](#refrences)].


## Installation

* Deploy to any path on your webserver.
* Update `config.inc.php` to match your DB settings.
* Use the scripts in `sql/` to create the database tables.
* Create users in the DB like
  ```sql
  INSERT INTO `users` (`active`,`username`,`password`) VALUES (1,'username','$2y$10$cjaSgipjSg6V/XStI9lx7.LJTo2QcDvxxGhlrnu6uZe8j02xh6Rhm')
  ```
  Note that '$2y$10$cjaSgipjSg6V/XStI9lx7.LJTo2QcDvxxGhlrnu6uZe8j02xh6Rhm' is what you get from `htpasswd -bnBC 10 "" 'password' | tr -d ':'`
* Setup permissions to use DynDNS update like
  ```sql
  INSERT INTO `permissions` (`user_id`,`hostname`) VALUES (1,'web1.mycorp.com');
  INSERT INTO `permissions` (`user_id`,`hostname`) VALUES (1,'.sub.mycorp.com');
  ```
  The user_id needs to be adapted, e.g. using the id of the user we created previously.\
  A hostname value starting with '.' (like .sub.mycorp.com) is a wildcard entry, this means the user my update any record that ends with this value in the zone provided.
  

## Examples

Update IPv4:\
`https://username:password@www.myhost.com/dyn/update.php?hostname=web1.mycorp.com&myip=127.0.0.1`

Set TXT record:\
`https://username:password@www.myhost.com/dyn/update.php?hostname=acme-challenge.db1.sub.mycorp.com&txt=12345678`

Clear (and remove) TXT record:\
`https://username:password@www.myhost.com/dyn/update.php?hostname=acme-challenge.db1.sub.mycorp.com&txt=`


## References

[1] https://help.dyn.com/remote-access-api/perform-update/ \
[2] https://help.dyn.com/remote-access-api/return-codes/ \
[3] https://github.com/BastiG/certbot-dns-webservice \
[4] https://github.com/BastiG/acme-sh-dyndns2
