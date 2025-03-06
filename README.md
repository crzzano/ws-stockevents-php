# ws-stockevents-php

This is a PHP library that uses https://github.com/gboudreau/ws-api-php

This is just a quick modification of the usage example found in the above repository to make it easy to export stock **buy and sell** events from Wealthsimple to a CSV following the StockEvents app specified format, which you can import using the StockEvents mobile app.

Nothing special - all it does is authenticate you with Wealthsimple using your one time passcode so you can export stock events (BUY, SELL). 

## Requirements

Make sure all of the stocks you want to track are on your StockEvents watchlist.

Get your Wealthsimple username, password & Wealthsimple account numbers ready.

**This project authenticates with Wealthsimple using one-time passcodes, so make sure you have that enabled. It has not been tested with anything else.**

## Installation

You'll at least need to know how to navigate to different directories using your terminal (command prompt)

- You'll need to have PHP8 or higher, and Composer installed on your machine
  - Composer: https://getcomposer.org/download/
  - To install PHP easily I recommend:
    - On Windows: Laragon (https://laragon.com) 
    - On MacOS: Laravel Herd (https://herd.laravel.com/)
- On your terminal run `php -v` and `composer -v` to ensure they are installed
- Clone or copy this project into a folder on your machine
- Run `composer install` from your terminal, from your project folder
- Copy or rename the `.env.example` file to `.env`, then open it and fill in your Wealthsimple username, password & Wealthsimple account numbers that you want to export stock events from
- Run `php wealthsimple.php` from your terminal, from your project directory

If all goes well you will have a .CSV file you can import using your StockEvents app.

