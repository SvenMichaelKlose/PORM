PORM tests
==========

This is to test *and* document PORM in one go on the command-line.

# Requirements

docker-compose will make your life much simpler.

# Running the tests

Run docker-compose (native PHP server should suffice):

~~~sh
sudo docker-compose up
~~~

You might need to change the mariadb port number in file
'docker-compose.yaml'.  Try

~~~sh
netstat -antu
~~~

to see which ports are currently in use.  The default ist port
7000.

Then, preferabley in another terminal, simply run the tests like:

~~~sh
php tests.php
~~~
