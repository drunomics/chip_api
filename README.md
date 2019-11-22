# Chip BestCheck API

Provides a service to load product prices from BestCheck API.

## API documentation

https://bc-api.bestcheck.de/api/doc

## Installation

Install the module via composer:

    composer require drunomics/chip_api
    
Enable the module as you would a normal drupal module.

Configure HTTP authentication on
    
    /admin/config/services/chip-api/settings

The credentials can also be set in environment variables, if done so they will 
override settings, the environment variables are:

    CHIP_API_BA_USERNAME
    CHIP_API_BA_PASSWORD

## Usage

For an example how to create a request see the test ASIN page

    /admin/config/services/chip-api/test-asin

