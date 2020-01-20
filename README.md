# libgen.php
Download books from gen.lib.rus.ec using PHP, RabbitMQ and Heroku. 

### Installation
To install all dependencies, just run:
```
$ composer install
```

### Environment Params
* CLOUDAMQP_URL - REQUIRED
* CLOUDAMQP_APIKEY - REQUIRED
* DROPBOX_ACCESS_TOKEN - REQUIRED
* DROPBOX_APP_KEY - REQUIRED
* DROPBOX_APP_SECRET - REQUIRED

### Install Heroku
```
https://devcenter.heroku.com/articles/heroku-cli#download-and-install
```

### Create APP on Dropbox
```
https://www.dropbox.com/developers/apps
```

### Notes
* This app was designed to be deployed to Heroku with the `CloudAMQP` add-on for RabbitMQ queue support.
* Heroku has a 30 second request timeout and as a result, the process of downloading a book needed to be offloaded to a worker.
* Dropbox support was added in order to simply the process of retrieving the book after the worker processes the download job.
