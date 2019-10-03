Changing Flight Tracker
=======================

Most institutions require some customization of products to meet needs specific to their institution. If you have a PHP developer accessible, then we want to make this happen. This directory allows you to override PHP files in the root directory of Flight Tracker. You simply need to put a file in this directory with the appropriate directory structure to support it. When implemented, Flight Tracker will automatically override the file in the Flight Tracker root directory with yours.

Function files are written in HTML, Markdown, JavaScript (and jQuery) and PHP. Charts are made via PHP/HTML and Chart.js. REDCap (with MySQL as its database) serves as the data engine. REDCap's External Modules distribute the code. CSS stylizes the application.

This feature is **Under Construction** and _not yet implemented_. If you have feedback about the design of this feature (positive or negative), please email <scott.j.pearson@vumc.org>.

## Organization

* **small_base.php** - The base library of PHP functions.
* **CareerDev.php** and **Application.php** - The PHP class which manages the entire application.
* **CareerDevHelp.php** - The PHP class which manages the Help feature.
* **classes/** - The core classes that handle the logic that runs the functioning of the code. _These cannot be overwritten!_
* **drivers/** - The files in this directory are called to run the cron jobs and to perform general maintenance. 
* **help/** - The HTML that organizes the online help. 
* **js/base.js** - The base library of JavaScript functions.
* **css/career_dev.css** - The base CSS styling document.
* **charts/baseWeb.php** - Handles all of the headers for Flight Tracker and sets up the web environment.
* **cronLoad.php** - Manages which crons run on which day. (The config.json file sets these to run at midnight server time.)

## Sharing

We hope to implement a way of sharing these customizations with other institutions (only if you want to). If you have any feedback about this, again, please email <scott.j.pearson@vumc.org>.

